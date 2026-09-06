# WC-Info API v2

Modern Laravel 11 rewrite of the legacy Lumen-based WC-Info REST API.

The new v2 application lives in `src/` and is deployed to `httpdocs/`

## Requirements

- PHP 8.4+
- MySQL / MariaDB
- Composer
- Imagick extension (for HEIC photo uploads)

## Local development

### 1. Start the database

Put an `.sql` or `.sql.gz` dump into `_dev_db_server/import`, then run:

```bash
cd _dev_db_server
podman compose up
```

PHPMyAdmin is available at `http://localhost:8081`.

### 2. Configure the application

```bash
cd api2
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set at least:

- `DB_*` connection details
- `S3_*` credentials
- `GOOGLE_API_KEY`
- `SMTP_*` credentials

### 3. Install dependencies

```bash
php composer.phar install
```

or, if you have Composer installed globally:

```bash
composer install
```

### 4. Run the development server

```bash
php artisan serve
```

The API is now available at `http://localhost:8000`.

## API documentation (OpenAPI)

Scramble automatically generates the OpenAPI spec from the code.

- Stoplight Elements UI: `https://api.wc-info.org/docs/api`
- Swagger UI: `https://api.wc-info.org/docs/api/swagger`
- Spec: `https://api.wc-info.org/docs/api.json`

## Database / migrations

v2 reuses the existing MySQL/MariaDB database from the legacy app. Laravel migrations are intentionally not used, because the schema predates Laravel and contains DDL that cannot be rolled back in MySQL/MariaDB.

Schema and data changes required for v2 are applied by a custom command:

```bash
php artisan app:migrate-v2-schema
```

Preview changes without applying them:

```bash
php artisan app:migrate-v2-schema --dry-run
```

Run the command against the production database once before the first v2 deploy.

## Tests

```bash
cd api2
php vendor/bin/phpunit
```

## Cron jobs / scheduler

The v2 application uses the Laravel Scheduler. On the web host, add a single cron entry:

```cron
* * * * * cd /path/to/httpdocs2 && php artisan schedule:run >> /dev/null 2>&1
```

This runs the following commands according to their schedule in `routes/console.php`:

- `app:notify-new-toilets` — daily email with newly added toilets
- `app:notify-updated-toilets` — daily email with updated toilets
- `app:discover-places` — daily Google Places discovery run around a random active toilet without recent place data

Configure discovery behaviour with these `.env` values:

- `DISCOVER_PLACES_LIMIT` — max Google results to process per run (default 500)
- `DISCOVER_PLACES_RADIUS` — search radius in meters (default 2000)
- `DISCOVER_PLACES_CACHE_DAYS` — how recently a place must have been updated to be skipped (default 30)

## Deployment

Deployment is triggered automatically on pushes to `master` via `.github/workflows/deploy-production.yml`.

The workflow has two jobs:

1. **DeployLegacy** — syncs `src/` to `httpdocs/` and runs `composer install` for the legacy app.
2. **DeployV2** — syncs `api2/` to `httpdocs2/` and runs `composer install` for the v2 app.

Make sure the production `.env` is placed on the host inside `httpdocs2/` and is **not** committed to Git.

## Project structure

```
api2/
├── app/
│   ├── Console/Commands/    # Scheduler commands
│   ├── Http/
│   │   ├── Controllers/Api/ # API controllers
│   │   ├── Middleware/      # CORS + request logging
│   │   ├── Requests/        # Form request validation
│   │   └── Resources/       # API response shaping
│   ├── Models/              # Eloquent models for the legacy schema
│   ├── Services/            # S3, Mail, Google Places, OpeningHours, AdminHash
│   └── Exceptions/
├── config/
│   └── wcinfo.php           # App-specific configuration
├── routes/
│   ├── web.php              # API routes (top-level, no /api prefix)
│   └── console.php          # Scheduler definitions
└── tests/
    ├── Unit/                # Service unit tests
    └── Feature/             # HTTP feature tests
```

## Notes

- v2 uses a transformed view of the existing database; run `app:migrate-v2-schema` once before deploying.
- Opening hours now come from Google Places `periods` data.
- Admin qualify/delete links still use the legacy hardcoded MD5 hash.
- Coordinates are stored as decimal degrees (`lat`/`lon` floats) instead of the legacy ×10000 integers.
