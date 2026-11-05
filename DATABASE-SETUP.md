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

The seed data includes development accounts, products, balances and terminals.
The credentials are intentionally for local testing only. Replace or disable
them before exposing the application to a network.

## Existing installations

Back up the database before importing a new schema. Do not run ad-hoc `ALTER`
statements copied from older documentation without checking the current schema
first; the SQL file is the source of truth for a fresh installation.
