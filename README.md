# UniSmart Pay

UniSmart Pay is a university payment platform built with PHP, PDO and MySQL.
It includes a student portal, a cafeteria workflow and an optional ESP32 RFID
payment terminal.

Demo video: https://youtu.be/cDfxOyyTLME

## What is included

- Student login, balance and transaction history.
- Cafeteria barcode scanning, cart management and checkout.
- RFID terminal support with an ESP32 and RC522 reader.
- RESTO mode with a fixed ticket price.
- BUVETTE mode for paying a web-created pending order.
- Terminal and payment logs stored in `code_appareil`.

## Quick start

Requirements:

- XAMPP with Apache and MySQL.
- PHP 8.x recommended.
- A modern browser.

1. Copy the project to `C:\xampp\htdocs\UniSmartPay`.
2. Start Apache and MySQL in XAMPP.
3. Import `database/unismart_pay_schema.sql` with phpMyAdmin.
4. Open `http://localhost/UniSmartPay/web/`.

The default database settings are in `web/config/config.php`. The demo
accounts are documented in [`DATABASE-SETUP.md`](DATABASE-SETUP.md); change
them before using the application outside development.

## Docker development

Docker is an alternative to XAMPP:

```bash
cp .env.example .env
docker compose up --build
```

Open `http://localhost:8080/UniSmartPay/web/`. Optional phpMyAdmin is available
with:

```bash
docker compose --profile tools up --build
```

Then open `http://localhost:8081`. The MySQL data is stored in the
`unismart_pay_db_data` Docker volume. To recreate the database from the SQL
seed, stop the stack and remove that volume:

```bash
docker compose down -v
```

The Docker credentials are for local development only. Keep `.env` private.

## ESP32 terminal

Use [`esp32/unismart_pay_terminal.ino`](esp32/unismart_pay_terminal.ino).
The terminal reads a card UID, checks it through the PHP API, and then performs
the appropriate payment flow. The computer and ESP32 must be on the same
network. Configure `SERVER_URL` with the computer's LAN address, never
`localhost`. Configure `TERMINAL_TOKEN` with the credential assigned to that
terminal.

Read [`esp32/SETUP_GUIDE.md`](esp32/SETUP_GUIDE.md) before wiring or uploading.

## Main API routes

| Purpose | Method and route |
|---|---|
| Check a card | `POST /web/api/card_check.php` |
| RESTO payment | `POST /web/api/payment.php` |
| Claim a cafeteria order | `GET /web/api/terminal_order_claim.php?terminal_id=1` |
| Pay a cafeteria order | `POST /web/api/terminal_order_pay.php` |
| Read terminal mode | `GET /web/api/terminal_mode_get.php?terminal_id=1` |
| Write a terminal log | `POST /web/api/terminal/log.php` |

## Documentation

- [`web/INSTALLATION.md`](web/INSTALLATION.md) - web installation and testing.
- [`DATABASE-SETUP.md`](DATABASE-SETUP.md) - database import and checks.
- [`esp32/SETUP_GUIDE.md`](esp32/SETUP_GUIDE.md) - ESP32 wiring and setup.
- [`SERIAL_MONITOR.md`](SERIAL_MONITOR.md) - logs and serial troubleshooting.
- [`NODE_RFID_SERVER.md`](NODE_RFID_SERVER.md) - optional Node.js serial bridge.
- [`ROADMAP.md`](ROADMAP.md) - planned improvements.
