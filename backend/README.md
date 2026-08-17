# EMPOWER Backend Bootstrap Notes

## Purpose
This folder contains executable bootstrap assets for Laravel 12 database setup and API testing.

## Included
1. Migration wrapper:
- database/migrations/2026_08_04_000000_create_empower_schema.php

2. SQL schema source:
- database/schema/empower_schema_mysql.sql

3. Seeder wrapper:
- database/seeders/EmpowerReferenceSeeder.php
- database/seeders/DatabaseSeeder.php

4. SQL seed source:
- database/seeders/sql/seed-data.sql

5. API testing collection:
- postman/EMPOWER.postman_collection.json

## How to Use in a Laravel 12 Project
1. Place this folder structure inside your Laravel project root.
2. Configure .env database connection.
3. Run migration and seeding:

```bash
php artisan migrate
php artisan db:seed
```

4. Import Postman collection and set variables:
- baseUrl
- token

## Important Notes
1. Replace placeholder password hashes in seed SQL before production use.
2. This setup intentionally excludes payroll functionality.
3. Matching evaluation remains advisory; deployment requires explicit HR action.
4. Keep audit logging enabled for all high-impact endpoint actions.
