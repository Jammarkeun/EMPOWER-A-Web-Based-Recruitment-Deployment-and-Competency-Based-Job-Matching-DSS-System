# EMPOWER Capstone Artifact Index

## Generated Artifacts
1. Database Schema:
- docs/01-database/empower_schema_mysql.sql

2. Use Case Narratives:
- docs/02-analysis/use-case-narratives.md

3. Screen and Wireframe Specification:
- docs/03-ui/screen-wireframe-spec.md

4. REST API Contract:
- docs/04-api/rest-api-contract.md

5. Seed Data Package:
- docs/05-seeding/seed-data.sql

6. Laravel Migration Blueprint:
- docs/06-laravel/migration-blueprint.md

7. Project Management (client-facing):
- docs/07-project-management/implementation-timeline.md
- docs/07-project-management/client-responsibilities.md

8. Backend Executable Assets:
- backend/database/migrations/2026_08_04_000000_create_empower_schema.php
- backend/database/schema/empower_schema_mysql.sql
- backend/database/seeders/EmpowerReferenceSeeder.php
- backend/database/seeders/DatabaseSeeder.php
- backend/database/seeders/sql/seed-data.sql
- backend/postman/EMPOWER.postman_collection.json
- backend/README.md

## Recommended Build Order
1. Create Laravel project and configure MySQL connection.
2. Convert SQL table blocks into Laravel migration files by module.
3. Create seeders for roles, permissions, requirement types, and criteria catalog.
4. Implement RBAC middleware and policies.
5. Build backend modules in this order:
- Clients and Departments
- Job Requests
- Applicants and Requirements
- Matching Engine
- Training
- Deployment and Employees
- Violations and Separation
- Reports and Audit
6. Build React screens in route order from dashboard to core workflows.
7. Execute unit, integration, and UAT scenarios from use-case narratives.
8. Implement API endpoints using the REST contract and validation matrix.
9. Execute seed data and smoke-test end-to-end lifecycle transitions.
10. Import Postman collection and execute module-level API smoke tests.

## Notes for Panel Defense
1. The schema enforces the full applicant-to-employee lifecycle.
2. Folder logic and lifecycle transitions are system-driven, not manual.
3. Competency scoring is rule-based and explainable, with HR final decision preserved.
4. Separation and archival preserve legal and operational history.
