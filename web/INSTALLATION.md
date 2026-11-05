# Web application installation

## Requirements

- XAMPP with Apache and MySQL.
- PHP 8.x recommended.
- A current desktop browser.

Chrome or Edge is recommended for barcode camera access and Web Serial.

## Install locally

1. Copy the repository to `C:\xampp\htdocs\UniSmartPay`.
2. Check database values in `web/config/config.php`.
3. Import `database/unismart_pay_schema.sql`.
4. Start Apache and MySQL in XAMPP.
5. Open `http://localhost/UniSmartPay/web/`.

If Apache uses a port other than 80, include that port in every URL.

## Docker alternative

From the repository root:

```bash
cp .env.example .env
docker compose up --build
```

Open `http://localhost:8080/UniSmartPay/web/`. To also start phpMyAdmin:

```bash
docker compose --profile tools up --build
```

Open phpMyAdmin at `http://localhost:8081`. Docker stores MySQL data in a
persistent volume. `docker compose down` keeps the data; `docker compose down
-v` deletes it and causes the schema seed to run again on the next start.

## Main pages

| Page | URL |
|---|---|
| Home | `/UniSmartPay/web/` |
| Administration | `/UniSmartPay/web/admin/login.php` |
| Student portal | `/UniSmartPay/web/etudiant/login.php` |
| RESTO terminal | `/UniSmartPay/web/terminal/resto.php` |
| BUVETTE terminal | `/UniSmartPay/web/terminal/buvette.php` |

The SQL seed credentials are for development only. Change them before
deployment.

## Functional smoke test

1. Log in to the administration area.
2. Confirm that a student, account and active RFID card exist.
3. Confirm that the card UID matches the UID printed by the ESP32.
4. Check the student balance.
5. In RESTO mode, present the card and verify the fixed-price debit.
6. In BUVETTE mode, create a web order, present the card and verify that the
   order changes from pending to paid.
7. Check the terminal logs and transaction history.

## ESP32 network setup

When the ESP32 accesses Docker from another device, use the computer's LAN IP
and the published application port:

```text
http://192.168.1.25:8080/UniSmartPay/web/api/payment.php
```

`localhost` works only from the computer itself. If requests time out, check
Apache, the Windows firewall and whether both devices use the same network.

## Security checklist

- Remove or change all demo accounts.
- Use a non-empty database password.
- Use HTTPS outside local development.
- Do not commit Wi-Fi credentials or database secrets.
- Restrict MySQL and terminal API access to trusted networks.
- Keep server-side validation and CSRF protection enabled.

## Troubleshooting

**Database connection error:** verify MySQL is running, the database exists and
`web/config/config.php` matches the server.

**RFID card not found:** assign the UID to a student, activate the card and
check the UID format.

**Camera unavailable:** use HTTPS or `http://localhost` on a desktop browser.

**Session error:** allow cookies and check PHP's session directory and Apache
error log.

See [`../SERIAL_MONITOR.md`](../SERIAL_MONITOR.md) for terminal diagnostics.
