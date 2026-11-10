# UniSmart Pay

UniSmart Pay is a university payment platform built with PHP 8.2, PDO and MySQL.
Students pay at the restaurant and the cafeteria with an RFID card, read by an
ESP32 terminal with an RC522 reader.

Demo video: https://youtu.be/cDfxOyyTLME

## Features

- **Student portal:** balance, transaction history, card status, and web payment
  for a restaurant ticket or a cafeteria cart.
- **Administration:** create students, assign RFID cards, recharge accounts and
  manage products. Faculty admins only see their own faculty's students.
- **RESTO mode:** the terminal charges a fixed ticket price when a card is presented.
- **BUVETTE (cafeteria) mode:** the cashier scans products in the browser to
  create an order, and the student pays it by presenting their card.
- **Terminal logs:** terminal activity is stored in `code_appareil` and shown
  on the terminal pages.

## Quick start (Docker)

```bash
cp .env.example .env
docker compose up -d --build
```

The database is created from `database/unismart_pay_schema.sql` on first start.
It contains one test student (`TEST/RFID/0001` / `Etudiant@1234`) but **no admins
and no terminals**. Create them inside the container:

```bash
docker compose exec -it app php /opt/unismartpay/scripts/create_admin.php admin@example.com Nom Prenom super_admin
docker compose exec app php /opt/unismartpay/scripts/create_terminal.php "Terminal Resto" RESTO 1
docker compose exec app php /opt/unismartpay/scripts/create_terminal.php "Terminal Buvette" BUVETTE 1
```

In Git Bash on Windows, run `export MSYS_NO_PATHCONV=1` first. Each terminal
token is printed once: save it for the ESP32 firmware.

Open `http://localhost:8080/UniSmartPay/web/`.

| Page | URL |
|---|---|
| Student portal | `/UniSmartPay/web/etudiant/login.php` |
| Administration | `/UniSmartPay/web/admin/login.php` |
| RESTO terminal | `/UniSmartPay/web/terminal/resto.php` |
| BUVETTE terminal | `/UniSmartPay/web/terminal/buvette.php` |

To start phpMyAdmin as well, use `docker compose --profile tools up -d` and
open `http://localhost:8081`. To reset the database to the seed data, run
`docker compose down -v`, then start again.

To run without Docker (XAMPP), see [`web/INSTALLATION.md`](web/INSTALLATION.md).

## ESP32 terminal

The firmware is [`esp32/unismart_pay_terminal.ino`](esp32/unismart_pay_terminal.ino).
It reads a card, checks it with the server, then pays the restaurant ticket or
the waiting cafeteria order, depending on the terminal's mode. Wiring and
configuration are in [`esp32/SETUP_GUIDE.md`](esp32/SETUP_GUIDE.md).

Terminal endpoints authenticate with an `X-Terminal-Token` header:

| Purpose | Route |
|---|---|
| Check a card | `POST /web/api/card_check.php` |
| Pay a restaurant ticket | `POST /web/api/payment.php` |
| Read the terminal mode | `GET /web/api/terminal_mode_get.php?terminal_id=1` |
| Claim the waiting cafeteria order | `GET /web/api/terminal_order_claim.php?terminal_id=2` |
| Pay a cafeteria order | `POST /web/api/terminal_order_pay.php` |
| Write a terminal log | `POST /web/api/terminal/log.php` |

## Tests

[`tests/smoke_test.py`](tests/smoke_test.py) checks the main payment flows and
the security fixes against a running instance. It needs only Python 3 and acts
as the terminal itself, so no ESP32 is required. See [`tests/README.md`](tests/README.md).

## Security notes

- Terminal tokens and admin passwords are never stored in plain text. They are
  created with the scripts above.
- The ESP32 currently talks to the server over plain HTTP. Only use it on a
  trusted network until the firmware moves to HTTPS (see the ESP32 guide).
- `.env` holds the database passwords. Keep it private.

## Documentation

- [`web/INSTALLATION.md`](web/INSTALLATION.md): installation without Docker (XAMPP).
- [`DATABASE-SETUP.md`](DATABASE-SETUP.md): schema, migrations, admins and terminals.
- [`esp32/SETUP_GUIDE.md`](esp32/SETUP_GUIDE.md): ESP32 wiring, firmware and troubleshooting.
- [`SERIAL_MONITOR.md`](SERIAL_MONITOR.md): terminal logs and serial debugging.
- [`ROADMAP.md`](ROADMAP.md): known limitations and planned work.
