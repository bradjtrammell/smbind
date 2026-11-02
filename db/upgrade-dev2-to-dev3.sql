-- SMBind v1.0.0-dev.2 -> dev.3 schema change.
-- For existing installs of the new SMBind 1.0 series only.
-- Historical PHP 5 SMBind database migration remains unsupported.
-- Take a verified database backup before applying this upgrade.
ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;
