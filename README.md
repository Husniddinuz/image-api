# Image API

API для хранения картинок. Регистрация, загрузка PNG/JPEG, список, скачивание,
удаление. Пользователь видит только свои файлы.

Laravel 13, PHP 8.4, токены Sanctum.

## Запуск

Локально, на SQLite:

```bash
make setup      # composer install, .env, ключ, миграции
make serve      # http://localhost:8000
make queue      # во втором терминале, сжимает загруженные картинки
```

Нужен PHP 8.3+ с расширениями gd (со сборкой webp), exif и fileinfo.

Через Docker (postgres, redis, воркер, планировщик):

```bash
make up
make logs
```

Без воркера API работает, картинки остаются в исходном формате со статусом
pending.

Swagger UI: http://localhost:8000/docs. Схема лежит в openapi.yaml и отдаётся
тем же приложением.

`make doctor` проверяет окружение: параметры php.ini, кодировщики, диск и
очередь.

## Маршруты

Префикс /api. Везде, кроме регистрации и входа, нужен заголовок
`Authorization: Bearer <token>`, иначе 401.

| Метод | Путь | Описание |
| --- | --- | --- |
| POST | `/auth/register` | Регистрация, возвращает токен |
| POST | `/auth/login` | Вход, возвращает токен |
| POST | `/auth/logout` | Отзывает текущий токен |
| GET | `/auth/me` | Текущий пользователь |
| POST | `/images` | Загрузка, поле `image`, PNG или JPEG до 5 МБ |
| GET | `/images` | Список, `?per_page=25&cursor=...` |
| GET | `/images/{id}` | Метаданные |
| GET | `/images/{id}/content` | Файл, `?download=1` для скачивания |
| DELETE | `/images/{id}` | Удаление |

Коды на загрузке: 201 если сохранили, 200 если такие байты уже загружались этим
пользователем, 422 на неверный тип или размер, 413 если тело сильно больше
лимита, 429 при превышении лимита запросов.

Чужой id отдаёт 404, как несуществующий.

Пример:

```bash
BASE=http://localhost:8000/api

TOKEN=$(curl -s -X POST $BASE/auth/register \
  -H 'Accept: application/json' \
  -d name=Ada -d email=ada@example.com \
  -d password=correct-horse-battery-staple \
  -d password_confirmation=correct-horse-battery-staple \
  | php -r 'echo json_decode(stream_get_contents(STDIN))->data->token;')

curl -s -X POST $BASE/images -H "Authorization: Bearer $TOKEN" -F image=@photo.jpg
curl -s $BASE/images -H "Authorization: Bearer $TOKEN"
curl -s $BASE/images/{id}/content -H "Authorization: Bearer $TOKEN" -o out.webp
curl -s -X DELETE $BASE/images/{id} -H "Authorization: Bearer $TOKEN" -i
```

Ответ на загрузку:

```json
{
  "data": {
    "id": "01K3P8S0RZK7XW2Q9M4V6C1T5B",
    "name": "sunset.jpg",
    "content_url": "http://localhost:8000/api/images/01K3P.../content",
    "status": "ready",
    "format": "webp",
    "mime_type": "image/webp",
    "width": 3840,
    "height": 2160,
    "bytes": 527463,
    "original": { "format": "jpg", "mime_type": "image/jpeg", "bytes": 5617664 },
    "compression": { "saved_bytes": 5090201, "saved_ratio": 0.9061 },
    "checksum": "sha256:9f86d081884c7d65...",
    "created_at": "2026-08-25T10:14:02+00:00",
    "updated_at": "2026-08-25T10:14:03+00:00"
  },
  "meta": { "deduplicated": false, "already_owned": false }
}
```

status меняется на ready, когда воркер пережмёт картинку. Скачать её можно и до
этого.

## Тесты

```bash
make test
```

70 тестов, 278 проверок. Тесты генерируют настоящие PNG и JPEG и гоняют их через
HTTP.

Покрыто: регистрация и вход, отзыв токена, отсутствие утечки существующих email,
загрузка и отказы по типу и размеру, дедупликация внутри и между пользователями,
перекодирование в WebP, пагинация, заголовки и 304 при отдаче, удаление с общим
блобом, документация.

## Структура

```
app/
├── Console/Commands/     images:doctor, images:prune
├── Http/
│   ├── Controllers/Api/  AuthController, ImageController
│   ├── Middleware/       проверка размера тела и JSON
│   ├── Requests/
│   └── Resources/
├── Jobs/                 OptimizeImageBlob, PruneImageBlob
├── Models/               User, Image, ImageBlob
├── Rules/                SafeRasterImage
└── Services/Images/      ingestor, optimizer, delivery, пути к блобам
config/images.php
config/docs.php
openapi.yaml
routes/api.php
```

## Настройки

В config/images.php, переопределяются через .env:

| Переменная | По умолчанию | Что делает |
| --- | --- | --- |
| `IMAGES_DISK` | `local` | Диск Laravel, для S3 поставить `s3` |
| `IMAGES_MAX_UPLOAD_KB` | `5120` | Максимальный размер файла |
| `IMAGES_FORMAT` | `webp` | Формат сжатия, можно `avif` |
| `IMAGES_QUALITY` | `82` | Качество |
| `IMAGES_MAX_DIMENSION` | не задано | Ограничение длинной стороны |
| `IMAGES_MAX_PIXELS` | `50M` | Лимит пикселей при декодировании |
| `IMAGES_MAX_SIDE` | `20000` | Лимит стороны при декодировании |
| `IMAGES_USE_TEMPORARY_URLS` | `false` | Отдавать подписанные ссылки S3 |
| `IMAGES_RATE_UPLOADS` | `240` | Загрузок в минуту на пользователя |
| `API_DOCS_ENABLED` | `true` | Включение /docs |
| `API_DOCS_PATH` | `docs` | Путь к документации |
