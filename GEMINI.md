# WC-Info API (v2) — Development & Architecture Guide

## Overview

**WC-Info API v2** is a modern Laravel 11 REST API replacing the legacy Lumen-based WC-Info backend.
The Laravel application resides in `src/` and serves `https://api.wc-info.de` directly at the root (no `/api` route prefix).

---

## Key Architectural Principles & Conventions

- **Framework**: Laravel 11 with PHP 8.4+ (strict types, modern syntax).
- **Application Directory**: All Laravel application code is located in `src/`. Run all `php artisan` and `composer` commands from `src/`.
- **Database Schema**: Reuses and extends the existing MySQL / MariaDB database.
  - Standard Laravel migrations are **not** used because the legacy schema predates Laravel and contains DDL that cannot be rolled back cleanly.
  - Schema updates and migrations are handled via the custom command: `php artisan app:migrate-v2-schema` (supports `--dry-run`).
- **Coordinate Handling**: Coordinates are stored and returned as **decimal degree floats** (`lat`, `lon`, e.g. `52.5200`), replacing the legacy integer scaling (`* 10000`).
- **Opening Hours**: Handled in Google Places API (New) `periods` format (array of `{open: {day, hour, minute}, close: {day, hour, minute}}`). Active status and timestamps (`is_open`, `open_timestamp`, `close_timestamp`) are dynamically evaluated by `OpeningHoursService`.
- **API Documentation**: Generated automatically using Dedoc Scramble (`/docs/api`, `/docs/api/swagger`, `/docs/api.json`).

---

## Directory Structure

```
wc-info-org-api/
├── _dev_db_server/          # Local MariaDB + PHPMyAdmin (Podman/Docker compose)
├── .github/workflows/       # CI/CD deployment workflows
├── README.md                # Project README
├── GEMINI.md                # Agent instructions and knowledge base
└── src/                     # Laravel 11 Application Root
    ├── app/
    │   ├── Console/Commands/ # Scheduler & maintenance artisan commands
    │   ├── Exceptions/      # Custom error handling
    │   ├── Http/
    │   │   ├── Controllers/ # API controllers (Toilet, Place, Upload, Sitemap, Health)
    │   │   ├── Middleware/  # CorsMiddleware, RequestLogMiddleware
    │   │   ├── Requests/    # Form request validation
    │   │   └── Resources/   # API JSON resource transformers
    │   ├── Models/          # Eloquent models mapped to legacy DB
    │   └── Services/        # Business logic & external service integrations
    ├── config/              # Laravel configuration + wcinfo.php
    ├── routes/
    │   ├── web.php          # API route definitions
    │   └── console.php      # Scheduler cron schedule
    ├── tests/
    │   ├── Unit/            # Unit tests for services and helpers
    │   └── Feature/         # HTTP API endpoint tests
    └── storage/             # Cache, logs, and framework storage
```

---

## Core Models & Database

- **`Toilet`** (`app/Models/Toilet.php`): Represents a public toilet entity.
  - Fields: `id`, `status` (`active`, `hidden`, `deleted`), `is_qualified`, `lat`, `lon`, `place_id`, `address`, `comment`, `website`, `is_unisex`, `is_gender_separated`, `has_wheelchair_access`, `has_changing_table`, `accessible_outside_opening_times`, `public_accessible`, `storage_space`, `euro_key`.
  - Relationships: `properties()` (`ToiletProperty`), `photos()` (`ToiletPhoto`), `place()` (`Place`).
  - Protection rule: Fields manually edited by users (`POST /toilet/add`, `PATCH /toilet/{id}/update`, `POST /toilet/add-properties`) are protected against automated overwrite by background discovery.
- **`Place`** (`app/Models/Place.php`): Authoritative store for Google Places API (New) cached place metadata.
- **`ToiletPhoto`** (`app/Models/ToiletPhoto.php`): S3-stored photo metadata supporting soft and permanent deletions.
- **`ToiletProperty`** (`app/Models/ToiletProperty.php`): Key-value properties associated with toilets.

---

## Core Services

- **`GooglePlacesService`** (`app/Services/GooglePlacesService.php`):
  - Integrates with Google Places API (New REST v1).
  - Handles Nearby Search fallback when no toilets exist locally in bounds/nearby queries.
- **`OpeningHoursService`** (`app/Services/OpeningHoursService.php`):
  - Parses opening hours `periods` and computes `is_open`, `open_timestamp`, and `close_timestamp`.
