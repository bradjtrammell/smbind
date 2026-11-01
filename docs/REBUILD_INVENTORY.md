# SMBind 1.0 — complete historical file disposition

**Reference baseline:** `master` before the rebuild, commit `90972375bdd5909667dafc4c9f2f1c338eda47bd` (November 2, 2014).

The 1.0 clean-slate rebuild explicitly accounts for **all 75 files** tracked at the historical baseline: **73 removed, one rewritten (`README.md`), and one renamed by preservation (`COPYING` → `LICENSE`)**. File history remains accessible through Git. No old PHP application code, CSS, image, importer, or Smarty template remains in the new working tree.

| Original path | Disposition |
| --- | --- |
| `CHANGELOG` | Removed: historical changelog preserved in Git history |
| `COPYING` | Preserved verbatim as `LICENSE` (original blob reused) |
| `INSTALL` | Removed: replaced with clean-install instructions in `README.md` |
| `README` | Removed: replaced by `README.md` |
| `README.md` | Rewritten as the new 1.0 development guide |
| `README.quickstart` | Removed: replaced with clean-install instructions in `README.md` |
| `UPGRADE` | Removed: no in-place upgrade supported |
| `Version` | Removed: replaced by root `VERSION` |
| `contrib/import.php` | Removed: removed `mysql_*` importer; no legacy data migration |
| `php/config.php` | Removed: legacy config replaced with environment-based `src/bootstrap.php` |
| `php/images/background.gif` | Removed: original obsolete graphics |
| `php/images/bottombar.gif` | Removed: original obsolete graphics |
| `php/images/dot_clear.gif` | Removed: original obsolete graphics |
| `php/images/logo.png` | Removed: original obsolete graphics |
| `php/images/logo1.png` | Removed: original obsolete graphics |
| `php/images/no.png` | Removed: original obsolete graphics |
| `php/images/top1.gif` | Removed: original obsolete graphics |
| `php/images/top3.gif` | Removed: original obsolete graphics |
| `php/images/top4.gif` | Removed: original obsolete graphics |
| `php/images/top5.gif` | Removed: original obsolete graphics |
| `php/images/top6.gif` | Removed: original obsolete graphics |
| `php/images/unknown.png` | Removed: original obsolete graphics |
| `php/images/yes.png` | Removed: original obsolete graphics |
| `php/index.php` | Removed: replaced by `public/index.php` |
| `php/src/chpass.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/commit.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/configtest.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/deleteuser.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/deletezone.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/include.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/logout.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/main.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/newuser.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/newzone.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/options.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/optionsread.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/optionswrite.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/record.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/recordread.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/recordwrite.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/savepass.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/user.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/useradd.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/userdelete.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/userlist.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/userlistread.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/userread.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/userwrite.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/zoneadd.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/zonedelete.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/zonelist.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/src/zoneread.php` | Removed: legacy PHP handlers replaced by `public/index.php` and `src/` |
| `php/templates/accessdenied.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/chpass.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/commit.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/deleteuser.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/deletezone.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/footer.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/header.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/login.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/logout.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/main.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/mainpage.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/menu.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/newuser.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/newzone.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/optionsread.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/pages.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/recordread.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/savepass.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/userlistread.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/userread.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `php/templates/zoneread.tpl` | Removed: Smarty templates replaced by `src/Ui.php` and Tailwind |
| `smbind-mysql.sql` | Removed: new MySQL PDO schema is `db/schema.sql` |
| `smbind-pg.sql` | Removed: new MySQL PDO schema is `db/schema.sql` |

## Why delete instead of keeping stale files?

Unused legacy files remain discoverable, can be accidentally deployed, and can imply they are still supported. Removing every obsolete artifact eliminates PHP 5-era executable code, default-password-era installation guides, and obsolete UI templates from clean deployments. Historical sources and attribution remain available through the repository's Git history.

## Rebuild architecture

- `public/`: new HTTP application and frontend assets (web document root).
- `src/`: dependency-free PHP application, DNS validation, zone rendering and BIND publishing.
- `db/`: new PDO MySQL schema; no legacy tables.
- `bin/`: CLI administrator bootstrap and explicit DNS publish command.
- `assets/`: Tailwind 4 source, compiled during deployment.
- `tests/` and `.github/workflows/`: automated verification.
- `LICENSE`: original unmodified copyright license document.

**Verification remains in progress.** File disposition is verified against the Git tree; it does not prove the new application is production-ready.
