# Database setup

The full schema and the development seed data are in
`database/unismart_pay_schema.sql`. It is the source of truth for a new database.

## Seed data

The seed creates the faculties, one test product (barcode `3700104455153`) and
one test student for local testing:

| Matricule | Password | Card UID | Balance |
|---|---|---|---|
| `TEST/RFID/0001` | `Etudiant@1234` | `9F:F7:9D:C2` | 20.00 DT |

No admins and no terminals are seeded. Create them with the scripts below.

## Loading the schema

- **Docker:** the schema loads automatically the first time the `db` container
  starts. To start over from the seed, run `docker compose down -v`, then
  `docker compose up -d --build`.
- **XAMPP / MySQL:** import the file with phpMyAdmin or
  `mysql -u root -p < database/unismart_pay_schema.sql`. See
  [`web/INSTALLATION.md`](web/INSTALLATION.md) for the database user and settings.

## Create admins and terminals

With Docker, run the scripts inside the app container (`-it` so the password
prompt works):

```bash
docker compose exec -it app php /opt/unismartpay/scripts/create_admin.php admin@example.com Nom Prenom super_admin
docker compose exec app php /opt/unismartpay/scripts/create_terminal.php "Terminal Resto" RESTO 1
docker compose exec app php /opt/unismartpay/scripts/create_terminal.php "Terminal Buvette" BUVETTE 1
```

Without Docker, run `php scripts/create_admin.php ...` from the repository root,
with the database variables set (see `web/INSTALLATION.md`).

In Git Bash on Windows, run `export MSYS_NO_PATHCONV=1` first. Otherwise Git
Bash turns `/opt/...` into a Windows path and the container cannot find the script.

- **`create_admin.php`:** arguments are `<email> <nom> <prenom> [super_admin|admin_faculte] [id_faculte]`.
  It asks for the password (at least 12 characters) and stores it with
  `password_hash()`. A faculty admin needs an `id_faculte`.
- **`create_terminal.php`:** arguments are `<nom> <RESTO|BUVETTE|RECHARGE> [id_faculte] [localisation]`.
  It prints the terminal token **once**. Put it in `TERMINAL_TOKEN` in the ESP32
  firmware right away: only its SHA-256 hash is stored, so it cannot be recovered.
- **Terminal ids** follow creation order. Create the RESTO terminal first and
  the BUVETTE terminal second to get ids 1 and 2.

## Migrations

`database/migrations/` contains the changes for databases created before a
schema update. A new database already includes them. Apply each file once, in
order:

```bash
mysql -u <user> -p unismart_pay < database/migrations/001_terminal_orders_created_by_admin.sql
mysql -u <user> -p unismart_pay < database/migrations/002_login_attempts.sql
```

Terminal tokens are stored hashed, so terminals from older databases (plain
tokens such as `TOKEN_RESTO_FST_001`) no longer authenticate. Create new
terminals with the script and update the firmware.

Back up the database before applying migrations to real data.

## Check the database

```sql
USE unismart_pay;
SELECT email, role, actif FROM admins;
SELECT id_terminal, nom, type, statut FROM terminaux;
SELECT matricule, actif FROM etudiants;
SELECT nom, code_barre, prix, stock FROM produits;
```
