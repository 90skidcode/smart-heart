-- =====================================================================
-- 0002 · Sign-in: staff lockout and MFA, session listing, permissions,
-- and a serialised head for the hash-chained audit log.
-- =====================================================================

-- Staff lockout ("5 failures → locked 15 min") needs an end time; MFA needs a pending secret
-- until the first code is confirmed, and the last used TOTP step so a code cannot be replayed.
ALTER TABLE users
  ADD COLUMN locked_until DATETIME(3) NULL AFTER failed_logins,
  ADD COLUMN mfa_pending_secret_enc VARBINARY(255) NULL AFTER mfa_enabled,
  ADD COLUMN mfa_last_step BIGINT UNSIGNED NULL AFTER mfa_pending_secret_enc;

-- One-time codes for staff who lose their authenticator. Only a SHA-256 hash is stored.
CREATE TABLE mfa_recovery_codes (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  code_hash  CHAR(64) NOT NULL,
  used_at    DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_recovery (user_id, code_hash),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Blinded roles never see allocation, or anything that reveals it (such as a case number).
ALTER TABLE roles ADD COLUMN is_blinded TINYINT(1) NOT NULL DEFAULT 0;
UPDATE roles SET is_blinded = 1 WHERE code = 'assessor';

-- Sessions: shown to staff per phone; checked on every request by family.
ALTER TABLE auth_refresh_tokens
  ADD COLUMN app_version VARCHAR(16) NULL AFTER device_label,
  ADD INDEX ix_rt_family (family_id);

-- Permissions. Only the ones the contract already assigns are seeded here; each later module adds its own.
-- App access: issue / re-issue / revoke case numbers (PI, sub-investigator, care coach — app_settings.app_signin.issue_roles).
INSERT INTO permissions (id, code) VALUES
 (1, 'app_access.view'),
 (2, 'app_access.issue');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('app_access.view', 'app_access.issue')
WHERE r.code IN ('pi', 'sub_investigator', 'care_coach');

-- audit_log is hash-chained. Each insert locks this row (SELECT … FOR UPDATE) inside its transaction,
-- so two requests can never chain onto the same previous hash.
CREATE TABLE audit_chain_head (
  id        TINYINT UNSIGNED PRIMARY KEY,
  last_id   BIGINT UNSIGNED NULL,
  last_hash CHAR(64) NOT NULL
) ENGINE=InnoDB;
INSERT INTO audit_chain_head (id, last_id, last_hash) VALUES (1, NULL, REPEAT('0', 64));
