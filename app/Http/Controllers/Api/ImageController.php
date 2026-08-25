<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImageRequest;
use App\Http\Resources\ImageResource;
use App\Models\Image;
use App\Services\Images\ImageDelivery;
use App\Services\Images\ImageIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every route here is behind auth:sanctum and every lookup is scoped to the
 * authenticated user, so one account can never observe another's images -- not
 * even their existence, since a foreign id 404s exactly like a missing one.
 */
class ImageController extends Controller
{
    /**
     * GET /api/images
     *
     * Cursor paginated: a keyset walk over (user_id, created_at, id) costs the
     * same on page 1 and page 10,000, where OFFSET would degrade linearly, and
     * it cannot skip or repeat rows while new uploads arrive mid-listing.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            max($request->integer('per_page', (int) config('images.per_page')), 1),
            (int) config('images.max_per_page'),
        );

        $images = Image::query()
            ->ownedBy($request->user())
            ->with('blob')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage)
            ->withQueryString();

        return ImageResource::collection($images);
    }

    /**
     * POST /api/images
     *
     * Returns 201 for a new image, or 200 when this user had already uploaded
     * the exact same bytes -- the upload is idempotent per (user, content).
     */
    public function store(StoreImageRequest $request, ImageIngestor $ingestor): JsonResponse
    {
        $result = $ingestor->ingest(
            $request->user(),
            $request->file('image'),
            $request->string('name')->toString() ?: null,
        );

        return ImageResource::make($result->image)
            ->additional(['meta' => [
                'deduplicated' => ! $result->storedNewBytes,
                'already_owned' => ! $result->createdRecord,
            ]])
            ->response()
            ->setStatusCode($result->statusCode());
    }

    /** GET /api/images/{image} -- metadata only. */
    public function show(Request $request, string $image): ImageResource
    {
        return ImageResource::make($this->find($request, $image));
    }

    /** GET /api/images/{image}/content -- the bytes themselves. */
    public function content(Request $request, string $image, ImageDelivery $delivery): Response
    {
        return $delivery->respond($request, $this->find($request, $image));
    }

    /**
     * DELETE /api/images/{image}
     *
     * Removes this user's copy. The underlying bytes only leave the disk once
     * the last owner has let go of them.
     */
    public function destroy(Request $request, string $image, ImageIngestor $ingestor): Response
    {
        $ingestor->forget($this->find($request, $image));

        return response()->noContent();
    }

    private function find(Request $request, string $id): Image
    {
        return Image::query()
            ->ownedBy($request->user())
            ->with('blob')
            ->findOrFail($id);
    }
}
