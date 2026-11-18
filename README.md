# Local Business Directory Platform

An independent local business discovery, lead generation, and merchant management platform built with a cleanroom architecture.

## Approved Technology Stack

* **Frontend (`frontend/`):** Single Next.js (App Router) + React 18 + TypeScript + Tailwind CSS application with isolated `(customer)` and `(business)` route groups *(planned in Milestone 2)*.
* **Backend API (`backend/`):** Modular **Laravel 13 REST API** (`/api/v1/*`) with Laravel Sanctum Bearer token authentication.
* **Admin Dashboard (`backend/`):** Custom **Laravel Blade + Tailwind CSS** admin panel (`/admin/*`) — **no Filament**.
* **Data Layer:** **MySQL 8.0** (`utf8mb4_0900_ai_ci`, `SPATIAL SRID 4326`, `FULLTEXT`) + **Redis 7** (caching, queues, rate limiting, autocomplete).
* **Mobile Compatibility:** Stateless versioned REST APIs (`/api/v1/*`) engineered for future **Flutter** iOS & Android apps.

## Repository Layout

```text
local-biz-directory/
├── backend/              # Laravel 13 REST API + Custom Blade Admin
├── frontend/             # Next.js App Router (Customer + Business Owner) [Upcoming]
├── docker/mysql/         # MySQL 8.0 configuration (UTF-8 English/Tamil, FT min token = 2)
├── docker-compose.yml    # Local MySQL 8.0, Redis 7, MinIO (S3), Mailpit
└── docs/                 # Phase 1 Discovery, Phase 2 Architecture & Progress Tracker
```

## Documentation

* Phase 1 Discovery: `docs/01-homepage-analysis.md` through `docs/06-feature-inventory.md`
* Phase 2 Architecture & Planning: `docs/07-system-architecture.md` through `docs/14-development-roadmap.md`
* Implementation Progress: `docs/15-implementation-progress.md`

## Local Infrastructure (MySQL 8.0 + Redis 7 + MinIO)

```bash
docker compose up -d
```

## Backend Foundation Quickstart

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan test
```
