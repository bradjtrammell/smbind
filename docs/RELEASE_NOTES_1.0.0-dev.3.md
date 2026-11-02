# SMBind 1.0.0-dev.3 — development branch

Branch: dev. master remains at released v1.0.0-dev.2 until tested promotion.

## Implementation

- Canonical path and symlink guards; mandatory readable DNS backup snapshots before publication, and surfaced critical errors if automatic rollback fails.
- Stable serials for unchanged zones; continued live BIND validation.
- CAA, DS, SSHFP and TLSA DNS records with strict input validation.
- Read-only administrator publication preview, with CLI JSON plan.
- Authenticated BIND-format zone export.
- Reversible account suspension, immediate login rejection, existing-session revocation on next request.
- Per-zone database row locks on DNS record mutations; concurrent editors cannot bypass CNAME collision detection or ownership changes.
- Additive upgrade SQL for installations of the new v1.0.0-dev.2 only; no historical SMBind migration.

## Qualification

- PHP 8.2–8.5 lint and DNS tests.
- MySQL 8.4 / PDO / HTTP authentication, session and authorization tests.
- Actual isolated BIND daemon, RNDC reload, authoritative DNS answers, rollback.
- CSS compilation and full application ZIP build.
- Real-host PHP-FPM/Nginx, permissions, operational rollback, browser accessibility still host-dependent.

Run the schema upgrade once after database backup for existing dev.2 installs. Fresh installs use db/schema.sql.
