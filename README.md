# SMART-HEART · Smart Heart Idhayam

Software for the SMART-HEART randomised trial of digital cardiac rehabilitation after PCI (Sri Ramachandra Medical Centre, 240 participants, 1:1). One REST API serves three clients:

| Client | Users | Stack | Status |
|---|---|---|---|
| eCRF + Admin | Study staff: screening, baseline, PROs, safety, randomisation | Vite + React (web) | Not started |
| Clinician Monitoring Portal | Clinicians, intervention arm | Same web app | Not started |
| SHI participant app | Intervention-arm participants and caregivers | React Native (Expo), **Android first** | Not started. Health Connect proof of concept in `~/tracker` |
| **API** | All of the above | Core PHP 8.5 + MySQL 8.4 LTS | **Foundation + sign-in in place** |

**The one rule:** every score, eligibility decision, rehab phase and heart-rate zone is computed on the server by versioned functions that pass the shared test vectors. Clients only display results. See [docs/calculation-specification.md](docs/calculation-specification.md).

## Layout

```
apps/api/                 PHP API (composer project)
  public/index.php        front controller, base path /api/v1
  bin/migrate.php         applies db/migrations
  src/Calc/Calc.php       reference calculation engines (102 vectors)
  src/Auth/CaseNumber.php participant sign-in credential
  src/Http/               router, request/response, problem+json errors
  src/Infra/              config, database, migrations
  tests/Unit, Integration PHPUnit
  tests/scripts/          vector suites (calc, case number, DB lifecycle)
db/migrations/            0001_baseline.sql = the full schema; 0002_auth.sql = lockout, MFA, permissions, audit chain head
packages/contracts/       openapi.yaml, test_vectors.json, caseNumber.ts (shared with web and mobile)
docs/                     calculation spec, API reference, design mockups
```

## Local setup (macOS, Homebrew)

```bash
brew install php mysql@8.4 composer
brew services start mysql@8.4

cd apps/api
cp .env.example .env        # root over /tmp/mysql.sock by default
php bin/generate-secrets.php >> .env   # local keys only
composer install
composer migrate            # creates and migrates smart_heart
php bin/create-user.php --email=you@example.in --name="Your Name" --site=SRMC \
    --site-name="Sri Ramachandra Medical Centre" --roles=pi   # asks for a password
composer serve              # http://localhost:8080/api/v1/health
```

## Secrets

Servers read keys from environment variables, never from files in the repository: `JWT_SIGNING_KEY`,
`CASE_NUMBER_PEPPER_<n>`, `DATA_ENCRYPTION_KEY` and their ids (see `apps/api/src/Infra/Secrets.php`, which also
explains rotation). Generate a separate set per environment with `php bin/generate-secrets.php`. The app refuses
to start if a key is missing or shorter than 32 bytes. Losing `CASE_NUMBER_PEPPER_*` makes every issued case
number unusable, and losing `DATA_ENCRYPTION_KEY` makes encrypted columns unreadable: back them up securely.

## What the API does so far

| Area | Endpoints |
|---|---|
| System | `GET /health` |
| Staff sign-in | `POST /auth/login`, `/auth/mfa/verify`, `/auth/mfa/enroll`, `/auth/mfa/confirm`, `/auth/reauth`, `GET /auth/me` |
| Sessions (all users) | `POST /auth/refresh`, `/auth/logout` |
| Participant / caregiver sign-in | `POST /app/auth/login` (case number) |
| App access (staff) | `GET/POST /participants/{id}/case-numbers`, `…/{credentialId}/revoke`, `…/{credentialId}/sign-out` |

## Tests

```bash
composer test       # unit tests, 102 calculation vectors, case-number tests, TypeScript cross-check (needs Node 22.18+)
composer test:db    # needs MySQL: migrations, triggers, health, case-number lifecycle (uses smart_heart_test)
```

`composer test:vectors` rewrites `packages/contracts/test_vectors.json`. Commit it when a vector changes; the web and mobile teams test their display code against it.

## Migrations

Add `db/migrations/NNNN_description.sql`; never edit a migration that has been applied (the runner checks a checksum and stops). `DELIMITER` lines are supported for triggers. MySQL cannot roll back DDL, so a failed migration names the statement and must be repaired by hand. `php bin/migrate.php --fresh` drops and rebuilds the database and is refused when `APP_ENV=production`.
