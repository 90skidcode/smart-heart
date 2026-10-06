# SMART-HEART eCRF — Phase 1 (Laravel)

> **Stack decision (6 Oct 2026):** the eCRF and admin system are built in **Laravel 13 + Vite React**, as in this
> folder. The core-PHP API in `apps/api` and the "no framework" rule in `/CLAUDE.md` predate this decision.
> Built admin assets are not committed: run `npm run build` in `admin/` (outputs to `api/public/admin`).

Admin system for the SMART-HEART trial at Sri Ramachandra Medical Centre: staff sign-in, roles matrix, audit trail, and the eCRF from registration to consent (REG-01 → SCR-01 → CON-01).

```
smart-heart/
├── api/     Laravel 13 (PHP 8.3+) — API, database, eCRF rules. Serves the admin panel at /admin
└── admin/   React + Vite source for the admin panel (already built into api/public/admin)
```

---

## 1. What Phase 1 does

| Area | Behaviour |
|---|---|
| **Sign-in** | Email + password. 5 wrong passwords lock the account for 15 min. Auto sign-out after 15 min idle (warning at 14). New users get a temporary password and must change it. Passwords: ≥ 10 characters, letters + numbers, must not contain the email name. |
| **Roles matrix** | Admin sets **read / write per screen** for each role (Administration → Roles & permissions). Starts with three roles: Technical Admin, PI / Research Coordinator, Cardiologist (view-only). The system admin role can never lose Users, Roles or Audit access, so nobody gets locked out. |
| **REG-01** | Identity (name, mobile, hospital no., address) + referral. Generates **SMART-HEART-0001** and **SCR-0001**. Warns on duplicate hospital numbers. |
| **SCR-01** | All 8 screens from the eCRF prototype. **Eligibility is calculated live** as fields are entered (4 inclusion, 11 exclusion criteria). Age and days-since-PCI are computed, never typed. "Unknown" answers keep eligibility *pending*, which blocks signing. Once any exclusion is found, the form can be completed and signed immediately as a screen failure. |
| **CON-01** | Consent record (date/time, PIS version, language, signature or thumb impression + witness, caregiver-view agreement). Opens only after SCR-01 is **signed as ELIGIBLE**. |
| **Form workflow** | In progress → Complete → **PI signed (locked)**. Out-of-range values (e.g. SBP > 250) need a confirmation reason. After a form is complete, **every change needs a reason**. Signing needs the PI's password and stores name, time and the declaration text. Unlocking needs a reason and a new signature; a form can't be unlocked while a later signed form depends on it. |
| **Audit trail** | Every sign-in, field change (old → new + reason), signature, unlock, export, user and permission change. Cannot be edited or deleted. Filter by participant, action, form, dates; download as CSV. |
| **Dashboard** | Counts, enrolment flow for CONSORT, screen-failure reasons, participant list with **arm filter** (All / Intervention / Control / Not randomised), status filter and search. |
| **Export** | De-identified CSV (no name, mobile, hospital no., address, DOB, consent-taker/witness names) with optional **A/B arm coding**, identified CSV (separate permission), and a **data dictionary**. Every download is logged. |
| **Backup** | `php artisan shi:backup` — encrypted (AES-256) daily backup at 01:30 IST, kept 30 days; `shi:restore` restores one. |

Field codes match `SMART_HEART_eCRF.html` style (e.g. `SCR_LVEF`, `SCR_EGFR`, `ELIG_STATUS`, `INC_PCI`, `EXC_UNCONTROLLED_HTN`). The data dictionary export lists all of them.

### Eligibility rules as built
Inclusion: age ≥ 18 · ACS or stable IHD (ACS needs a subtype) · PCI done ≤ 30 days before screening · smartphone access **on Android**.
Exclusion: CABG · LVEF < 40 % · cardiac arrest · complex ventricular arrhythmia · cardiogenic shock · diabetic retinopathy / neuropathy / foot ulcer · eGFR < 45 · SBP ≥ 160 or DBP ≥ 100 · visual/hearing impairment that prevents safe app use · cognitive impairment that prevents safe app use (impairment manageable with aids or a caregiver does **not** exclude).

To change a threshold: `api/app/Forms/EligibilityEngine.php` (constants at the top). To change fields or ranges: `api/app/Forms/Definitions/*.php`.

---

## 2. Deploy on HostingRaja (shared hosting, cPanel)

**Requirements:** PHP **8.3 or newer** (choose 8.3 or 8.4 in cPanel → *Select PHP Version*), MySQL, SSL certificate, cron jobs. PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `json`, `zlib`, `fileinfo`, `tokenizer`, `ctype`.

### Step 1 — Install PHP packages on your own computer
Shared hosting usually has no Composer, so do this once on a laptop with PHP 8.3+ and Composer:
```bash
cd smart-heart/api
composer install --no-dev --optimize-autoloader
```
This creates the `vendor/` folder.

### Step 2 — Create the database
cPanel → **MySQL Databases**: create a database (e.g. `user_smartheart`) and a user with a strong password; add the user to the database with **ALL PRIVILEGES**.

### Step 3 — Upload
Zip the whole `api` folder (including `vendor/` and `public/admin/`) and upload it with cPanel → **File Manager** to your home folder, *outside* `public_html`, e.g. `/home/USER/smartheart/`. Extract it there.

