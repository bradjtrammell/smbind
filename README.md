# SMBind 1.0

**Authoritative DNS management — rebuilt for modern PHP.**

**Current state: 1.0.0-dev.3 on `dev` (development; not production qualified).** `master` continues to distribute the verified `v1.0.0-dev.2` prerelease until these changes are promoted. The historical code has been removed from the working tree, but the replacement still requires end-to-end DNS, database, and browser verification. Track the complete per-file replacement ledger in [Rebuild inventory](docs/REBUILD_INVENTORY.md).

> [!IMPORTANT]
> **A clean-slate rebuild.** This is a new application targeting PHP 8.2+, PDO MySQL, Tailwind CSS 4, and BIND 9. It does not read or migrate the historical SMBind tables or deployment files.

## Branch and release policy

Only two active branches are maintained:

- **`dev`** — integration branch for all ongoing development, bug fixes, UI work, and testing. Commit work here and require the existing PHP, database, HTTP, DNS, and frontend verification before promoting.
- **`master`** — release branch. Promote tested changes from `dev` through a pull request. The GitHub CI workflow publishes the versioned prerelease artifact from `master` only, after all required jobs pass.

Temporary `feat/*`, `fix/*`, and `rebuild/*` branches are not part of the active workflow. The historical source remains available through Git commits, tags, and the per-file rebuild inventory.

## Features

- Responsive Tailwind CSS operations console with clear navigation, searchable-in-browser zone tables, and keyboard-accessible forms.
- DNS zone configuration and editing, including SOA parameters and A, AAAA, CAA, CNAME, DS, MX, SSHFP, TLSA, TXT, NS, PTR and SRV records.
- PDO prepared statements with MySQL 8 or compatible MariaDB.
- Administrator/editor/viewer roles, per-zone ownership, and reversible account suspension (revokes sessions on their next request).
- Argon2id password hashing, session regeneration, CSRF defenses, and login throttling.
- Two-stage publishing: validate every zone with named-checkzone, replace zone files atomically, validate BIND configuration, then rndc reload. Original files are restored on failure. Deleted zones are tracked in a publication manifest, and the dashboard reports pending deletions even if no zones remain.
- Activity audit, team management, and password changes.
- Read-only **Review publication** screen and CLI plan showing new, changed, and retired DNS zones before an administrator applies and reloads the BIND configuration.
- Authenticated BIND-format zone export from the zone detail page, including the next SOA serial for pending edits.

**Not included:** legacy database migration, DNSSEC signing, authoritative secondary replication, SSO, multi-tenant billing, and a REST API. This development build requires end-to-end qualification on an isolated BIND installation before production use.

## Minimum requirements

| Component | Requirements |
| --- | --- |
| PHP | 8.2+ with pdo_mysql, Argon2id password hashing, sessions, filter |
| Database | MySQL 8.0+ or compatible MariaDB |
| DNS | BIND 9; named-checkzone, named-checkconf, rndc |
| UI assets | Production ZIP includes compiled CSS and JS; building from source requires Node.js 20+ and pinned Tailwind CSS 4.3.3 |
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

5. **Choose a distribution.** The GitHub Actions `SMBind verification` workflow builds and uploads a **complete application ZIP with compiled CSS** and its SHA-256 checksum. For a deployment using that ZIP, extract it to the application directory and **skip the Node/Tailwind step**. For a source checkout, compile the stylesheet:

   ```bash
   npm install --no-audit --no-fund
   npm run build
   ```

6. Set the HTTP server document root to `public/`. For Nginx deployments, [`ops/nginx-loopback.conf`](ops/nginx-loopback.conf) is a loopback-only PHP-FPM virtual-host starting point; review PHP-FPM socket and installation path before enabling. For local **development only**, use `php -S 127.0.0.1:8080 -t public`.

7. **Prepare dedicated publisher-owned directories.** On Ubuntu/Debian with Nginx/PHP-FPM running as `www-data` and BIND running as group `bind`, a concrete example is:

   ```bash
   sudo install -d -o www-data -g bind -m 2770 /var/cache/bind/smbind
   sudo install -d -o www-data -g bind -m 2770 /etc/bind/smbind-managed
   sudo install -o www-data -g bind -m 0640 /dev/null /etc/bind/smbind-managed/zones.conf
   ```

   Ubuntu's default BIND AppArmor policy permits zone files in `/var/cache/bind/` and configuration under `/etc/bind/`. Do not place zone files under arbitrary paths without explicitly reviewing AppArmor; unexpected permission denials can prevent BIND from loading otherwise valid zones. The `2` in `2770` sets setgid on the directories so newly staged and atomically renamed files inherit group `bind`; `0640` files remain readable by the authoritative daemon. **Confirm your actual PHP-FPM and BIND service identities before using these commands.** Keep the zone directory and managed include dedicated to SMBind only, and configure `SMBIND_INCLUDE_FILE=/etc/bind/smbind-managed/zones.conf`. Add `include "/etc/bind/smbind-managed/zones.conf";` to BIND's main `named.conf` once.

   Keep `SMBIND_ZONE_DIR` outside the web root. Permit the PHP publishing identity to atomically replace files there and to replace `SMBIND_INCLUDE_FILE` (also outside the web root). Set up tightly scoped access to RNDC keys. Never give the web worker unrestricted root/sudo permissions. **Review permissions on every deployment.** The include file must exist and be readable by BIND. Ensure the generated include is explicitly present exactly once in the correct BIND `named.conf`. Do not point SMBind at a configuration file shared with manually managed zones. The include file must initially be **empty** or already contain an SMBind-generated header; the zone directory must be dedicated, since existing non-SMBind zone files are rejected to avoid destructive overwrites.

