# Database setup

UniSmart Pay uses MySQL. The complete schema and development seed data are in:

```text
database/unismart_pay_schema.sql
```

## Requirements

- MySQL through XAMPP, MySQL Workbench or a compatible server.
- Permission to create the `unismart_pay` database.

## Import with phpMyAdmin

1. Start MySQL in XAMPP.
2. Open `http://localhost/phpmyadmin`.
3. Open **Import**.
4. Select `database/unismart_pay_schema.sql`.
5. Run the import and wait for it to finish.

## Import from a terminal

From the repository root:

```bash
mysql -u root -p < database/unismart_pay_schema.sql
```

If the root account has no password, press Enter when prompted.

## Configure PHP

Check `web/config/config.php` and make sure these values match MySQL:

```text
DB_NAME = unismart_pay
DB_USER = root
DB_PASS = your-password
```

Do not use the example values for a production deployment.

## Verify the import

```sql
USE unismart_pay;
SHOW TABLES;

SELECT email, role, actif FROM admins;
SELECT matricule, actif FROM etudiants;
SELECT nom, prix, stock FROM produits;
SELECT id_terminal, type, statut FROM terminaux;
```

The seed data includes faculties, a test product and one test student
(`TEST/RFID/0001` / `Etudiant@1234`, card `9F:F7:9D:C2`) for local testing only.
No admins and no terminals are seeded: create them with the scripts below.

## Create admins and terminals

With Docker, run the scripts inside the app container (`-it` so the password
prompt works):

```bash
docker compose exec -it app php /opt/unismartpay/scripts/create_admin.php admin@example.com Nom Prenom super_admin
docker compose exec app php /opt/unismartpay/scripts/create_terminal.php "Terminal Resto" RESTO 1
docker compose exec app php /opt/unismartpay/scripts/create_terminal.php "Terminal Buvette" BUVETTE 1
```

Without Docker, run the same scripts with `php scripts/...` from the repository root.

In Git Bash on Windows, run `export MSYS_NO_PATHCONV=1` first. Otherwise Git Bash
rewrites container paths such as `/opt/...` into a Windows path and the container cannot find the script.

- `create_admin.php` asks for the password (at least 12 characters) and stores it
  with `password_hash()`. A faculty admin needs `admin_faculte <id_faculte>`.
- `create_terminal.php` prints the terminal's token **once**. Copy it into
  `TERMINAL_TOKEN` in the ESP32 firmware right away; only its SHA-256 hash is
  stored, so it cannot be recovered later.
- Terminal ids follow creation order. Create the RESTO terminal first and the
  BUVETTE terminal second to get ids 1 and 2.

## Existing installations

Back up the database before importing a new schema. Do not run ad-hoc `ALTER`
statements copied from older documentation without checking the current schema
first; the SQL file is the source of truth for a fresh installation.

Terminal tokens are now stored hashed, so terminals from an older database
(plain tokens such as `TOKEN_RESTO_FST_001`) stop authenticating. For a
development database, reset it (`docker compose down -v && docker compose up -d --build`)
and create new admins and terminals as above.
