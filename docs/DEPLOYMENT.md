# Running and deploying WasteWise

## 1. Local (XAMPP), the main way

1. Start Apache and MySQL in the XAMPP control panel.
2. Copy this folder to `C:\xampp\htdocs\Wastewise`.
3. Open phpMyAdmin, click **Import**, choose `wastewise_db.sql`, click Go.
   (This erases and recreates every table. Use `schema_v2_upgrade.sql` instead only if you must keep
   existing data.)
4. Open `http://localhost/Wastewise/register.php` and create an account. If the page shows a database
   error, check the settings in `includes/config.php` and that the import in step 3 finished.

The default settings (`root`, empty password) suit XAMPP. Change them for anything public.

## 2. Docker (portable, and a base for hosting)

```
docker compose up --build
```
Then open `http://localhost:8080`. The database is created from `wastewise_db.sql` on first start.
Stop with `docker compose down` (add `-v` to also delete the database).
Settings come from environment variables (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`,
`APP_ENV`), so no code changes are needed between machines.

## 3. Putting it on a public host

Any host that offers PHP 8 and MySQL (or a container host plus a managed MySQL) will do. Check the
host's current free-tier terms yourself before choosing, as they change.

Checklist:
- [ ] Create a MySQL database and user; import `wastewise_db.sql`.
- [ ] Set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_PORT` and `APP_ENV=production`
      (or edit `includes/config.php` if the host has no environment variables).
- [ ] Upload the project. Do **not** publish `tests/`, `evaluation/`, `docs/` or `*.sql` (the supplied
      `.htaccess` files block them on Apache, and `.dockerignore` keeps them out of the image).
- [ ] Use **HTTPS**. Camera barcode scanning only works on HTTPS or localhost, and session cookies
      are marked Secure under HTTPS.
- [ ] Register a test account and run FT-01, FT-06, FT-09 and ST-05 from `docs/TEST_PLAN.md`.
- [ ] Record the live address and the date you checked it, for the report.

## 4. Things that need an internet connection

Barcode lookup (Open Food Facts), receipt photo reading (Tesseract.js and its language data), the
camera scanner library and the summary chart all load from the internet. Everything else works offline.
For the viva, check the Wi-Fi in advance and keep a **local XAMPP copy as a backup** in case the
public site or the network fails (this is the mitigation in the contract's risk analysis).

## 5. Backups

phpMyAdmin > Export (SQL) before the demo, and after any testing you want to keep.
