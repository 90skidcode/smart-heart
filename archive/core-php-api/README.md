# Archived: core-PHP API

The first SMART-HEART API (PHP 8.5, no framework), replaced on 6 Oct 2026 by the Laravel app in `apps/api`.
Kept for reference only. Worth porting into Laravel when the participant app is built:

- `api/src/Auth/CaseNumber.php`: participant and caregiver case-number sign-in (printed bilingual card)
- `api/src/Auth/Totp.php`: optional staff MFA with recovery codes
- `api/src/Calc/Calc.php` + `packages/contracts/test_vectors.json`: calculation engines with 102 test vectors
- `db/migrations/0001_baseline.sql`: the original full schema design
