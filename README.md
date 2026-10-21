# WP DB Backup Cron

Scheduled database backups for WordPress, run by WP-Cron — no shell access required.

## Why

Most backup plugins are bloated, upsell you, or phone home. This one does a single job well: a daily or weekly `.sql.gz` of your **entire database**, stored locally with rotation, plus an email only when something breaks.

## How it works

1. **Fast path** — if `mysqldump` is installed, `exec()` is allowed, and MySQL runs locally, it dumps with `--single-transaction --quick` (password via `MYSQL_PWD` env var, never on the command line).
2. **Portable fallback** — otherwise it dumps through `$wpdb` (`SHOW CREATE TABLE` + chunked `INSERT`s), which works on any shared host.
3. The dump is gzipped, stored in `wp-content/uploads/wpdbc-backups/` (filename includes a random token; the folder has `index.php` + `.htaccess` deny), old backups are pruned to your retention count, and the admin gets an email on failure.

## Usage

Install, activate, go to **Tools → DB Backups**:

- Pick Daily / Weekly / Disabled, set how many newest backups to keep
- **Backup Now** button for on-demand runs
- Download or delete any stored backup from the table (capability + nonce protected)

Uninstalling removes the settings and the cron job but **keeps your backup files** — deleting someone's only data copy on uninstall would be a crime.

## License

MIT — see [LICENSE](LICENSE).
