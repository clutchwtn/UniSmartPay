# Installation without Docker (XAMPP)

Docker is the simplest setup (see the [README](../README.md)). Use this guide
to run the app on XAMPP instead.

## Requirements

- XAMPP with Apache, PHP 8.2+ and MySQL/MariaDB.
- Chrome or Edge on a desktop computer, for barcode scanning and Web Serial.

## 1. Copy the project

Copy the repository to `C:\xampp\htdocs\UniSmartPay`. The app expects to be
served at `/UniSmartPay/web/`.

## 2. Create the database and a MySQL user

Import `database/unismart_pay_schema.sql` with phpMyAdmin (**Import** tab) or:

```bash
mysql -u root -p < database/unismart_pay_schema.sql
```

The app refuses to start without a database password, so create a dedicated
user instead of using XAMPP's passwordless `root`:

```sql
CREATE USER 'unismart'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
GRANT ALL PRIVILEGES ON unismart_pay.* TO 'unismart'@'localhost';
```

## 3. Configure Apache

The database settings come from environment variables. Add them to
`C:\xampp\apache\conf\httpd.conf`, then restart Apache:

```apache
SetEnv UNISMARTPAY_DB_USER unismart
SetEnv UNISMARTPAY_DB_PASS choose-a-strong-password
SetEnv UNISMARTPAY_DB_PORT 3306
```

| Variable | Default |
|---|---|
| `UNISMARTPAY_DB_HOST` | `127.0.0.1` |
| `UNISMARTPAY_DB_PORT` | `3307` (XAMPP uses `3306`) |
| `UNISMARTPAY_DB_NAME` | `unismart_pay` |
| `UNISMARTPAY_DB_USER`, `UNISMARTPAY_DB_PASS` | none; both are required |
| `UNISMARTPAY_APP_ENV` | `prod`; set `dev` to see error details |
| `UNISMARTPAY_FORCE_HTTPS` | off; `1` redirects to HTTPS and sends HSTS |

Do not put these values in `web/.htaccess`: that file is committed to the repository.

## 4. Create an admin and the terminals

The scripts read the same variables, so set them in the shell first
(PowerShell shown):

```powershell
$env:UNISMARTPAY_DB_USER = "unismart"
$env:UNISMARTPAY_DB_PASS = "choose-a-strong-password"
$env:UNISMARTPAY_DB_PORT = "3306"
php scripts/create_admin.php admin@example.com Nom Prenom super_admin
php scripts/create_terminal.php "Terminal Resto" RESTO 1
php scripts/create_terminal.php "Terminal Buvette" BUVETTE 1
```

More details are in [`../DATABASE-SETUP.md`](../DATABASE-SETUP.md).

## 5. Open the app

Open `http://localhost/UniSmartPay/web/`. If Apache does not use port 80,
include the port in every URL.

## Manual check

1. Log in as the admin, and assign a card to the test student if needed.
2. On the RESTO terminal page, present the card and check the ticket price is debited.
3. On the BUVETTE terminal page, scan a product, present the card, and check
   the order becomes paid.
4. Check the student's transaction history and the terminal logs.

## Troubleshooting

| Symptom | Check |
|---|---|
| "Server configuration error." | `UNISMARTPAY_DB_USER` and `UNISMARTPAY_DB_PASS` are set, and Apache was restarted. |
| Database connection error | MySQL is running, the port is right (`3306` on XAMPP), and the user can access `unismart_pay`. |
| Card not found | The card is assigned to a student, active, and its UID matches what the ESP32 prints. |
| Camera unavailable | Use HTTPS, or `http://localhost` on the same computer. |

Terminal and network issues are covered in [`../SERIAL_MONITOR.md`](../SERIAL_MONITOR.md).
