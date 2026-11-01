# SMBind 1.0

**Authoritative DNS management — rebuilt for modern PHP.**

**Current state: 1.0.0-dev.1 (development; not production qualified).** The historical code has been removed from the working tree, but the replacement still requires end-to-end DNS, database, and browser verification. Track the complete per-file replacement ledger in [Rebuild inventory](docs/REBUILD_INVENTORY.md).

> [!IMPORTANT]
> **A clean-slate rebuild.** This is a new application targeting PHP 8.2+, PDO MySQL, Tailwind CSS 4, and BIND 9. It does not read or migrate the historical SMBind tables or deployment files.

## Features

- Responsive Tailwind CSS operations console with clear navigation, searchable-in-browser zone tables, and keyboard-accessible forms.
- DNS zone configuration and editing, including SOA parameters and A, AAAA, CNAME, MX, TXT, NS, PTR and SRV records.
- PDO prepared statements with MySQL 8 or compatible MariaDB.
- Administrator/editor/viewer roles and per-zone ownership.
- Password hashing, session regeneration, CSRF defenses, and login throttling.
- Two-stage publishing: validate every zone with named-checkzone, replace zone files atomically, validate BIND configuration, then rndc reload. Original files are restored on failure.
- Activity audit, team management, and password changes.

**Not included:** legacy database migration, DNSSEC signing, authoritative secondary replication, SSO, multi-tenant billing, and a REST API. This development build requires end-to-end qualification on an isolated BIND installation before production use.

## Minimum requirements

| Component | Requirements |
| --- | --- |
| PHP | 8.2+ with pdo_mysql, sessions, filter |
| Database | MySQL 8.0+ or compatible MariaDB |
| DNS | BIND 9; named-checkzone, named-checkconf, rndc |
| UI assets | Node.js 20+, Tailwind CSS 4.3.3 |
| HTTP server | Nginx or Apache, document root **public/** only |

> [!CAUTION]
> This software changes authoritative DNS configuration. Restrict access to trusted networks or a VPN. Never expose the source, database schema, secrets, or DNS output within the web document root. Do not grant the PHP worker root access or unrestricted sudo.

## Fresh-install procedure

1. Create a **new empty** database and a limited database account. Use a long secret from your secret manager:

   ```sql
   CREATE DATABASE smbind CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'smbind_app'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_A_LONG_RANDOM_SECRET';
   GRANT SELECT,INSERT,UPDATE,DELETE ON smbind.* TO 'smbind_app'@'127.0.0.1';
   ```

2. Apply the schema as an administrative database user:

   ```bash
   mysql -u root -p smbind < db/schema.sql
   ```

3. Export all required settings from `.env.example` through PHP-FPM or the service manager (the application reads the process environment, not dotenv files). Configure HTTPS and `SMBIND_SECURE_COOKIES=1`. Never place secrets in `public/`.

4. Create the initial admin without putting a password on the command line:

   ```bash
   read -rs -p 'Initial SMBind password: ' SMBIND_INITIAL_PASSWORD; printf '\n'
   printf '%s\n' "$SMBIND_INITIAL_PASSWORD" | php bin/create-admin.php admin
   unset SMBIND_INITIAL_PASSWORD
   ```

5. Compile the production Tailwind asset:

   ```bash
   npm install --no-audit --no-fund
   npm run build
   ```

6. Set the HTTP server document root to `public/`. For local **development only**, use `php -S 127.0.0.1:8080 -t public`.

7. Create `SMBIND_ZONE_DIR` outside the web root. Permit the PHP publishing identity to atomically replace files there and to replace `SMBIND_INCLUDE_FILE` (also outside the web root). Set up tightly scoped access to RNDC keys. Never give the web worker unrestricted root/sudo permissions. **Review permissions on every deployment.** The include file must exist and be readable by BIND. Add `include "/etc/bind/smbind-zones.conf";` to the correct BIND `named.conf` once, substituting your configured absolute path. Do not point SMBind at a configuration file shared with manually managed zones.

8. Sign in. Create zones, add records, and select **Validate & publish DNS**. SMBind only changes DNS serving after the explicit publication action.

## Permissions

| Role | Capabilities |
| --- | --- |
| Administrator | Manage all zones/users, publish zones, audit history |
| Editor | Modify only assigned zones, no publishing privileges |
| Viewer | Read zones and records |

## Validation

```bash
find src public bin tests -name '*.php' -type f -exec php -l {} \;
php tests/run.php
# Disposable database/BIND validation integration runs in GitHub CI.
npm run build
```

GitHub CI lints on PHP 8.2–8.5, runs deterministic DNS validation tests, and compiles the UI. An additional disposable MySQL 8.4 job uses real BIND zone/configuration validators while simulating RNDC success/failure to test publishing, file rollback, and deleted-zone cleanup. These checks do **not** replace a live authoritative DNS reload test, real browser/a11y testing, production permission testing, or install/upgrade qualification.

## Design

The console adopts an **airy, precise, dependable** visual identity inspired by modern rail-control rooms: ivory `#F6F6F1`, evergreen `#203B32`, action green `#11744E`, and terracotta `#B6543D`. An accessible desktop rail collapses into mobile navigation; operations tables and typographic hierarchy take priority over decorative cards. Inter/system sans, IBM Plex Mono/system mono, 170ms transitions, and reduced-motion compliance.

## Heritage and license

The original Simple Management for BIND project was created by **Ole Myhre** and **Ben Lentz**, with contributions from other developers. The original Git history and author credits remain preserved. The original `COPYING` license text was transferred verbatim to [`LICENSE`](LICENSE) while the historical source remains accessible in earlier commits. The PHP 5-era executable files are not part of the new application.
