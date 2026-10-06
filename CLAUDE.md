# SMART-HEART

Clinical-trial software for the SMART-HEART RCT (SRMC): eCRF + admin, clinician portal, participant app. Read README.md for layout, deployment and commands.

## Stack (decided 6 Oct 2026)

- `apps/api`: **Laravel 13**, PHP 8.3+, MySQL (SQLite for tests). Deployed on HostingRaja shared hosting, so no queue workers or long-running processes: scheduled work runs through `schedule:run` from cron.
- `apps/admin`: **Vite + React** admin panel, built into `apps/api/public/admin` and served at `/admin`.
- Participant app (later): **React Native**, Android only, Health Connect for Redmi Watch 3 Active + Omron BP, Firebase phone OTP.
- `archive/core-php-api`: earlier framework-free API. Reference only; port pieces (case-number sign-in, MFA, calculation test vectors) instead of editing it.

## Rules

- This system is the trial's official research database (no REDCap). Every create/update/sign/unlock/export goes through `App\Support\Audit`; the audit log is append-only.
- Eligibility, scores and other clinical calculations are computed on the server only (`app/Forms/*Engine.php`), never typed by staff or computed in the client. Unit-test every rule with boundary values.
- eCRF field codes and ranges live in `app/Forms/Definitions/*.php` and follow `SMART_HEART_eCRF.html` naming. Changing a saved value on a complete form needs a reason; signed forms are locked.
- Follow `docs/calculation-specification.md` for calculations: IST calendar dates for day arithmetic, round before classifying, missing input is pending (never 0), no silent clinical defaults. Anything marked PROPOSED is not a decision yet: ask.
- Bilingual: everything a participant or caregiver sees is in English and Tamil. New Tamil text is a draft until a native speaker reviews it. Questionnaires (PHQ-9, GAD-7, DASI, EQ-5D-5L) use only their licensed, validated Tamil versions.
- Access is controlled by the admin-editable roles matrix (`App\Support\Screens`). Add a screen key there for every new screen.
- Schema changes go in new migrations; never edit a migration that has run on the server.
- Before saying work is done: `php artisan test` in `apps/api` and `npm run build` in `apps/admin` must pass.