- **`S3PhotoStorageService`** (`app/Services/S3PhotoStorageService.php`):
  - Manages image uploads, HEIC conversions (via Imagick), thumbnail generation, and soft deletion (`_DELETED_` prefix on S3).
- **`AdminLinkService`** (`app/Services/AdminLinkService.php`):
  - Generates qualification and deletion tokens using legacy MD5 hash compatibility.
- **`MailService`** (`app/Services/MailService.php`):
  - Handles notification emails for new and updated toilets.

---

## API Routes & Endpoints

All endpoints are registered in `src/routes/web.php` without an `/api` prefix:

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/toilets/nearby/{lat}/{lon}` | Find toilets near coordinates (`?distance=40&filter=...`) |
| `GET` | `/toilets/bounds/{south}/{west}/{north}/{east}` | Find toilets in geographic bounding box |
| `GET` | `/toilets/place/{placeId}` | Toilets associated with a Google Place ID |
| `GET` | `/toilet/{id}` | Detailed toilet info |
| `PATCH` | `/toilet/{id}/update` | Update toilet details |
| `POST` | `/toilet/add` | Create new toilet manually |
| `POST` | `/toilet/add-properties/{toiletId}` | Add key-value toilet properties |
| `GET` | `/places/{placeId}` | Get cached Google Place details |
| `POST` | `/places/{placeId}` | Store/update Google Place details |
| `POST` | `/upload` | Upload toilet photo |
| `POST` | `/uploadSubmit/{toiletId}` | Finalize photo uploads & activate toilet |
| `DELETE` | `/deletePhoto/{toiletId}/{filename}` | Soft (`soft=true`) or hard delete photo |
| `GET` | `/sitemap` | XML sitemap |
| `GET` | `/health` | Service health check |
| `GET` | `/admin/login` | Admin login page |
| `POST` | `/admin/login` | Admin authentication |
| `POST` | `/admin/logout` | Admin logout |
| `GET` | `/admin` | Admin dashboard (lists toilets added in last 24h & search) |
| `GET` | `/admin/toilets/{id}` | Admin toilet view/edit panel |
| `POST` | `/admin/toilets/{id}` | Admin toilet update |
| `POST` | `/admin/toilets/{id}/reschedule-discovery` | Reschedule discovery job (sets last_included to NOW()) |
| `POST` | `/admin/toilets/{id}/photos/{filename}/delete` | Delete photo (soft or hard) |

---

## Artisan Commands & Scheduled Tasks

Configured in `src/routes/console.php`:

- `php artisan app:migrate-v2-schema [--dry-run]`: Applies required database schema changes for v2.
- `php artisan app:discover-places`: Daily crawler discovering/refreshing Google Places metadata for active toilets (prioritizing toilets with `last_included > last_discovered`).
- `php artisan app:notify-new-toilets`: Daily notification email with newly added toilets.
- `php artisan app:notify-updated-toilets`: Daily notification email with updated toilets and deleted photos.
- `php artisan app:convert-places-v2`: Converts legacy place cache records to Places API (New) format.
- `php artisan app:repair-toilet-coordinates`: Fixes legacy scaled coordinates.

---

## Development Workflows

### Setup & Local Server
```bash
# 1. Start database
cd _dev_db_server
podman compose up  # or docker compose up

# 2. Setup application
cd ../src
cp .env.example .env
php artisan key:generate
composer install

# 3. Apply schema updates
php artisan app:migrate-v2-schema

# 4. Start local development server
php artisan serve
```

### Running Tests
```bash
cd src
php vendor/bin/phpunit
```

---

## Coding Guidelines for Agents

- **Always work inside `src/`**: Run all Artisan, Composer, and PHPUnit commands from `src/`.
- **Database Modesty**: Do not generate standard Laravel migrations (`make:migration`) for legacy schema tables unless explicitly instructed; use/update `MigrateV2SchemaCommand.php`.
- **Typing & Return Types**: Enforce strict typing (`declare(strict_types=1);`), typed properties, and explicit return types on methods and controller actions.
- **Form Requests & Resources**: Keep controllers thin by using `FormRequest` classes for validation and `JsonResource` classes for response shaping.
- **Coordinate Integrity**: Always treat coordinates as standard floating-point numbers in decimal degrees (e.g. `52.5200`), never integer-scaled.
