# Image API

A private image library: sign in, upload PNG/JPEG, list, fetch and delete — where
every image belongs to exactly one account and nobody else can see it.

Built on **Laravel 13.26** / **PHP 8.4**, with Sanctum token auth, content-addressed
deduplicated storage, and queued WebP compression.

---

## Contents

- [What it does](#what-it-does)
- [Quick start](#quick-start)
- [API reference](#api-reference)
- [How the hard parts work](#how-the-hard-parts-work)
  - [100k+ uploads a day](#1-100k-uploads-a-day)
  - [Compression without visible quality loss](#2-compression-without-visible-quality-loss)
  - [Never storing the same image twice](#3-never-storing-the-same-image-twice)
- [Security](#security)
- [Tests](#tests)
- [Project layout](#project-layout)

---

## What it does

| Requirement | Where it lives |
| --- | --- |
| Authentication | `POST /api/auth/register`, `POST /api/auth/login` — Sanctum bearer tokens |
| Private upload route | `POST /api/images` — PNG and JPEG only, everything else refused |
| Private list route | `GET /api/images` — cursor paginated, scoped to the caller |
| Private fetch route | `GET /api/images/{id}` (metadata) and `GET /api/images/{id}/content` (bytes) |
| Private delete route | `DELETE /api/images/{id}` |
| A user sees only their own images | Every query goes through `Image::ownedBy($user)`; a foreign id is a 404 |
| Max 5 MB per file | Enforced at four layers, from `Content-Length` down to the decoded header |
| ★ 100k+ uploads/day | Compression runs on a queue; the request path is a hash, a lookup and an insert |
| ★ Compress without quality loss | Queued re-encode to WebP q82 — a 4K JPEG in the local benchmark went 5.4 MB → 515 KB (−91%) |
| ★ No duplicate storage | sha-256 content addressing with reference counting — the same file uploaded a thousand times occupies one blob |

---

## Quick start

### Local (SQLite, no Docker)

```bash
make setup          # composer install, .env, app key, migrate
make serve          # http://localhost:8000
make queue          # in a second terminal: runs the compressor
```

Requires PHP 8.3+ with `gd` (WebP enabled), `exif` and `fileinfo`.

The API works without the queue worker running — uploads are simply served in
their original format and stay `"status": "pending"` until a worker picks them up.

> **php.ini** — `upload_max_filesize` and `post_max_size` must be ≥ 6 MB.
> Below that PHP discards the request body before Laravel can see it. The
> Docker image sets this already.

### Docker (PostgreSQL + Redis + workers)

```bash
make up             # app on :8000, plus worker, scheduler, postgres, redis
make logs
docker compose up -d --scale worker=4    # more compression throughput
```

### A 60-second tour

```bash
BASE=http://localhost:8000/api

# 1. Register and keep the token
TOKEN=$(curl -s -X POST $BASE/auth/register \
  -H 'Accept: application/json' \
  -d name=Ada -d email=ada@example.com \
  -d password=correct-horse-battery-staple \
  -d password_confirmation=correct-horse-battery-staple \
  | php -r 'echo json_decode(stream_get_contents(STDIN))->data->token;')

# 2. Upload
curl -s -X POST $BASE/images \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -F image=@photo.jpg

# 3. List
curl -s $BASE/images -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'

# 4. Download the bytes
curl -s $BASE/images/{id}/content -H "Authorization: Bearer $TOKEN" -o out.webp

# 5. Delete
curl -s -X DELETE $BASE/images/{id} -H "Authorization: Bearer $TOKEN" -i
```

---

## API reference

All routes are prefixed with `/api`. Everything except register and login
requires `Authorization: Bearer <token>`; without it the answer is `401`.

The machine-readable contract is in [`openapi.yaml`](openapi.yaml).

### `POST /auth/register`

```json
{ "name": "Ada", "email": "ada@example.com",
  "password": "…", "password_confirmation": "…", "device_name": "iphone" }
```

`201` → `{ "data": { "user": {…}, "token": "3|xxxx", "token_type": "Bearer" } }`

### `POST /auth/login`

`{ "email": "…", "password": "…" }` → `200` with the same token payload.
Wrong credentials give a `422` that is byte-for-byte identical whether or not
the account exists.

### `POST /auth/logout` · `GET /auth/me`

Logout revokes only the token that made the call, leaving other devices signed in.

### `POST /images` — upload

Multipart form:

| Field | Required | Notes |
| --- | --- | --- |
| `image` | yes | PNG or JPEG, ≤ 5 MB |
| `name` | no | Display name; defaults to the client filename |

- `201` — stored.
- `200` — you had already uploaded these exact bytes; the existing image is
  returned. Uploads are idempotent per (user, content).
- `422` — wrong type, too large, or not a decodable image.
- `413` — the body is far past the limit; refused before it is parsed.
- `429` — over the per-user upload rate limit.

```json
{
  "data": {
    "id": "01K3P8S0RZK7XW2Q9M4V6C1T5B",
    "name": "sunset.jpg",
    "content_url": "http://localhost:8000/api/images/01K3P…/content",
    "status": "ready",
    "format": "webp",
    "mime_type": "image/webp",
    "width": 3840, "height": 2160,
    "bytes": 527_463,
    "original": { "format": "jpg", "mime_type": "image/jpeg", "bytes": 5_617_664 },
    "compression": { "saved_bytes": 5_090_201, "saved_ratio": 0.9061 },
    "checksum": "sha256:9f86d081884c7d65…",
    "created_at": "2026-08-25T10:14:02+00:00",
    "updated_at": "2026-08-25T10:14:03+00:00"
  },
  "meta": { "deduplicated": false, "already_owned": false }
}
```

`status` is `pending` until a worker compresses it, then `ready`. The image is
downloadable throughout; only its `format` and `bytes` change.

### `GET /images` — list

`?per_page=25` (max 100) · `?cursor=…`

```json
{
  "data": [ … ],
  "links": { "first": null, "last": null, "prev": null, "next": "…?cursor=eyJ…" },
  "meta": { "path": "…", "per_page": 25, "next_cursor": "eyJ…", "prev_cursor": null }
}
```

Newest first, and only ever the caller's own images.

### `GET /images/{id}` — metadata · `GET /images/{id}/content` — bytes

`content` streams the file with `Content-Type` of the stored format,
`Cache-Control: private, immutable` and a strong `ETag` (the content digest), so
a repeat fetch costs a `304`. Add `?download=1` for
`Content-Disposition: attachment`.

On S3, set `IMAGES_USE_TEMPORARY_URLS=true` and the endpoint answers `302` with a
short-lived signed URL instead of proxying the bytes through PHP.

### `DELETE /images/{id}`

`204`. Removes *your* copy. The stored bytes are erased once the last owner has
let go of them — see [deduplication](#3-never-storing-the-same-image-twice).

Someone else's id behaves exactly like a nonexistent one: `404 {"message": "Resource not found."}`.

---

## How the hard parts work

Two tables carry the whole design:

```
users ──< images >── image_blobs
             │            │
   "Ada owns this,        "these exact bytes, stored once,
    calls it sunset.jpg"   referenced by N users"
```

`images` is per-user and cheap. `image_blobs` is per-unique-content and owns the
file on disk. Everything below follows from that split.

### 1. 100k+ uploads a day

100k/day is ~1.2 uploads/second on average, but real traffic is bursty — an
evening peak of 20–50/s is entirely normal. The design targets the burst.

**The request does almost nothing.** Hash the temp file, one indexed lookup, one
insert, respond. Compression — the only expensive step, 576 ms for a 4K photo in
the local benchmark — is dispatched to a queue:

```php
$hash = hash_file('sha256', $file->getRealPath());   // streamed, ~5 ms for 5 MB
[$blob, $isNew] = $this->resolveBlob($file, $hash);  // indexed lookup + insert
$image = Image::firstOrCreate([...]);                // the user's claim
$isNew && OptimizeImageBlob::dispatch($blob->id);    // the slow part, later
```

Holding compression in the request would cap a single PHP worker at under 2
uploads/second. Off the request, upload throughput is bounded by I/O, and
compression capacity scales independently: `docker compose up -d --scale worker=8`.

**Nothing is ever loaded into memory whole.** Hashing streams off disk, storage
writes take the upload stream, and downloads use `readStream` + `fpassthru`. A
5 MB upload never becomes a 5 MB PHP string.

**Contention is designed out.** Concurrent uploads of the same content are
arbitrated by a unique index (`insertOrIgnore`), not a lock — the write target is
derived from the content itself, so two racing writers produce identical bytes at
an identical path. `SELECT … FOR UPDATE` appears exactly once, in the pruner,
where correctness genuinely needs it.

**Listing stays flat.** `cursorPaginate` walks the `(user_id, created_at, id)`
index by keyset, so page 10,000 costs what page 1 costs; `OFFSET` would degrade
linearly and can skip or repeat rows while uploads keep arriving.

**Storage fans out.** Blobs live at `images/blobs/ab/cd/<hash>.webp`. A flat
directory would hold 36M entries within a year of 100k/day; two hex levels spread
that over 65,536 directories.

**Everything is horizontal.** Bearer tokens mean no session affinity; point
`IMAGES_DISK` at S3 and app servers become interchangeable. Rate limits (240
uploads/min per user) cap any single account without touching the aggregate.

### 2. Compression without visible quality loss

Every upload is re-encoded to **WebP at quality 82**, which for photographic
content is where the size curve falls off a cliff long before the visible
artefacts start. Resolution is preserved by default — the task asks for no
significant quality loss, and downscaling is the one lossy step a user would
actually notice. (`IMAGES_MAX_DIMENSION` enables a cap if you want one.)

Measured locally on this machine:

| Source | Result |
| --- | --- |
| 3840×2160 JPEG, 5.4 MB | 515 KB WebP — **−91%**, 576 ms |
| 355×200 JPEG, 20 KB | 5.2 KB WebP — **−74%**, 7 ms |

Three details that matter more than the encoder choice:

- **EXIF is stripped**, after the orientation is baked into the pixels. Smaller
  files, and no GPS coordinates riding along inside a shared photo.
- **The original wins if it is smaller.** Already-optimised JPEGs and tiny flat
  PNGs sometimes grow when re-encoded; when that happens the source bytes are
  kept and the format stays as uploaded. Compression that makes files bigger is
  not compression.
- **A failed re-encode is not a failed upload.** The original is stored first and
  served throughout; the blob is marked `failed` after retries and the user never
  notices. The compressor is an optimisation, never a dependency.

`IMAGES_FORMAT=avif` switches to AVIF (smaller again, several times slower to
encode) with no other change.

### 3. Never storing the same image twice

The deduplication key is the **sha-256 of the uploaded bytes**, computed before
anything else happens. It is also the storage path, so identity and location are
the same fact.

```
Ada uploads cat.png    ─┐
Bob uploads cat.png    ─┼─→  one image_blob (reference_count: 3)
Bob uploads it again   ─┘    one file on disk
```

- **Second upload of known bytes writes nothing.** No disk write, no compression
  job, no duplicate file — just a new row pointing at the existing blob.
- **The same user re-uploading gets their existing image back** (`200` with
  `"already_owned": true`), instead of a second identical entry in their library.
- **Deleting is reference counted.** `DELETE` drops the user's row and decrements
  the count in one transaction, then queues a pruner. The pruner locks the blob,
  re-checks that the count is zero *and* that no rows reference it, and only then
  erases the file. Ada deleting her copy cannot take Bob's image away.
- **Lost jobs cannot leak disk.** `images:prune` runs hourly and sweeps anything
  that has been unreferenced for an hour — a queue flush or a killed worker
  cannot strand bytes forever.

Because the hash covers the *original* bytes, deduplication works before the
compressor has run, which is what keeps the fast path fast.

---

## Security

| Concern | Handling |
| --- | --- |
| Cross-account access | Every lookup is `Image::ownedBy($user)->findOrFail()`; a foreign id 404s exactly like a missing one, so the API never confirms that another user's image exists |
| Type spoofing | Four independent checks: client extension, extension implied by the sniffed mime, the sniffed mime itself, and `getimagesize()` on the decoded header. A PHP payload named `.png` with `Content-Type: image/png` is rejected |
| Decompression bombs | Pixel-count and side-length caps applied *before* decoding — a 20 KB PNG that expands to gigabytes never reaches the decoder |
| Oversized bodies | `Content-Length` is checked before parsing (`413`), Laravel validates the file at 5 MB (`422`), and php.ini backstops both |
| Serving user content | `X-Content-Type-Options: nosniff`, a locked-down CSP, and a sanitised `Content-Disposition` filename, so an upload can never be reinterpreted as markup |
| Credential stuffing | 20 login attempts/min per IP and 5 per account, and the password hash is always verified — against a throwaway hash for unknown accounts — so timing cannot enumerate users |
| Token handling | Sanctum personal access tokens, hashed at rest; logout revokes only the current one; expired tokens pruned daily |
| Private files | Stored outside the web root under `storage/app/private`; the only way to the bytes is an authorised route |

---

## Tests

```bash
make test        # 49 tests, 215 assertions
```

Nothing is mocked away from the interesting parts: the suite encodes real PNGs
and JPEGs, pushes them through the HTTP layer, and asserts against the bytes that
come back out.

| Suite | Covers |
| --- | --- |
| `AuthenticationTest` | Registration, login, token lifetime, per-token logout, no user enumeration, every private route rejecting anonymous callers |
| `ImageUploadTest` | Happy paths, GIF/SVG/text refused, a text file disguised as a PNG refused, 5 MB limit, `413` on a huge body, dedup within and across users, queueing behaviour |
| `ImageOptimizationTest` | Real WebP re-encode shrinks the file, dimensions survive, the original is kept when re-encoding would grow it, the original file is cleaned up, a failed job leaves the image servable |
| `ImageListingTest` | Only own images, newest first, cursor pagination, page-size cap |
| `ImageRetrievalTest` | Metadata, real bytes with correct headers, `304` on `If-None-Match`, `private` caching, a foreign image being indistinguishable from a missing one |
| `ImageDeletionTest` | Deletion removes the file, a shared blob survives until its last owner leaves, deleting someone else's image is impossible, the sweeper's rules |

---

## Project layout

```
app/
├── Console/Commands/PruneOrphanBlobs.php   images:prune — safety net for lost jobs
├── Enums/BlobStatus.php
├── Http/
│   ├── Controllers/Api/{AuthController,ImageController}.php
│   ├── Middleware/RejectOversizedUpload.php   413 before PHP eats the body
│   ├── Requests/{Register,Login,StoreImage}Request.php
│   └── Resources/ImageResource.php
├── Jobs/
│   ├── OptimizeImageBlob.php               compression, off the request
│   └── PruneImageBlob.php                  reference-counted deletion
├── Models/{User,Image,ImageBlob}.php
├── Rules/SafeRasterImage.php               real decode + bomb guards
└── Services/Images/
    ├── BlobPathResolver.php                content-addressed, fanned-out paths
    ├── ImageIngestor.php                   the upload fast path
    ├── ImageOptimizer.php                  WebP re-encode with a fallback
    └── ImageDelivery.php                   streaming, ETags, signed URLs
config/images.php                           every knob, documented
routes/api.php
```

## Configuration

`config/images.php` is the single place where behaviour is tuned; each option is
overridable from `.env` (see `.env.example`). The ones worth knowing:

| Variable | Default | Meaning |
| --- | --- | --- |
| `IMAGES_DISK` | `local` | Any Laravel disk — set to `s3` for object storage |
| `IMAGES_MAX_UPLOAD_KB` | `5120` | The 5 MB ceiling |
| `IMAGES_FORMAT` / `IMAGES_QUALITY` | `webp` / `82` | Compression target |
| `IMAGES_MAX_DIMENSION` | *(unset)* | Optional longest-side cap |
| `IMAGES_MAX_PIXELS` / `IMAGES_MAX_SIDE` | `50M` / `20000` | Decompression-bomb guards |
| `IMAGES_USE_TEMPORARY_URLS` | `false` | Redirect to signed S3 URLs instead of streaming |
| `IMAGES_RATE_UPLOADS` | `240` | Uploads per minute per user |
