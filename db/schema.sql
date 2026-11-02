-- SMBind 1.0 clean-install schema (MySQL 8.0+ / compatible MariaDB).
-- Creates a NEW empty schema. There is no importer or legacy migration path.
CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'editor', 'viewer') NOT NULL DEFAULT 'viewer',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE zones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(253) NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    primary_ns VARCHAR(253) NOT NULL,
    contact VARCHAR(253) NOT NULL,
    default_ttl INT UNSIGNED NOT NULL DEFAULT 3600,
    refresh INT UNSIGNED NOT NULL DEFAULT 10800,
    retry INT UNSIGNED NOT NULL DEFAULT 3600,
    expire INT UNSIGNED NOT NULL DEFAULT 604800,
    minimum INT UNSIGNED NOT NULL DEFAULT 3600,
    serial BIGINT UNSIGNED NOT NULL DEFAULT 1,
    dirty TINYINT(1) NOT NULL DEFAULT 1,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_zones_name (name),
    KEY ix_zones_owner (owner_id),
    CONSTRAINT fk_zones_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    zone_id BIGINT UNSIGNED NOT NULL,
    host VARCHAR(253) NOT NULL,
    type ENUM('A','AAAA','CAA','CNAME','DS','MX','SSHFP','TLSA','TXT','NS','PTR','SRV') NOT NULL,
    value TEXT NOT NULL,
    ttl INT UNSIGNED NOT NULL DEFAULT 3600,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    weight SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    port SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_records_zone_host (zone_id, host),
    CONSTRAINT fk_records_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE published_zones (
    name VARCHAR(253) NOT NULL PRIMARY KEY,
    published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    zone_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    detail JSON NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_audit_created (created_at),
    KEY ix_audit_zone (zone_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
