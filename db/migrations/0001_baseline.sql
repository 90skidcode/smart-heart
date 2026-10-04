-- =====================================================================
-- SMART-HEART / Smart Heart Idhayam (SHI)
-- Target: MySQL 8.4 LTS  ·  InnoDB · utf8mb4 · all timestamps stored in UTC
-- Verified: loads on MySQL 8.4.11 (65 tables, 2 views, 2 triggers) through bin/migrate.php; case-number lifecycle passes.
-- (MySQL 8.0 itself reached end of life on 30 April 2026 — do not deploy on it.)
-- Systems served: eCRF (REG/SCR/BL/PRO/SAF/RAND), Clinician Monitoring
-- Portal, Participant mobile app (React Native), Admin.
-- Conventions:
--   * Surrogate PK `id` BIGINT UNSIGNED; public identifiers are separate
--   * Every clinical table carries created_by/updated_by + row version
--   * Every write to a clinical table is mirrored into audit_log (app layer,
--     plus triggers that forbid UPDATE/DELETE on audit_log itself)
--   * PII columns (names, phone, email) are AES-256-GCM encrypted in PHP
--     before insert (VARBINARY); a blind index (HMAC) supports lookups
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
-- 1. ORGANISATION, STAFF, ACCESS
-- ---------------------------------------------------------------------
CREATE TABLE sites (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(16)  NOT NULL UNIQUE,            -- e.g. SRMC
  name          VARCHAR(160) NOT NULL,
  city          VARCHAR(80),
  timezone      VARCHAR(40)  NOT NULL DEFAULT 'Asia/Kolkata',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB;

CREATE TABLE users (                                      -- staff only
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id         BIGINT UNSIGNED NOT NULL,
  email           VARCHAR(190) NOT NULL UNIQUE,
  full_name       VARCHAR(160) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,                  -- password_hash(PASSWORD_ARGON2ID)
  mfa_secret_enc  VARBINARY(255) NULL,                    -- TOTP secret, encrypted
  mfa_enabled     TINYINT(1)  NOT NULL DEFAULT 0,
  status          ENUM('active','locked','disabled') NOT NULL DEFAULT 'active',
  failed_logins   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_login_at   DATETIME(3) NULL,
  password_changed_at DATETIME(3) NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_users_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB;

CREATE TABLE roles (
  id    SMALLINT UNSIGNED PRIMARY KEY,
  code  VARCHAR(32) NOT NULL UNIQUE,   -- admin, pi, sub_investigator, assessor, clinician, care_coach, data_manager, statistician, monitor
  label VARCHAR(80) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id    SMALLINT UNSIGNED PRIMARY KEY,
  code  VARCHAR(64) NOT NULL UNIQUE    -- e.g. screening.write, eligibility.sign, randomise, careplan.push, export.read
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id       SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id),
  FOREIGN KEY (permission_id) REFERENCES permissions(id)
) ENGINE=InnoDB;

CREATE TABLE user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- Refresh tokens for staff, participants and caregivers (rotated on use)
CREATE TABLE auth_refresh_tokens (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_type  ENUM('user','participant','caregiver') NOT NULL,
  subject_id    BIGINT UNSIGNED NOT NULL,
  credential_id BIGINT UNSIGNED NULL,                     -- app sessions: the case number that opened it (re-issue revokes these)
  token_hash    CHAR(64) NOT NULL UNIQUE,                 -- sha256 of opaque token
  family_id     CHAR(36) NOT NULL,                        -- reuse detection → revoke family
  device_id     VARCHAR(128) NULL,                        -- app: install ID made on first launch
  device_label  VARCHAR(80) NULL,                         -- app: "Android 14 · Redmi Note 12", shown to staff
  user_agent    VARCHAR(255) NULL,
  ip            VARBINARY(16) NULL,
  last_used_at  DATETIME(3) NULL,
  expires_at    DATETIME(3) NOT NULL,
  revoked_at    DATETIME(3) NULL,
  revoke_reason VARCHAR(40) NULL,                         -- logout, rotated_reuse, new_device, case_number_reissued, case_number_revoked, staff_sign_out
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX ix_rt_subject (subject_type, subject_id),
  INDEX ix_rt_credential (credential_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. PARTICIPANTS & STUDY CALENDAR
-- ---------------------------------------------------------------------
CREATE TABLE participants (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id          BIGINT UNSIGNED NOT NULL,
  study_id         VARCHAR(24) NOT NULL UNIQUE,           -- SMART-HEART-0032 (shown in eCRF)
  screening_id     VARCHAR(16) NOT NULL UNIQUE,           -- SCR-0032
                                                          -- app sign-in: a random case number in app_credentials (intervention arm only)
  full_name_enc    VARBINARY(512) NULL,
  phone_enc        VARBINARY(255) NULL,
  phone_bidx       CHAR(64) NULL,                         -- HMAC blind index for lookup
  email_enc        VARBINARY(512) NULL,
  dob              DATE NULL,
  sex              ENUM('M','F','O') NULL,
  preferred_language ENUM('en','ta') NOT NULL DEFAULT 'en',
  status           ENUM('registered','screening','screen_failed','eligible','consented','baseline',
                        'safety_cleared','randomised','withdrawn','lost_to_follow_up','completed')
                   NOT NULL DEFAULT 'registered',
  arm              ENUM('intervention','control') NULL,
  age_stratum      ENUM('lt60','ge60') NULL,
  pci_date         DATE NULL,                             -- day 0 for rehab phase
  randomised_at    DATETIME(3) NULL,
  app_activated_at DATETIME(3) NULL,                      -- first sign-in with the case number
  withdrawn_at     DATETIME(3) NULL,
  withdrawal_reason VARCHAR(255) NULL,
  registered_by    BIGINT UNSIGNED NOT NULL,
  created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version      INT UNSIGNED NOT NULL DEFAULT 1,
  INDEX ix_part_status (site_id, status),
  INDEX ix_part_phone (phone_bidx),
  FOREIGN KEY (site_id) REFERENCES sites(id),
  FOREIGN KEY (registered_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Visit schedule template (admin-configurable): screening, baseline, d14, d30, d60, d90, d180
CREATE TABLE visit_definitions (
  id            SMALLINT UNSIGNED PRIMARY KEY,
  code          VARCHAR(16) NOT NULL UNIQUE,              -- SCR, BL, D14, D30, D60, D90, D180
  label         VARCHAR(60) NOT NULL,
  anchor        ENUM('registration','randomisation','pci') NOT NULL DEFAULT 'randomisation',
  day_offset    SMALLINT NOT NULL,
  window_before SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_after  SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  sort_order    SMALLINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

-- Which forms / instruments are expected at which visit
CREATE TABLE visit_form_matrix (
  visit_def_id SMALLINT UNSIGNED NOT NULL,
  form_code    VARCHAR(32) NOT NULL,      -- REG01, SCR01, BL01_M1..M11, PHQ9, GAD7, EQ5D5L, DASI, DHRX, SAF01, RAND01, FU01, CCSPS, SIXMWT, RYP_INDIA
  delivery     ENUM('ecrf','app','either') NOT NULL DEFAULT 'ecrf',
  arm_scope    ENUM('all','intervention','control') NOT NULL DEFAULT 'all',
  is_required  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (visit_def_id, form_code),
  FOREIGN KEY (visit_def_id) REFERENCES visit_definitions(id)
) ENGINE=InnoDB;

CREATE TABLE visits (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  visit_def_id   SMALLINT UNSIGNED NOT NULL,
  target_date    DATE NULL,
  window_start   DATE NULL,
  window_end     DATE NULL,
  actual_date    DATE NULL,
  status         ENUM('scheduled','open','in_progress','complete','missed','not_applicable') NOT NULL DEFAULT 'scheduled',
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_visit (participant_id, visit_def_id),
  FOREIGN KEY (participant_id) REFERENCES participants(id),
  FOREIGN KEY (visit_def_id) REFERENCES visit_definitions(id)
) ENGINE=InnoDB;

-- Status + lock + signature state for every CRF page (one row per form per visit)
CREATE TABLE form_instances (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id       BIGINT UNSIGNED NOT NULL,
  form_code      VARCHAR(32) NOT NULL,
  status         ENUM('not_started','in_progress','complete','signed','locked') NOT NULL DEFAULT 'not_started',
  source         ENUM('ecrf','app','tablet','import') NOT NULL DEFAULT 'ecrf',
  started_at     DATETIME(3) NULL,
  completed_at   DATETIME(3) NULL,
  completed_by   BIGINT UNSIGNED NULL,
  signed_sig_id  BIGINT UNSIGNED NULL,
  locked_at      DATETIME(3) NULL,
  row_version    INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_form (visit_id, form_code),
  FOREIGN KEY (visit_id) REFERENCES visits(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. REG-01 · SCR-01 · CONSENT
-- ---------------------------------------------------------------------
CREATE TABLE registrations (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id        BIGINT UNSIGNED NOT NULL UNIQUE,
  registration_date     DATE NOT NULL,
  referral_source       ENUM('pci_unit_inpatient','cardiology_opd','cardiology_ward','other') NOT NULL,
  referral_other        VARCHAR(120) NULL,
  potentially_eligible  TINYINT(1) NOT NULL,
  created_by            BIGINT UNSIGNED NOT NULL,
  created_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_by            BIGINT UNSIGNED NULL,
  updated_at            DATETIME(3) NULL ON UPDATE CURRENT_TIMESTAMP(3),
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

CREATE TABLE screenings (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id           BIGINT UNSIGNED NOT NULL UNIQUE,
  screening_date           DATE NOT NULL,
  -- Screen 1 · age
  dob                      DATE NULL,
  age_years                TINYINT UNSIGNED NULL,          -- computed server-side
  -- Screen 2 · diagnosis
  index_diagnosis          ENUM('acs','stable_ihd','other') NULL,
  index_diagnosis_other    VARCHAR(120) NULL,
  acs_subtype              ENUM('stemi','nstemi','unstable_angina') NULL,
  -- Screen 3 · PCI
  pci_done                 TINYINT(1) NULL,
  pci_date                 DATE NULL,
  pci_days_before_screen   SMALLINT NULL,                  -- computed
  pci_indication           ENUM('acs','stable_ihd','other') NULL,
  vessels_treated          TINYINT UNSIGNED NULL,
  stents_implanted         TINYINT UNSIGNED NULL,
  access_site              ENUM('femoral','radial','brachial') NULL,
  -- Screen 4 · CABG
  cabg_history             TINYINT(1) NULL,
  -- Screen 5 · high-risk cardiac
  lvef_pct                 TINYINT UNSIGNED NULL,
  lvef_source              VARCHAR(60) NULL,
  lvef_date                DATE NULL,                      -- which echo counts (calc spec §Screening)
  prior_cardiac_arrest     ENUM('no','yes','unknown') NULL,
  complex_vent_arrhythmia  ENUM('no','yes','unknown') NULL,
  cardiogenic_shock        ENUM('no','yes','unknown') NULL,
  -- Screen 6 · DM / renal / BP
  t2dm                     TINYINT(1) NULL,
  retinopathy              ENUM('no','yes','unknown') NULL,
  peripheral_neuropathy    ENUM('no','yes','unknown') NULL,
  diabetic_foot_ulcer      ENUM('no','yes','unknown') NULL,
  egfr                     DECIMAL(5,1) NULL,
  egfr_source              ENUM('lab_reported','ckd_epi_2021') NULL,
  egfr_lab_equation        VARCHAR(40) NULL,               -- e.g. CKD-EPI 2021, CKD-EPI 2009, MDRD (as printed by the lab)
  creatinine_mg_dl         DECIMAL(4,2) NULL,              -- lets the server compute CKD-EPI 2021 and run the consistency check
  sbp                      SMALLINT UNSIGNED NULL,
  dbp                      SMALLINT UNSIGNED NULL,
  bp_reading_count         TINYINT UNSIGNED NULL,          -- 1 or 2; sbp/dbp hold the mean when 2
  on_antihypertensive      TINYINT(1) NULL,
  -- Screen 7 · sensory / cognitive
  visual_impairment        ENUM('no','yes_aids_ok','yes_unsafe') NULL,
  hearing_impairment       ENUM('no','yes_aids_ok','yes_unsafe') NULL,
  cognitive_impairment     ENUM('no','yes_caregiver_ok','yes_unsafe') NULL,
  -- Screen 8 · digital access
  smartphone_access        TINYINT(1) NULL,
  primary_phone_user       ENUM('participant','household_member') NULL,
  phone_os                 ENUM('android','ios') NULL,
  -- Decision (system-generated + PI-confirmed)
  eligibility_result       ENUM('pending','eligible','ineligible') NOT NULL DEFAULT 'pending',
  criteria_json            JSON NULL,                      -- per-criterion status as evaluated by EligibilityEngine
  failure_reasons          JSON NULL,                      -- ["LVEF_LT_40", ...]
  engine_version           VARCHAR(16) NULL,
  confirmed_by             BIGINT UNSIGNED NULL,
  confirmed_at             DATETIME(3) NULL,
  pi_declaration_sig_id    BIGINT UNSIGNED NULL,
  created_by               BIGINT UNSIGNED NOT NULL,
  created_at               DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_by               BIGINT UNSIGNED NULL,
  updated_at               DATETIME(3) NULL ON UPDATE CURRENT_TIMESTAMP(3),
  row_version              INT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (participant_id) REFERENCES participants(id),
  FOREIGN KEY (confirmed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE consent_documents (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version      VARCHAR(16) NOT NULL,                       -- v3.0
  language     ENUM('en','ta') NOT NULL,
  iec_approval_ref VARCHAR(80) NULL,
  effective_from DATE NOT NULL,
  file_id      BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_consent_doc (version, language)
) ENGINE=InnoDB;

CREATE TABLE consents (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id      BIGINT UNSIGNED NOT NULL,
  consent_document_id BIGINT UNSIGNED NOT NULL,
  method              ENUM('paper_scanned','econsent_tablet','econsent_app') NOT NULL,
  signed_at           DATETIME(3) NOT NULL,
  signature_file_id   BIGINT UNSIGNED NULL,                -- signature / thumb impression image
  is_thumb_impression TINYINT(1) NOT NULL DEFAULT 0,
  impartial_witness_name VARCHAR(120) NULL,
  witness_signature_file_id BIGINT UNSIGNED NULL,
  av_recording_file_id BIGINT UNSIGNED NULL,               -- if AV consent required by IEC
  obtained_by         BIGINT UNSIGNED NOT NULL,
  data_sharing_ok     TINYINT(1) NOT NULL DEFAULT 1,
  caregiver_access_ok TINYINT(1) NOT NULL DEFAULT 0,
  withdrawn_at        DATETIME(3) NULL,
  created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  FOREIGN KEY (participant_id) REFERENCES participants(id),
  FOREIGN KEY (consent_document_id) REFERENCES consent_documents(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. BL-01 BASELINE MODULES (also reused at follow-up via visit_id)
-- ---------------------------------------------------------------------
CREATE TABLE participant_details (                         -- BL-01 M1
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL UNIQUE,
  marital_status ENUM('single','married','widowed','separated_divorced','other') NULL,
  education      ENUM('none','primary','secondary','higher_secondary','graduate','postgraduate') NULL,
  employment     ENUM('employed','self_employed','unemployed','retired','homemaker','other') NULL,
  residence      ENUM('urban','semi_urban','rural') NULL,
  household_income_band VARCHAR(32) NULL,
  updated_by     BIGINT UNSIGNED NULL,
  updated_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version    INT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

CREATE TABLE cv_profiles (                                 -- BL-01 M2 (seeded from SCR-01)
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id     BIGINT UNSIGNED NOT NULL UNIQUE,
  index_event_date   DATE NULL,
  diagnosis_text     VARCHAR(160) NULL,                    -- "NSTEMI — inferolateral"
  culprit_vessel     VARCHAR(60) NULL,
  vessels_summary    VARCHAR(160) NULL,
  killip_class       ENUM('I','II','III','IV') NULL,
  nyha_class         ENUM('I','II','III','IV') NULL,
  prior_mi           TINYINT(1) NULL,
  prior_pci          TINYINT(1) NULL,
  hypertension       TINYINT(1) NULL,
  dyslipidaemia      TINYINT(1) NULL,
  ckd_stage          TINYINT UNSIGNED NULL,
  family_history_cad TINYINT(1) NULL,
  risk_tier          ENUM('low','low_moderate','moderate','high') NULL,
  updated_by         BIGINT UNSIGNED NULL,
  updated_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

CREATE TABLE anthropometry (                               -- BL-01 M3 (per visit)
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id     BIGINT UNSIGNED NOT NULL UNIQUE,
  height_cm    DECIMAL(5,1) NULL,
  weight_kg    DECIMAL(5,1) NULL,
  bmi          DECIMAL(4,1) NULL,                          -- computed
  waist_cm     DECIMAL(5,1) NULL,
  updated_by   BIGINT UNSIGNED NULL,
  updated_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version  INT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (visit_id) REFERENCES visits(id)
) ENGINE=InnoDB;

CREATE TABLE clinical_measurements (                       -- BL-01 M4 (per visit)
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id     BIGINT UNSIGNED NOT NULL UNIQUE,
  sbp_1 SMALLINT UNSIGNED NULL, dbp_1 SMALLINT UNSIGNED NULL,
  sbp_2 SMALLINT UNSIGNED NULL, dbp_2 SMALLINT UNSIGNED NULL,
  sbp_mean DECIMAL(5,1) NULL,   dbp_mean DECIMAL(5,1) NULL, -- computed
  heart_rate   SMALLINT UNSIGNED NULL,
  spo2         TINYINT UNSIGNED NULL,
  lvef_pct     TINYINT UNSIGNED NULL,
  lvef_carried_from_screening TINYINT(1) NOT NULL DEFAULT 0,
  updated_by   BIGINT UNSIGNED NULL,
  updated_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version  INT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (visit_id) REFERENCES visits(id)
) ENGINE=InnoDB;

CREATE TABLE lab_tests (                                   -- dictionary; canonical unit per test
  code          VARCHAR(16) PRIMARY KEY,                   -- HBA1C, LDL, TC, HDL, TG, CREAT, EGFR, FBG, PPBG
  label         VARCHAR(60) NOT NULL,
  canonical_unit VARCHAR(20) NOT NULL,                     -- %, mg/dL, mL/min/1.73m²
  alt_unit      VARCHAR(20) NULL,                          -- mmol/L
  alt_to_canonical_factor DECIMAL(10,6) NULL,              -- canonical = alt × factor + offset (LDL: × 38.67)
  alt_to_canonical_offset DECIMAL(10,4) NOT NULL DEFAULT 0, -- needed for HbA1c IFCC→NGSP: % = 0.09148 × mmol/mol + 2.152
  min_plausible DECIMAL(8,2) NULL,
  max_plausible DECIMAL(8,2) NULL
) ENGINE=InnoDB;

CREATE TABLE lab_results (                                 -- BL-01 M5 + follow-up + app-entered
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  visit_id       BIGINT UNSIGNED NULL,
  test_code      VARCHAR(16) NOT NULL,
  value_entered  DECIMAL(8,2) NOT NULL,
  unit_entered   VARCHAR(20) NOT NULL,
  value_canonical DECIMAL(8,2) NOT NULL,                   -- converted server-side
  sample_date    DATE NOT NULL,
  source         ENUM('lab_report','medical_record','carried_from_scr','participant_app') NOT NULL,
  method         ENUM('direct','friedewald','martin_hopkins','sampson','ckd_epi_2021','ckd_epi_2009','mdrd','other') NULL, -- LDL derivation or eGFR equation
  verified_by    BIGINT UNSIGNED NULL,                     -- app-entered values need staff verification before use as outcome
  verified_at    DATETIME(3) NULL,
  client_uuid    CHAR(36) NULL UNIQUE,
  created_by_type ENUM('user','participant') NOT NULL DEFAULT 'user',
  created_by     BIGINT UNSIGNED NOT NULL,
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  row_version    INT UNSIGNED NOT NULL DEFAULT 1,
  INDEX ix_lab_part (participant_id, test_code, sample_date),
  FOREIGN KEY (participant_id) REFERENCES participants(id),
  FOREIGN KEY (test_code) REFERENCES lab_tests(code)
) ENGINE=InnoDB;

CREATE TABLE drug_dictionary (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  drug_class  VARCHAR(40) NOT NULL,                        -- antiplatelet, p2y12, statin, beta_blocker, acei_arb, antidiabetic, diuretic, ...
  generic_name VARCHAR(80) NOT NULL,
  is_dapt_component TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_drug (drug_class, generic_name)
) ENGINE=InnoDB;

CREATE TABLE participant_medications (                     -- BL-01 M6; pushes to app Meds tab
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  drug_id        BIGINT UNSIGNED NULL,
  drug_name_free VARCHAR(120) NULL,
  dose           VARCHAR(40) NOT NULL,
  frequency      ENUM('od','bd','tds','qid','od_night','prn','weekly','other') NOT NULL,
  slots          SET('morning','afternoon','evening','night') NOT NULL,
  start_date     DATE NULL,
  stop_date      DATE NULL,
  is_current     TINYINT(1) NOT NULL DEFAULT 1,
  source         ENUM('ecrf','app_participant','discharge_ocr') NOT NULL DEFAULT 'ecrf',
  verified_by    BIGINT UNSIGNED NULL,
  created_by_type ENUM('user','participant') NOT NULL DEFAULT 'user',
  created_by     BIGINT UNSIGNED NOT NULL,
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version    INT UNSIGNED NOT NULL DEFAULT 1,
  INDEX ix_med_part (participant_id, is_current),
  FOREIGN KEY (participant_id) REFERENCES participants(id),
  FOREIGN KEY (drug_id) REFERENCES drug_dictionary(id)
) ENGINE=InnoDB;

CREATE TABLE lifestyle_assessments (                       -- BL-01 M7 (per visit)
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id       BIGINT UNSIGNED NOT NULL UNIQUE,
  smoking_status ENUM('never','former','current') NULL,
  tobacco_type   SET('cigarette','bidi','smokeless','other') NULL,
  units_per_day  SMALLINT UNSIGNED NULL,
  years_smoked   SMALLINT UNSIGNED NULL,
  quit_date      DATE NULL,
  sleep_hours_self DECIMAL(3,1) NULL,
  alcohol_use    TINYINT(1) NULL,
  alcohol_units_week SMALLINT UNSIGNED NULL,
  physical_activity_min_week SMALLINT UNSIGNED NULL,
  diet_score     TINYINT UNSIGNED NULL,                    -- Rate Your Plate – India (0–100)
  updated_by     BIGINT UNSIGNED NULL,
  updated_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version    INT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (visit_id) REFERENCES visits(id)
) ENGINE=InnoDB;

CREATE TABLE six_minute_walk_tests (                       -- BL-01 M8 (eCRF only)
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id      BIGINT UNSIGNED NOT NULL UNIQUE,
  performed     TINYINT(1) NOT NULL,
  not_done_reason VARCHAR(160) NULL,
  distance_m    SMALLINT UNSIGNED NULL,
  pre_hr        SMALLINT UNSIGNED NULL,  post_hr  SMALLINT UNSIGNED NULL,
  pre_spo2      TINYINT UNSIGNED NULL,   post_spo2 TINYINT UNSIGNED NULL,
  pre_borg      TINYINT UNSIGNED NULL,   post_borg TINYINT UNSIGNED NULL,
  stopped_early TINYINT(1) NULL,
  category      ENUM('poor','intermediate','ideal') NULL,  -- <350 / 350–500 / >500
  assessor_id   BIGINT UNSIGNED NULL,
  updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (visit_id) REFERENCES visits(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. PRO-01 · INSTRUMENTS & RESPONSES (DHRx, PHQ-9, GAD-7, EQ-5D-5L, DASI, BRiDgE, RYP)
-- ---------------------------------------------------------------------
CREATE TABLE instruments (
  code        VARCHAR(16) PRIMARY KEY,                     -- PHQ9, GAD7, EQ5D5L, DASI, DHRX, BRIDGE, RYP_INDIA
  name        VARCHAR(120) NOT NULL,
  version     VARCHAR(16) NOT NULL,
  recall_period VARCHAR(40) NULL,
  show_score_to_participant TINYINT(1) NOT NULL DEFAULT 0,
  licence_ref VARCHAR(120) NULL
) ENGINE=InnoDB;

CREATE TABLE instrument_items (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  instrument_code VARCHAR(16) NOT NULL,
  item_no         VARCHAR(8) NOT NULL,                      -- "1".."9", "F" (function), "C1"
  text_en         VARCHAR(500) NOT NULL,
  text_ta         VARCHAR(500) NOT NULL,
  response_set    JSON NOT NULL,                            -- [{"v":0,"en":"Not at all","ta":"..."}, ...]
  is_scored       TINYINT(1) NOT NULL DEFAULT 1,
  weight          DECIMAL(5,2) NULL,                        -- DASI item weights
  is_safety_item  TINYINT(1) NOT NULL DEFAULT 0,            -- PHQ-9 item 9
  sort_order      SMALLINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_item (instrument_code, item_no),
  FOREIGN KEY (instrument_code) REFERENCES instruments(code)
) ENGINE=InnoDB;

CREATE TABLE pro_responses (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id   BIGINT UNSIGNED NOT NULL,
  visit_id         BIGINT UNSIGNED NOT NULL,
  instrument_code  VARCHAR(16) NOT NULL,
  status           ENUM('in_progress','complete') NOT NULL DEFAULT 'in_progress',
  answers          JSON NOT NULL,                           -- {"1":0,"2":1,...,"F":2}
  total_score      DECIMAL(6,2) NULL,
  severity         VARCHAR(32) NULL,                        -- minimal/mild/moderate/...
  derived          JSON NULL,                               -- {"item9_positive":false,"review_flag":true,"mets":5.2,"health_state":"21111","vas":70,"utility":0.83}
  scoring_version  VARCHAR(16) NULL,
  delivery         ENUM('app','tablet','ecrf_interview') NOT NULL,
  started_at       DATETIME(3) NULL,
  completed_at     DATETIME(3) NULL,
  client_uuid      CHAR(36) NULL UNIQUE,
  created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version      INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_pro (visit_id, instrument_code),
  INDEX ix_pro_part (participant_id, instrument_code),
  FOREIGN KEY (participant_id) REFERENCES participants(id),
  FOREIGN KEY (visit_id) REFERENCES visits(id),
  FOREIGN KEY (instrument_code) REFERENCES instruments(code)
) ENGINE=InnoDB;

CREATE TABLE eq5d_value_sets (                              -- India value set, loaded under EuroQol licence
  country     CHAR(2) NOT NULL DEFAULT 'IN',
  health_state CHAR(5) NOT NULL,                             -- "11111".."55555"
  utility     DECIMAL(6,4) NOT NULL,
  PRIMARY KEY (country, health_state)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. CCSPS COMPOSITE · SAFETY · RANDOMISATION
-- ---------------------------------------------------------------------
CREATE TABLE composite_scores (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id         BIGINT UNSIGNED NOT NULL UNIQUE,
  domains          JSON NOT NULL,          -- {"bp":{"score":1,"input":"138/86"},"ldl":{...},...,"mental":{"score":0,"rule":"max(PHQ9,GAD7) severity"}}
  raw_score        TINYINT UNSIGNED NULL,  -- sum of scored domains (0–20)
  domains_scored   TINYINT UNSIGNED NOT NULL,
  domains_pending  JSON NULL,
  normalised_0_100 DECIMAL(5,1) NULL,      -- per SAP: complete case (all 10) or proportional (≥ 8 domains)
  normalisation_method ENUM('complete_case','proportional') NULL,
  arm_source_profile VARCHAR(32) NULL,     -- which data sources fed each domain (must be arm-neutral, calc spec D1)
  trace            JSON NULL,              -- inputs with record ids, each step, output: lets a monitor re-derive the score
  is_final         TINYINT(1) NOT NULL DEFAULT 0,
  spec_version     VARCHAR(16) NOT NULL,   -- CCSPS spec version
  config_id        BIGINT UNSIGNED NULL,   -- scoring_configs row used
  computed_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  FOREIGN KEY (visit_id) REFERENCES visits(id)
) ENGINE=InnoDB;

CREATE TABLE safety_clearances (                            -- SAF-01
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id        BIGINT UNSIGNED NOT NULL UNIQUE,
  checklist             JSON NOT NULL,                      -- [{"code":"VITALS_REVIEWED","done":true,"note":"HR 74, BP 138/86"}, ...]
  acute_decompensation_since_pci TINYINT(1) NULL,
  safety_concerns       TINYINT(1) NOT NULL,
  deferral_reason       VARCHAR(255) NULL,
  outcome               ENUM('cleared','deferred') NOT NULL,
  cleared_by            BIGINT UNSIGNED NOT NULL,
  cleared_at            DATETIME(3) NOT NULL,
  signature_id          BIGINT UNSIGNED NULL,
  FOREIGN KEY (participant_id) REFERENCES participants(id),
  FOREIGN KEY (cleared_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Statistician-generated sequence, imported once; never exposed to site staff
CREATE TABLE randomisation_sequence (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id     BIGINT UNSIGNED NOT NULL,
  stratum     ENUM('lt60','ge60') NOT NULL,
  seq_no      INT UNSIGNED NOT NULL,
  block_no    INT UNSIGNED NOT NULL,
  block_size  TINYINT UNSIGNED NOT NULL,
  arm         ENUM('intervention','control') NOT NULL,
  used_by_participant_id BIGINT UNSIGNED NULL UNIQUE,
  used_at     DATETIME(3) NULL,
  import_batch VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_seq (site_id, stratum, seq_no)
) ENGINE=InnoDB;

CREATE TABLE randomisations (                               -- RAND-01
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id  BIGINT UNSIGNED NOT NULL UNIQUE,
  sequence_id     BIGINT UNSIGNED NOT NULL UNIQUE,
  stratum         ENUM('lt60','ge60') NOT NULL,
  arm             ENUM('intervention','control') NOT NULL,
  checklist       JSON NOT NULL,
  randomised_by   BIGINT UNSIGNED NOT NULL,
  randomised_at   DATETIME(3) NOT NULL,
  signature_id    BIGINT UNSIGNED NULL,
  FOREIGN KEY (participant_id) REFERENCES participants(id),
  FOREIGN KEY (sequence_id) REFERENCES randomisation_sequence(id)
) ENGINE=InnoDB;

CREATE TABLE post_randomisation_tasks (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  task_code      VARCHAR(32) NOT NULL,     -- APP_ACCOUNT, WEARABLE_ISSUED, BP_MONITOR_ISSUED, CARE_PLAN_PUSHED, FU01_SCHEDULED
  done           TINYINT(1) NOT NULL DEFAULT 0,
  detail         VARCHAR(160) NULL,        -- device serial etc.
  done_by        BIGINT UNSIGNED NULL,
  done_at        DATETIME(3) NULL,
  UNIQUE KEY uq_prt (participant_id, task_code),
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. MOBILE APP: PROFILE, CAREGIVERS, DEVICES, ONBOARDING
-- ---------------------------------------------------------------------
CREATE TABLE app_profiles (
  participant_id   BIGINT UNSIGNED PRIMARY KEY,
  goal             ENUM('family','activity','strength','work','other') NULL,
  resting_hr_baseline SMALLINT UNSIGNED NULL,
  thr_low          SMALLINT UNSIGNED NULL,                  -- Karvonen, computed server-side (NULL = no zone: Phase I, NYHA IV, RHR ≥ 100 or missing)
  thr_high         SMALLINT UNSIGNED NULL,
  thr_method       VARCHAR(40) NULL,                        -- e.g. 'karvonen_220_age' | 'exercise_test' | 'clinician_override'
  thr_override_reason VARCHAR(255) NULL,
  thr_override_by  BIGINT UNSIGNED NULL,
  on_beta_blocker  TINYINT(1) NULL,                         -- RPE becomes the primary guide
  daily_step_goal  INT UNSIGNED NULL,
  fluid_mode       ENUM('target','limit') NOT NULL DEFAULT 'target', -- 'limit' for heart-failure participants
  fluid_target_ml  SMALLINT UNSIGNED NULL,                  -- set per participant; no global default
  ml_per_glass     SMALLINT UNSIGNED NOT NULL DEFAULT 250,
  onboarding_step  VARCHAR(32) NULL,
  onboarding_completed_at DATETIME(3) NULL,
  push_token       VARCHAR(255) NULL,
  push_platform    ENUM('fcm','apns') NULL,
  app_version      VARCHAR(16) NULL,
  updated_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

CREATE TABLE caregivers (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  relation       VARCHAR(40) NULL,
  name_enc       VARBINARY(512) NULL,
  email_enc      VARBINARY(512) NULL,
  phone_enc      VARBINARY(255) NULL,                       -- contact only; never used to sign in
  phone_bidx     CHAR(64) NULL,
  invite_status  ENUM('invited','active','revoked') NOT NULL DEFAULT 'invited', -- invited = case number issued, not yet signed in
  notify_on      SET('bp_sync','alerts','missed_meds','weekly_summary') NOT NULL DEFAULT 'weekly_summary',
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

-- Participant and caregiver sign-in uses a case number only: no OTP, no SMS.
-- Case number = 9 characters from 23456789ABCDEFGHJKMNPQRSTVWXYZ (no 0/O, 1/I/L, U),
-- 8 random (CSPRNG) + 1 Luhn mod 30 check character, printed as K7Q-M3X-PDX.
-- It is shown once, when issued; only HMAC-SHA256(server pepper, number) is stored.
-- One active case number per person; re-issue replaces it and signs out every device.
CREATE TABLE app_credentials (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id  BIGINT UNSIGNED NOT NULL,                -- the case the number belongs to
  subject_type    ENUM('participant','caregiver') NOT NULL,
  subject_id      BIGINT UNSIGNED NOT NULL,                -- participants.id or caregivers.id
  case_no_hmac    BINARY(32) NOT NULL,                     -- never the number itself
  pepper_key_id   TINYINT UNSIGNED NOT NULL DEFAULT 1,     -- which server key made the HMAC (key rotation)
  hint            CHAR(3) NOT NULL,                        -- last group, e.g. 'PDX': "ends in PDX" for staff
  version         SMALLINT UNSIGNED NOT NULL DEFAULT 1,    -- 1 = first issue, +1 for each re-issue
  status          ENUM('active','replaced','revoked') NOT NULL DEFAULT 'active',
  active_subject  VARCHAR(48) AS (IF(status = 'active', CONCAT(subject_type, ':', subject_id), NULL)) STORED,
  issued_by       BIGINT UNSIGNED NOT NULL,
  issued_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  issue_reason    VARCHAR(255) NULL,                       -- required on re-issue: lost card, new phone, suspected misuse
  first_login_at  DATETIME(3) NULL,
  last_login_at   DATETIME(3) NULL,
  ended_at        DATETIME(3) NULL,
  ended_by        BIGINT UNSIGNED NULL,
  end_reason      VARCHAR(255) NULL,                       -- replaced, withdrawn, caregiver removed, study completed
  UNIQUE KEY uq_cred_hmac (case_no_hmac),                  -- numbers are never reused, even after they end
  UNIQUE KEY uq_cred_active (active_subject),              -- at most one active number per person
  INDEX ix_cred_participant (participant_id, status),
  FOREIGN KEY (participant_id) REFERENCES participants(id),
  FOREIGN KEY (issued_by) REFERENCES users(id),
  FOREIGN KEY (ended_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Every case-number sign-in attempt, for throttling and attack detection.
-- 5 failures from one install ID or IP in 15 min → 15-min block (HTTP 429).
-- 50 failures across all clients in 10 min → alert to the on-call engineer.
CREATE TABLE app_login_attempts (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip             VARBINARY(16) NOT NULL,
  install_id     CHAR(36) NULL,
  succeeded      TINYINT(1) NOT NULL,
  failure        ENUM('malformed','unknown','ended','throttled') NULL,  -- internal only; the app always gets the same message
  credential_id  BIGINT UNSIGNED NULL,                     -- set on success only
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX ix_attempt_ip (ip, created_at),
  INDEX ix_attempt_install (install_id, created_at),
  INDEX ix_attempt_time (created_at)
) ENGINE=InnoDB;

CREATE TABLE devices (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  device_type    ENUM('wearable','bp_monitor','glucometer','scale') NOT NULL,
  make_model     VARCHAR(80) NOT NULL,                      -- Redmi Watch 3 Active / Omron HEM-7156T
  serial_no      VARCHAR(80) NULL,
  integration    ENUM('health_connect','healthkit','ble_direct','manual') NOT NULL,
  issued_at      DATETIME(3) NULL,
  paired_at      DATETIME(3) NULL,
  last_sync_at   DATETIME(3) NULL,
  returned_at    DATETIME(3) NULL,
  INDEX ix_dev_part (participant_id),
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

CREATE TABLE readiness_scores (                             -- app onboarding DHR / BRiDgE (if retained)
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  instrument_code VARCHAR(16) NOT NULL,
  score          SMALLINT UNSIGNED NOT NULL,
  max_score      SMALLINT UNSIGNED NOT NULL,
  band           VARCHAR(40) NOT NULL,
  answers        JSON NOT NULL,
  completed_at   DATETIME(3) NOT NULL,
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. REMOTE MONITORING DATA (app is source of truth)
-- ---------------------------------------------------------------------
CREATE TABLE vital_readings (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  kind           ENUM('bp','resting_hr','hr','hrv_sdnn','spo2','weight','glucose_fasting','glucose_post_meal','temperature') NOT NULL,
  value_1        DECIMAL(7,2) NOT NULL,                     -- SBP / HR / SpO2 / kg ...
  value_2        DECIMAL(7,2) NULL,                         -- DBP
  value_3        DECIMAL(7,2) NULL,                         -- pulse from BP cuff
  unit           VARCHAR(16) NOT NULL,
  measured_at    DATETIME(3) NOT NULL,
  source         ENUM('manual','ble_omron','health_connect','healthkit','clinic') NOT NULL,
  device_id      BIGINT UNSIGNED NULL,
  client_uuid    CHAR(36) NOT NULL UNIQUE,                  -- idempotent sync
  received_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX ix_vital (participant_id, kind, measured_at),
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

CREATE TABLE daily_activity (
  participant_id BIGINT UNSIGNED NOT NULL,
  activity_date  DATE NOT NULL,
  steps          INT UNSIGNED NULL,
  distance_m     INT UNSIGNED NULL,
  active_minutes SMALLINT UNSIGNED NULL,
  avg_mets       DECIMAL(4,1) NULL,
  wear_minutes   SMALLINT UNSIGNED NULL,                    -- for wear-time adherence
  source         ENUM('health_connect','healthkit','manual') NOT NULL,
  updated_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (participant_id, activity_date),
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

CREATE TABLE sleep_sessions (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  sleep_date     DATE NOT NULL,                             -- date of wake-up
  start_at       DATETIME(3) NOT NULL,
  end_at         DATETIME(3) NOT NULL,
  total_min      SMALLINT UNSIGNED NOT NULL,
  deep_min SMALLINT UNSIGNED NULL, light_min SMALLINT UNSIGNED NULL, rem_min SMALLINT UNSIGNED NULL, awake_min SMALLINT UNSIGNED NULL,
  source         ENUM('health_connect','healthkit','manual') NOT NULL,
  client_uuid    CHAR(36) NOT NULL UNIQUE,
  INDEX ix_sleep (participant_id, sleep_date),
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

CREATE TABLE exercise_sessions (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id  BIGINT UNSIGNED NOT NULL,
  session_type    ENUM('aerobic','resistance','breathing','flexibility','other') NOT NULL,
  started_at      DATETIME(3) NOT NULL,
  duration_min    SMALLINT UNSIGNED NOT NULL,
  avg_hr          SMALLINT UNSIGNED NULL,
  max_hr          SMALLINT UNSIGNED NULL,
  pct_in_zone     TINYINT UNSIGNED NULL,
  zone_low        SMALLINT UNSIGNED NULL,
  zone_high       SMALLINT UNSIGNED NULL,
  rpe             TINYINT UNSIGNED NULL,                    -- store on Borg 6–20; app 1–5 scale mapped server-side
  rpe_scale       ENUM('borg_6_20','cr10','simple_1_5') NOT NULL DEFAULT 'borg_6_20', -- app must collect Borg 6–20
  pain_vas        TINYINT UNSIGNED NULL,                    -- 0–10
  care_plan_id    BIGINT UNSIGNED NULL,
  source          ENUM('app_timer','health_connect','healthkit','manual') NOT NULL,
  client_uuid     CHAR(36) NOT NULL UNIQUE,
  INDEX ix_ex (participant_id, started_at),
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

CREATE TABLE symptom_logs (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  log_date       DATE NOT NULL,
  chest_pain     TINYINT UNSIGNED NOT NULL DEFAULT 0,       -- 0 none … 3 severe
  breathlessness TINYINT UNSIGNED NOT NULL DEFAULT 0,
  palpitations   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  dizziness      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ankle_swelling TINYINT UNSIGNED NOT NULL DEFAULT 0,
  fatigue        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  fever          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  cough          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  note           VARCHAR(500) NULL,
  client_uuid    CHAR(36) NOT NULL UNIQUE,
  logged_at      DATETIME(3) NOT NULL,
  UNIQUE KEY uq_sym_day (participant_id, log_date),
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

CREATE TABLE medication_doses (                             -- adherence events
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  medication_id  BIGINT UNSIGNED NOT NULL,
  dose_date      DATE NOT NULL,
  slot           ENUM('morning','afternoon','evening','night') NOT NULL,
  status         ENUM('taken','skipped','missed') NOT NULL,
  recorded_at    DATETIME(3) NOT NULL,
  client_uuid    CHAR(36) NOT NULL UNIQUE,
  UNIQUE KEY uq_dose (medication_id, dose_date, slot),
  INDEX ix_dose_part (participant_id, dose_date),
  FOREIGN KEY (participant_id) REFERENCES participants(id),
  FOREIGN KEY (medication_id) REFERENCES participant_medications(id)
) ENGINE=InnoDB;

CREATE TABLE daily_logs (                                   -- checklist, fluids, meals, nutrition adherence
  participant_id BIGINT UNSIGNED NOT NULL,
  log_date       DATE NOT NULL,
  tasks_done     JSON NULL,                                 -- {"bp_check":true,"walk_20":false,...}
  fluid_glasses  TINYINT UNSIGNED NULL,
  meals          JSON NULL,                                 -- {"breakfast":true,"lunch":true,...}
  nutrition_adherence JSON NULL,                            -- {"salt":true,"fluids":false,...}
  note           VARCHAR(1000) NULL,
  updated_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (participant_id, log_date),
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

CREATE TABLE engagement_daily (                             -- DHI Adherence Meter inputs (nightly job)
  participant_id BIGINT UNSIGNED NOT NULL,
  metric_date    DATE NOT NULL,
  app_opens      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  wearable_wear_pct TINYINT UNSIGNED NULL,
  self_report_entries SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  edu_minutes    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  caregiver_views SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (participant_id, metric_date),
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. CARE PLANS · EDUCATION · TELECONSULTS
-- ---------------------------------------------------------------------
CREATE TABLE care_plan_catalog (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  domain      ENUM('exercise','nutrition','medication','tobacco','psychosocial','sleep','education','teleconsult','escalation') NOT NULL,
  title_en    VARCHAR(200) NOT NULL,
  title_ta    VARCHAR(300) NOT NULL,
  body_en     TEXT NULL,
  body_ta     TEXT NULL,
  phases      SET('1','2','3','4') NULL,                    -- NULL = all phases
  is_active   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE care_plans (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  catalog_id     BIGINT UNSIGNED NOT NULL,
  priority       ENUM('routine','priority','urgent') NOT NULL DEFAULT 'routine',
  clinician_note VARCHAR(500) NULL,
  phase_at_push  TINYINT UNSIGNED NOT NULL,
  pushed_by      BIGINT UNSIGNED NOT NULL,
  pushed_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  delivered_at   DATETIME(3) NULL,                          -- app fetched it
  read_at        DATETIME(3) NULL,                          -- participant opened card
  acknowledged_by BIGINT UNSIGNED NULL,                     -- clinician ack (urgent alerts)
  acknowledged_at DATETIME(3) NULL,
  status         ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
  INDEX ix_cp_part (participant_id, status),
  FOREIGN KEY (participant_id) REFERENCES participants(id),
  FOREIGN KEY (catalog_id) REFERENCES care_plan_catalog(id),
  FOREIGN KEY (pushed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE education_modules (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(8) NOT NULL UNIQUE,                   -- h1, m2 ...
  category    ENUM('heart','meds','activity','nutrition','tobacco','mind','monitor','family') NOT NULL,
  title_en    VARCHAR(160) NOT NULL,
  title_ta    VARCHAR(240) NOT NULL,
  format      ENUM('video','article','interactive') NOT NULL,
  minutes     TINYINT UNSIGNED NOT NULL,
  phases      SET('1','2','3','4') NULL,
  content_url_en VARCHAR(500) NULL,
  content_url_ta VARCHAR(500) NULL,
  version     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  is_active   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE education_progress (
  participant_id BIGINT UNSIGNED NOT NULL,
  module_id      BIGINT UNSIGNED NOT NULL,
  status         ENUM('assigned','in_progress','completed') NOT NULL,
  assigned_by    BIGINT UNSIGNED NULL,
  assigned_at    DATETIME(3) NULL,
  started_at     DATETIME(3) NULL,
  completed_at   DATETIME(3) NULL,
  seconds_spent  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (participant_id, module_id),
  FOREIGN KEY (participant_id) REFERENCES participants(id),
  FOREIGN KEY (module_id) REFERENCES education_modules(id)
) ENGINE=InnoDB;

CREATE TABLE teleconsults (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  consult_type   ENUM('cardiologist','cr_nurse','physiotherapy','dietitian','counsellor') NOT NULL,
  scheduled_at   DATETIME(3) NOT NULL,
  status         ENUM('scheduled','attended','missed','cancelled') NOT NULL DEFAULT 'scheduled',
  staff_id       BIGINT UNSIGNED NULL,
  notes          TEXT NULL,
  care_plan_id   BIGINT UNSIGNED NULL,
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 10. ALERTS ENGINE
-- ---------------------------------------------------------------------
CREATE TABLE alert_rules (
  id          SMALLINT UNSIGNED PRIMARY KEY,
  code        VARCHAR(32) NOT NULL UNIQUE,     -- SBP_GT_160, RHR_GT_100, STEP_DECLINE_50, SYMPTOM_CLUSTER, PHQ9_ITEM9, PHQ9_GE10, GAD7_GE10, DAPT_ADH_LT70, NO_SYNC_72H
  description VARCHAR(255) NOT NULL,
  severity    ENUM('info','warning','critical') NOT NULL,
  params      JSON NOT NULL,                   -- {"threshold":160,"consecutive_days":1}
  is_active   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE alerts (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  rule_id        SMALLINT UNSIGNED NULL,
  source         ENUM('rule','care_plan','manual') NOT NULL DEFAULT 'rule',
  severity       ENUM('info','warning','critical') NOT NULL,
  message        VARCHAR(500) NOT NULL,
  evidence       JSON NULL,                    -- the readings that triggered it
  dedupe_key     VARCHAR(80) NOT NULL,         -- rule+participant+date → one open alert at a time
  status         ENUM('open','acknowledged','resolved','auto_cleared') NOT NULL DEFAULT 'open',
  acknowledged_by BIGINT UNSIGNED NULL,
  acknowledged_at DATETIME(3) NULL,
  resolution_note VARCHAR(500) NULL,
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_alert_dedupe (dedupe_key),
  INDEX ix_alert_open (status, severity, created_at),
  FOREIGN KEY (participant_id) REFERENCES participants(id),
  FOREIGN KEY (rule_id) REFERENCES alert_rules(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 11. DATA MANAGEMENT · E-SIGNATURES · AUDIT · FILES · JOBS
-- ---------------------------------------------------------------------
CREATE TABLE data_queries (                                  -- DM queries raised against a field
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id  BIGINT UNSIGNED NOT NULL,
  form_instance_id BIGINT UNSIGNED NULL,
  table_name      VARCHAR(64) NOT NULL,
  record_id       BIGINT UNSIGNED NOT NULL,
  field_name      VARCHAR(64) NOT NULL,
  query_text      VARCHAR(1000) NOT NULL,
  status          ENUM('open','answered','closed','cancelled') NOT NULL DEFAULT 'open',
  raised_by       BIGINT UNSIGNED NOT NULL,
  raised_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  response_text   VARCHAR(1000) NULL,
  responded_by    BIGINT UNSIGNED NULL,
  responded_at    DATETIME(3) NULL,
  closed_by       BIGINT UNSIGNED NULL,
  closed_at       DATETIME(3) NULL,
  INDEX ix_dq_status (status),
  FOREIGN KEY (participant_id) REFERENCES participants(id)
) ENGINE=InnoDB;

CREATE TABLE e_signatures (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  meaning       ENUM('eligibility_confirmed','pi_declaration','safety_clearance','randomisation','form_signoff','baseline_complete','data_lock') NOT NULL,
  entity_table  VARCHAR(64) NOT NULL,
  entity_id     BIGINT UNSIGNED NOT NULL,
  content_hash  CHAR(64) NOT NULL,              -- sha256 of the signed record snapshot
  signed_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  ip            VARBINARY(16) NULL,
  INDEX ix_sig_entity (entity_table, entity_id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE audit_log (                                     -- append-only, hash-chained
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  occurred_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  actor_type    ENUM('user','participant','caregiver','system') NOT NULL,
  actor_id      BIGINT UNSIGNED NULL,
  action        ENUM('create','update','delete','view','login','logout','login_failed','export','sign','randomise','unlock') NOT NULL,
  entity_table  VARCHAR(64) NULL,
  entity_id     BIGINT UNSIGNED NULL,
  participant_id BIGINT UNSIGNED NULL,
  old_values    JSON NULL,
  new_values    JSON NULL,
  reason        VARCHAR(500) NULL,             -- mandatory for updates after form 'complete'
  request_id    CHAR(36) NULL,
  ip            VARBINARY(16) NULL,
  user_agent    VARCHAR(255) NULL,
  prev_hash     CHAR(64) NULL,
  row_hash      CHAR(64) NOT NULL,             -- sha256(prev_hash || canonical row)
  INDEX ix_audit_entity (entity_table, entity_id),
  INDEX ix_audit_part (participant_id, occurred_at),
  INDEX ix_audit_actor (actor_type, actor_id, occurred_at)
) ENGINE=InnoDB;

DELIMITER $$
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_log FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'; END$$
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_log FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'; END$$
DELIMITER ;

CREATE TABLE files (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_type    ENUM('participant','user','system') NOT NULL,
  owner_id      BIGINT UNSIGNED NULL,
  participant_id BIGINT UNSIGNED NULL,
  purpose       ENUM('consent_signature','consent_scan','consent_av','discharge_summary','diet_plan','lab_report','echo_report','education_asset','export','other') NOT NULL,
  storage_key   VARCHAR(255) NOT NULL,          -- path in encrypted object store, never public
  mime_type     VARCHAR(80) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL,
  sha256        CHAR(64) NOT NULL,
  ocr_status    ENUM('none','pending','done','failed') NOT NULL DEFAULT 'none',
  ocr_result    JSON NULL,
  uploaded_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX ix_files_part (participant_id, purpose)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipient_type ENUM('participant','caregiver','user') NOT NULL,
  recipient_id  BIGINT UNSIGNED NOT NULL,
  channel       ENUM('push','email','in_app') NOT NULL,     -- no SMS: sign-in has no OTP and nothing else needs it
  template_code VARCHAR(48) NOT NULL,
  payload       JSON NOT NULL,
  status        ENUM('queued','sent','failed','read') NOT NULL DEFAULT 'queued',
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  send_after    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  sent_at       DATETIME(3) NULL,
  read_at       DATETIME(3) NULL,
  error         VARCHAR(255) NULL,
  INDEX ix_notif_queue (status, send_after),
  INDEX ix_notif_recipient (recipient_type, recipient_id, status)
) ENGINE=InnoDB;

CREATE TABLE jobs (                                          -- simple DB-backed queue for cron workers
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  queue        VARCHAR(32) NOT NULL,           -- alerts, scoring, notifications, ocr, exports
  payload      JSON NOT NULL,
  status       ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  run_after    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  locked_by    VARCHAR(64) NULL,
  locked_at    DATETIME(3) NULL,
  last_error   TEXT NULL,
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX ix_jobs_pick (queue, status, run_after)
) ENGINE=InnoDB;

CREATE TABLE idempotency_keys (
  key_hash     CHAR(64) PRIMARY KEY,
  subject      VARCHAR(48) NOT NULL,
  response_code SMALLINT UNSIGNED NOT NULL,
  response_body MEDIUMTEXT NOT NULL,
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB;

-- Versioned thresholds and parameters for every calculation engine (calc spec "Ground rules").
-- A draft becomes active only with PI and statistician approval; results store the config_id they used.
CREATE TABLE scoring_configs (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  engine          VARCHAR(24) NOT NULL,          -- ELIG, PHQ9, GAD7, DASI, EQ5D5L, CCSPS, PHASE, THR, ALERTS, ADHERENCE, MONITORING
  version         VARCHAR(16) NOT NULL,          -- e.g. 1.0
  params          JSON NOT NULL,
  status          ENUM('draft','active','retired') NOT NULL DEFAULT 'draft',
  approved_by_pi  BIGINT UNSIGNED NULL,
  approved_by_statistician BIGINT UNSIGNED NULL,
  effective_from  DATE NULL,
  change_reason   VARCHAR(500) NOT NULL,
  created_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_engine_version (engine, version)
) ENGINE=InnoDB;

CREATE TABLE app_settings (
  setting_key  VARCHAR(64) PRIMARY KEY,
  setting_value JSON NOT NULL,
  updated_by   BIGINT UNSIGNED NULL,
  updated_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 12. REPORTING VIEWS
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_participant_phase AS
SELECT p.id AS participant_id, p.study_id, p.pci_date,
       -- IST calendar date, not UTC_DATE() (which is still "yesterday" until 05:30 IST)
       DATEDIFF(DATE(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+05:30')), p.pci_date) AS days_post_pci,
       CASE WHEN p.pci_date IS NULL THEN NULL
            WHEN DATEDIFF(DATE(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+05:30')), p.pci_date) <= 7  THEN 1   -- Phase I: day 0–7
            WHEN DATEDIFF(DATE(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+05:30')), p.pci_date) <= 30 THEN 2   -- Phase II: day 8–30
            WHEN DATEDIFF(DATE(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+05:30')), p.pci_date) <= 90 THEN 3   -- Phase III: day 31–90
            ELSE 4 END AS rehab_phase
FROM participants p;

CREATE OR REPLACE VIEW v_dashboard_counts AS
SELECT site_id,
  SUM(status='screening')                         AS screening_pending,
  SUM(status='eligible')                          AS eligible_awaiting_baseline,
  SUM(status='screen_failed')                     AS screen_failures,
  SUM(status IN ('baseline','safety_cleared'))    AS in_baseline,
  SUM(status='randomised')                        AS randomised,
  SUM(status='randomised' AND arm='intervention') AS randomised_intervention,
  SUM(status='randomised' AND arm='control')      AS randomised_control
FROM participants GROUP BY site_id;

-- ---------------------------------------------------------------------
-- 13. SEED / REFERENCE DATA
-- ---------------------------------------------------------------------
INSERT INTO roles (id, code, label) VALUES
 (1,'admin','System Administrator'),(2,'pi','Principal Investigator'),(3,'sub_investigator','Sub-Investigator'),
 (4,'assessor','Outcome Assessor (blinded)'),(5,'clinician','Clinician'),(6,'care_coach','Care Coach / CR Nurse'),
 (7,'data_manager','Data Manager'),(8,'statistician','Independent Statistician'),(9,'monitor','Study Monitor (read-only)');

INSERT INTO visit_definitions (id, code, label, anchor, day_offset, window_before, window_after, sort_order) VALUES
 (1,'SCR','Screening','registration',0,0,7,1),
 (2,'BL','Baseline (Day 0)','randomisation',0,7,0,2),
 (3,'D14','Day 14 minimal check','randomisation',14,2,3,3),
 (4,'D30','Day 30','randomisation',30,5,5,4),
 (5,'D60','Day 60','randomisation',60,5,5,5),
 (6,'D90','Day 90 (primary endpoint)','randomisation',90,7,7,6),
 (7,'D180','Day 180','randomisation',180,14,14,7);

-- Schedule as stated in the eCRF App Integration Map (confirm against protocol)
INSERT INTO visit_form_matrix (visit_def_id, form_code, delivery, arm_scope) VALUES
 (1,'REG01','ecrf','all'),(1,'SCR01','ecrf','all'),
 (2,'BL01','ecrf','all'),(2,'DHRX','either','all'),(2,'PHQ9','either','all'),(2,'GAD7','either','all'),
 (2,'EQ5D5L','either','all'),(2,'DASI','either','all'),(2,'SIXMWT','ecrf','all'),(2,'CCSPS','ecrf','all'),
 (2,'SAF01','ecrf','all'),(2,'RAND01','ecrf','all'),
 (3,'FU01','either','all'),
 (4,'PHQ9','either','all'),(4,'GAD7','either','all'),(4,'CCSPS','ecrf','all'),
 (5,'PHQ9','either','all'),(5,'GAD7','either','all'),(5,'CCSPS','ecrf','all'),
 (6,'PHQ9','either','all'),(6,'GAD7','either','all'),(6,'EQ5D5L','either','all'),(6,'DASI','either','all'),
 (6,'SIXMWT','ecrf','all'),(6,'CCSPS','ecrf','all'),
 (7,'PHQ9','either','all'),(7,'GAD7','either','all'),(7,'EQ5D5L','either','all'),(7,'DASI','either','all'),(7,'CCSPS','ecrf','all');

INSERT INTO lab_tests (code,label,canonical_unit,alt_unit,alt_to_canonical_factor,alt_to_canonical_offset,min_plausible,max_plausible) VALUES
 ('HBA1C','HbA1c','%','mmol/mol',0.09148,2.152,3.5,18),          -- NGSP master equation
 ('LDL','LDL-Cholesterol','mg/dL','mmol/L',38.67,0,10,400),
 ('TC','Total Cholesterol','mg/dL','mmol/L',38.67,0,50,500),
 ('HDL','HDL-Cholesterol','mg/dL','mmol/L',38.67,0,10,150),
 ('TG','Triglycerides','mg/dL','mmol/L',88.57,0,20,2000),
 ('CREAT','Creatinine','mg/dL','µmol/L',0.011310,0,0.2,15),       -- ÷ 88.42
 ('EGFR','eGFR','mL/min/1.73m²',NULL,NULL,0,5,150),
 ('FBG','Fasting glucose','mg/dL','mmol/L',18.016,0,40,600),
 ('PPBG','Post-meal glucose','mg/dL','mmol/L',18.016,0,40,700);

INSERT INTO instruments (code,name,version,recall_period,show_score_to_participant,licence_ref) VALUES
 ('PHQ9','Patient Health Questionnaire-9','1.0','2 weeks',0,'Pfizer — free to use'),
 ('GAD7','Generalised Anxiety Disorder-7','1.0','2 weeks',0,'Pfizer — free to use'),
 ('EQ5D5L','EQ-5D-5L','Tamil/English','today',0,'EuroQol registration + digital licence required'),
 ('DASI','Duke Activity Status Index','1.0','current',0,NULL),
 ('DHRX','Digital Health Readiness','study','current',0,'Confirm source/licence'),
 ('BRIDGE','BRiDgE Engagement Readiness','study','current',0,'Confirm source/licence'),
 ('RYP_INDIA','Rate Your Plate – India','study','typical week',0,'Confirm source/licence');

INSERT INTO alert_rules (id,code,description,severity,params) VALUES
 (1,'SBP_GT_160','Home SBP reading > 160 mmHg (PROPOSED: confirmed by a repeat within 15 min)','critical','{"threshold":160,"confirm_repeat_within_min":15,"confirm_required":false}'),
 (2,'RHR_GT_100','Daily resting HR > 100 bpm on each of the last 3 complete days','critical','{"threshold":100,"consecutive_days":3}'),
 (3,'STEP_DECLINE_50','(prior-7-day mean − last-7-day mean) ÷ prior mean × 100 > 50; valid days only (wear ≥ 600 min); 14 complete days','warning','{"pct":50,"window_days":14,"min_wear_min":600,"min_valid_days_each_half":4}'),
 (4,'SYMPTOM_CLUSTER','≥ 2 of chest pain / breathlessness / ankle swelling ≥ 2 (moderate) on ≥ 2 of the last 7 logged days; critical if any is 3','warning','{"symptoms":["chest_pain","breathlessness","ankle_swelling"],"min_symptoms":2,"min_days":2,"window":7,"min_severity":2,"critical_if_severity":3}'),
 (5,'PHQ9_ITEM9','PHQ-9 item 9 > 0 at any total — same-day safety call; never auto-clears','critical','{"auto_clear":false}'),
 (6,'PHQ9_GE10','PHQ-9 total ≥ 10 — clinical review','warning','{"threshold":10}'),
 (7,'GAD7_GE10','GAD-7 total ≥ 10 — clinical review','warning','{"threshold":10}'),
 (8,'DAPT_ADH_LT70','Any DAPT drug (aspirin or P2Y12 inhibitor) adherence < 70 % over 7 days','critical','{"threshold":70,"window_days":7}'),
 (9,'NO_SYNC_72H','No wearable or app data received for 72 h','info','{"hours":72}'),
 (10,'RED_FLAG_SYMPTOM','PROPOSED: chest pain ≥ 2, or any symptom = 3, on a single day — same-day call','critical','{"chest_pain_min":2,"any_symptom_min":3}'),
 (11,'EXERCISE_ABOVE_ZONE','PROPOSED: session average HR > zone high + 10 bpm, or Borg RPE ≥ 15','warning','{"hr_margin":10,"rpe_borg_min":15}'),
 (12,'WEIGHT_GAIN','PROPOSED (heart-failure risk): weight gain > 2 kg within 3 days','warning','{"kg":2.0,"days":3}');

-- Draft engine parameters: every PROPOSED value from the calculation spec, awaiting PI + statistician approval.
INSERT INTO scoring_configs (engine,version,params,status,change_reason) VALUES
 ('ELIG','1.0','{"age_min":18,"pci_max_days":30,"lvef_min":40,"egfr_min":45,"sbp_max_exclusive":160,"dbp_max_exclusive":100,"unknown_holds_pending":true,"stop_at_first_exclusion":true,"egfr_consistency_tolerance_pct":15}','draft','Initial draft from calculation spec'),
 ('PHQ9','1.0','{"bands":[[0,4,"minimal"],[5,9,"mild"],[10,14,"moderate"],[15,19,"moderately_severe"],[20,27,"severe"]],"review_threshold":10,"prorate_max_missing":0}','draft','Initial draft from calculation spec'),
 ('GAD7','1.0','{"bands":[[0,4,"minimal"],[5,9,"mild"],[10,14,"moderate"],[15,21,"severe"]],"review_threshold":10,"prorate_max_missing":0}','draft','Initial draft from calculation spec'),
 ('DASI','1.0','{"weights":[2.75,1.75,2.75,5.5,8,2.7,3.5,8,4.5,5.25,6,7.5],"vo2_slope":0.43,"vo2_intercept":9.6,"mets_divisor":3.5,"all_items_required":true}','draft','Initial draft from calculation spec'),
 ('CCSPS','1.0','{"domains":["bp","ldl","hba1c","diet","physical_activity","smoking","sleep","bmi","mental_health","medication_adherence"],"mental_health":{"rule":"worst_of_phq9_gad7","both_minimal":2,"worst_mild":1,"worst_moderate_plus":0},"other_domain_thresholds":null,"normalisation":null,"min_domains_proportional":8}','draft','Thresholds for 9 domains still to come from CCSPS_Specification'),
 ('PHASE','1.0','{"anchor":"pci_date","phase_max_day":{"1":7,"2":30,"3":90},"timezone":"Asia/Kolkata"}','draft','Initial draft from calculation spec'),
 ('THR','1.0','{"hrmax":"220_minus_age","resting_hr":"7_day_mean","intensity_pct_hrr":{"I":{"2":[45,54],"3":[60,69],"4":[80,89]},"II":{"2":[40,45],"3":[55,60],"4":[70,80]},"III":{"2":[35,40],"3":[45,54],"4":[45,54]},"IV":{}},"no_zone_if_resting_hr_at_least":100,"beta_blocker_primary_guide":"rpe_borg_11_13"}','draft','Intensity table copied from app prototype; needs clinical sign-off'),
 ('ADHERENCE','1.0','{"dose_grace_min":120,"windows_days":[7,30],"weighting":null,"dhi_subdomains":["app_usage","wearable_use","self_report","teleconsult","education","caregiver","symptom_med_reporting"]}','draft','Initial draft from calculation spec'),
 ('MONITORING','1.0','{"valid_day_min_wear_min":600,"min_valid_days":4,"home_bp_min_readings":6,"trend_min_change":{"sbp":5,"dbp":5,"resting_hr":5,"weight":1,"spo2":2,"hrv_sdnn":5},"sleep_bands_h":{"ideal_min":7,"short_min":6},"steps_below_goal_fraction":0.6}','draft','Initial draft from calculation spec');

-- App sign-in policy (case number only; no OTP, no SMS). Editable in Admin › Settings; changes are audited.
INSERT INTO app_settings (setting_key, setting_value) VALUES
 ('app_signin', '{"case_number":{"alphabet":"23456789ABCDEFGHJKMNPQRSTVWXYZ","random_length":8,"check":"luhn_mod_30","display":"XXX-XXX-XXX"},"throttle":{"max_failures":5,"window_min":15,"block_min":15},"attack_alert":{"failures":50,"window_min":10},"tokens":{"participant_access_min":30,"caregiver_access_min":30,"refresh_days_participant":90,"refresh_days_caregiver":30},"one_device_per_person":true,"issue_roles":["pi","sub_investigator","care_coach"]}');
