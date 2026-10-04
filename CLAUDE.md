# SMART-HEART

Clinical-trial software (eCRF, clinician portal, participant app) on one PHP 8.5 + MySQL 8.4 API. Read README.md for layout and commands.

## Rules

- Scores, eligibility, phases, zones and alerts are computed only on the server, in `apps/api/src/Calc` or engines that pass `packages/contracts/test_vectors.json`. Clients never compute them.
- Follow `docs/calculation-specification.md`: IST calendar dates for day arithmetic, timestamps in UTC, round before classifying, missing input is null/pending and never 0, no silent clinical defaults. Anything marked PROPOSED there is not a decision yet: ask before treating it as one.
- Follow `packages/contracts/openapi.yaml` for every endpoint: snake_case JSON, problem+json errors, cursor pagination, `row_version` + `If-Match`, `change_reason` after a form is complete.
- Bilingual: everything a participant or caregiver sees is in English and Tamil. Server-supplied participant text comes as `_en` / `_ta` pairs (e.g. `heading_en`, `heading_ta`); the app holds its own UI strings in both languages and picks by `preferred_language`. New Tamil text is a draft until a native speaker reviews it — say so in the code. Questionnaires (PHQ-9, GAD-7, DASI, EQ-5D-5L) use only their licensed, validated Tamil versions, never our own translation.
- Schema changes go in a new `db/migrations/NNNN_*.sql`; never edit an applied migration.
- Core PHP, no framework. Keep dependencies minimal and ask before adding one.
- Before saying work is done: `composer test` and `composer test:db` in `apps/api` must pass.