### Step 4 — Point the web address at the `public` folder
- **Best:** create a subdomain (e.g. `ecrf.yourdomain.in`) in cPanel → **Domains/Subdomains** and set its **document root** to `/home/USER/smartheart/public`.
- **If you can't change the document root:** copy everything inside `smartheart/public/` into the subdomain's folder, then edit that copy of `index.php` and change the two paths `__DIR__.'/../vendor/autoload.php'` and `__DIR__.'/../bootstrap/app.php'` to `'/home/USER/smartheart/vendor/autoload.php'` and `'/home/USER/smartheart/bootstrap/app.php'`.

Turn on **SSL** (cPanel → SSL/TLS Status → AutoSSL) and make sure the site opens with `https://`.

### Step 5 — Configure `.env`
In File Manager, copy `.env.example` to `.env` and fill in:
```
APP_URL=https://ecrf.yourdomain.in
DB_DATABASE=user_smartheart
DB_USERNAME=user_shi
DB_PASSWORD=...
BACKUP_KEY=           # see below
SEED_ADMIN_EMAIL=you@srmc...    # first administrator
SEED_ADMIN_PASSWORD=            # leave blank to get a random one printed
STUDY_ID_START=1      # set higher if paper CRFs already used numbers
```
`APP_KEY` and `BACKUP_KEY` are generated in the next step. **Save a copy of `BACKUP_KEY` somewhere off the server** (e.g. the PI's password manager). Without it, backups cannot be opened.

### Step 6 — Set up the database
If cPanel has **Terminal** (or SSH):
```bash
cd ~/smartheart
php artisan key:generate --force
php -r "echo 'BACKUP_KEY=', base64_encode(random_bytes(32)), PHP_EOL;"   # paste into .env
php artisan migrate --force
php artisan db:seed --force          # creates roles + first admin, prints password if blank
php artisan config:cache
php artisan route:cache
```
No terminal? Run the same commands on your laptop against the server database (if remote MySQL is allowed), or ask HostingRaja support to run them once.

Folders `storage/` and `bootstrap/cache/` must be writable (permission 755, or 775 if needed).

### Step 7 — Cron job (backups and session clean-up)
cPanel → **Cron Jobs** → every 5 minutes:
```
*/5 * * * * /usr/local/bin/php /home/USER/smartheart/artisan schedule:run >> /dev/null 2>&1
```
(Check the PHP path in cPanel; it may be `/opt/cpanel/ea-php83/root/usr/bin/php`.)

### Step 8 — First sign-in
Open `https://ecrf.yourdomain.in/admin/`, sign in as the admin, change the password, then **Users → Add user** for the PI and the cardiologist. Each gets a one-time temporary password.

### Step 9 — Check before the first participant
- [ ] Sign in as the PI, register a **test** participant, complete SCR-01, sign it, check the audit trail.
- [ ] Download a de-identified export and confirm no names appear.
- [ ] Next morning, check that `storage/app/backups/` has a new `.enc` file.
- [ ] Copy one backup off the server (File Manager → Download) and keep it with the `BACKUP_KEY`.
- [ ] Remove the test participant's data by restoring an empty database: `php artisan migrate:fresh --force && php artisan db:seed --force` (do this **before** real enrolment only).

---

## 3. Backups and restore test (monthly)
```bash
php artisan shi:backup                                   # make one now
php artisan shi:backup --decrypt=storage/app/backups/shi_YYYYmmdd_HHMMSS.json.gz.enc
# On a SEPARATE test database only:
php artisan migrate:fresh --force
php artisan shi:restore storage/app/backups/shi_YYYYmmdd_HHMMSS.json.gz
```
Download backups regularly from `storage/app/backups/` to a hospital computer; the server copy alone is not enough.

---

## 4. Local development
```bash
# API
cd api && composer install && cp .env.example .env
# in .env: DB_CONNECTION=sqlite, DB_DATABASE=/full/path/to/api/database/database.sqlite, APP_ENV=local, APP_DEBUG=true
touch database/database.sqlite
php artisan key:generate && php artisan migrate --seed
php artisan serve                       # http://127.0.0.1:8000

# Admin panel (hot reload, proxies /api to :8000)
cd admin && npm install && npm run dev  # http://localhost:5173/admin/
npm run build                           # rebuilds into api/public/admin
```
Tests: `cd api && php artisan test` (17 tests: eligibility rules, gating, signing, unlock order, reason-for-change, audit immutability, permissions, lockout, de-identified export).

---

## 5. Settings in `api/config/smartheart.php` / `.env`
| Setting | Default |
|---|---|
| `SESSION_IDLE_MINUTES` | 15 |
| `LOGIN_LOCKOUT_ATTEMPTS` / `_MINUTES` | 5 / 15 |
| `STUDY_ID_START` | 1 |
| `ARM_CODE_INTERVENTION` / `_CONTROL` | A / B |
| `BACKUP_KEEP_DAYS` | 30 |
| Reasons for change list | `change_reasons` in the config file |
| Signature declaration text | `signature_meanings` in the config file |

---

## 6. Not in Phase 1 (next)
BL-01 baseline modules · PRO-01 tablet self-entry (DHRx, PHQ-9, GAD-7, EQ-5D-5L, DASI, MARS-5) · SAF-01 · RAND-01 · FU-01 · AE/MACE/withdrawal · intervention monitoring dashboard · control-arm web links · mobile app (React Native, Health Connect, Firebase OTP).
