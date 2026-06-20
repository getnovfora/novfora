# Create the NovFora DB + user without the MySQL root password

> Use ONLY if `sudo mysql -e "SELECT 1"` is denied AND you can't retrieve a working root password.
> Method: bring MySQL up briefly with `--skip-grant-tables` (auth bypassed), add just the `novfora`
> user, then restore. Never resets or touches root. No file on disk, so it does NOT depend on the data
> directory path. Needs two `systemctl restart mysql` cycles — the live site keeps connecting through
> the window (auth is simply bypassed), but do it in a quiet window anyway. Run as `dev` on the VPS.
>
> Why skip-grant-tables and not `--init-file`: an init file must live inside MySQL's datadir (AppArmor +
> systemd PrivateTmp block other paths), and that path varies per box. skip-grant-tables avoids the whole
> problem. (The unit honoring `$MYSQLD_OPTS` is confirmed on this box.)

## 0. Make sure MySQL is up first (protect the live site)
```bash
sudo systemctl unset-environment MYSQLD_OPTS
sudo systemctl restart mysql && sleep 3
systemctl is-active mysql        # must say: active   — do not proceed until it does
```

## 1. Enter skip-grant-tables mode
```bash
sudo systemctl set-environment MYSQLD_OPTS="--skip-grant-tables"
sudo systemctl restart mysql && sleep 3
systemctl is-active mysql        # active
```

## 2. Create the DB + user (password pulled from .env so it matches Laravel)
`FLUSH PRIVILEGES` first is required — MySQL 8 disables account statements in this mode until the grant
tables are reloaded.
```bash
DBP=$(grep '^DB_PASSWORD=' ~/novfora/.env | cut -d= -f2-)
mysql <<SQL
FLUSH PRIVILEGES;
CREATE DATABASE IF NOT EXISTS novfora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS novfora_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'novfora'@'localhost' IDENTIFIED BY '${DBP}';
ALTER USER 'novfora'@'localhost' IDENTIFIED BY '${DBP}';
GRANT ALL PRIVILEGES ON novfora.* TO 'novfora'@'localhost';
GRANT ALL PRIVILEGES ON novfora_test.* TO 'novfora'@'localhost';
FLUSH PRIVILEGES;
SQL
```

## 3. Restore normal auth
```bash
sudo systemctl unset-environment MYSQLD_OPTS
sudo systemctl restart mysql && sleep 3
systemctl is-active mysql        # active
```

## 4. Verify + migrate
```bash
DBP=$(grep '^DB_PASSWORD=' ~/novfora/.env | cut -d= -f2-)
mysql -u novfora -p"$DBP" -h 127.0.0.1 -e "SHOW DATABASES LIKE 'novfora%';"   # lists novfora + novfora_test
cd ~/novfora && php artisan migrate --force
```

## Safety notes
- During step 1–3, MySQL accepts local connections without auth. Keep the window short; the box's
  firewall must not expose 3306 externally. We deliberately do NOT add `--skip-networking` so the live
  site's TCP connections keep working.
- If a restart ever fails, `sudo systemctl unset-environment MYSQLD_OPTS && sudo systemctl restart mysql`
  always returns the server to its normal configuration.

## Done when
- Step 4 lists `novfora` + `novfora_test`, and `php artisan migrate --force` completes with no access error.
- `MYSQLD_OPTS` is unset (`systemctl show-environment | grep MYSQLD_OPTS` returns nothing).

## Rollback
Once you have admin access: `DROP DATABASE novfora; DROP DATABASE novfora_test; DROP USER 'novfora'@'localhost';`