8. Run the **non-destructive preflight** in the same environment as the PHP process:

   ```bash
   php bin/doctor.php
   ```

   Correct any failures, then sign in. Create zones, add records, and select **Validate & publish DNS**. SMBind changes DNS serving only after an explicit publication action.

### Updating an existing v1.0.0-dev.2 installation

The historical PHP 5-era SMBind database is **not supported**. If you have already installed the **new SMBind v1.0.0-dev.2** prerelease, first take a verified MySQL backup. Before running the dev.3 application code, apply its additive schema upgrade once:

```bash
mysql -u root -p smbind < db/upgrade-dev2-to-dev3.sql
php bin/doctor.php
php bin/plan.php
```

The upgrade adds `users.is_active`, defaulting to `1`, preserving existing accounts and roles. Do **not** apply it again to an already-upgraded database or to the old SMBind PHP 5 schema. Fresh installations use `db/schema.sql` directly. `bin/plan.php` is read-only and prints pending zone changes as JSON.

### Packaged deployment and checksum

The passing CI pipeline also publishes a **versioned GitHub prerelease** under [Releases](https://github.com/bradjtrammell/smbind/releases) after all PHP matrix, MySQL/BIND/HTTP integration and CSS jobs succeed on `master`. Development builds remain marked **prerelease**, not stable. The CI `css` job assembles `smbind-<version>.zip` and `smbind-<version>.zip.sha256` with `public/assets/app.css` already compiled. Download both from the successful workflow's **Artifacts** section. Verify integrity *before extracting*:

```bash
sha256sum -c smbind-1.0.0-dev.3.zip.sha256
unzip -t smbind-1.0.0-dev.3.zip
```

The next ZIP, published only after promotion of dev.3 to `master`, contains the web application, configuration template, schema upgrade, command-line tools, documentation, and a PHP-FPM/Nginx example. Each build also includes a machine-readable `BUILD_INFO.json` with the version and exact source commit; `php bin/doctor.php` verifies it matches `VERSION`. It does not provision a database, alter DNS configuration, or create credentials automatically. **The GitHub Actions artifact is a verified build artifact, not evidence of successful deployment on your host.**

## Permissions

| Role | Capabilities |
| --- | --- |
| Administrator | Manage all zones/users, suspend or restore accounts, publish zones, audit history |
| Editor | Modify only assigned zones, no publishing privileges |
| Viewer | Read zones and records |

## Validation

```bash
find src public bin tests -name '*.php' -type f -exec php -l {} \;
php tests/run.php
# Disposable database/BIND validation integration runs in GitHub CI.
npm run build
```

GitHub CI lints on PHP 8.2–8.5, runs deterministic DNS validation tests, and compiles the UI. It also exercises login, sessions, CSRF rejection, creation and deletion through the real PHP HTTP entry point against disposable MySQL. Additional disposable MySQL 8.4 integration jobs on PHP 8.2 and 8.4 use real BIND zone/configuration validators while simulating RNDC success/failure to test publishing, file rollback, and deleted-zone cleanup. Additionally, CI starts an **actual isolated BIND 9 named daemon**, publishes and modifies a zone, executes real RNDC reload, verifies live authoritative answers using dig, and confirms zone deletion removes its DNS answer. The same integration jobs simulate the dev.2 user schema and apply the incremental upgrade, verifying that existing accounts and DNS rows are preserved. These checks do **not** replace host-specific PHP-FPM/Nginx integration, browser/accessibility testing, live production permissions/AppArmor acceptance, or an operational rollback drill.

## Design

The console adopts an **airy, precise, dependable** visual identity inspired by modern rail-control rooms: ivory `#F6F6F1`, evergreen `#203B32`, action green `#11744E`, and terracotta `#B6543D`. An accessible desktop rail collapses into mobile navigation; operations tables and typographic hierarchy take priority over decorative cards. Inter/system sans, IBM Plex Mono/system mono, 170ms transitions, and reduced-motion compliance.

## Heritage and license

The original Simple Management for BIND project was created by **Ole Myhre** and **Ben Lentz**, with contributions from other developers. The original Git history and author credits remain preserved. The original `COPYING` license text was transferred verbatim to [`LICENSE`](LICENSE) while the historical source remains accessible in earlier commits. The PHP 5-era executable files are not part of the new application.
