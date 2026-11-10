# Tests

`smoke_test.py` runs against a live instance and checks two things:

- **Functional:** logins, student pages, a restaurant payment, the full
  cafeteria order flow and a recharge. Every test must say `PASS`; the script
  exits with code 1 otherwise.
- **Security:** one check per fixed vulnerability, labelled with its fix
  number. Each should say `FIXED`; `OPEN` means a regression. These results
  only count when all functional tests pass.

It uses only the Python 3 standard library and acts as the ESP32 itself, so no
device is needed. It tops the test student's balance back up after each
payment, so it can be run repeatedly.

## Run

Start the app, create an admin and two terminals (RESTO first, then BUVETTE;
see [`../DATABASE-SETUP.md`](../DATABASE-SETUP.md)), then pass their
credentials (Git Bash shown):

```bash
export RESTO_TOKEN=... BUVETTE_TOKEN=... ADMIN_EMAIL=admin@example.com ADMIN_PASS=...
python tests/smoke_test.py
```

| Option | Effect |
|---|---|
| `--only func` / `--only sec` | Run one group only. |
| `--lockout` | Also check login lockout. This locks the test student for 15 minutes, so run it last. |

Other settings (with defaults): `APP_ROOT` (`http://localhost:8080/UniSmartPay`),
`RESTO_TERMINAL` (`1`), `BUVETTE_TERMINAL` (`2`), `STUDENT_MAT`, `STUDENT_PASS`,
`CARD_UID`, `PRODUCT_ID`, `PRODUCT_PRICE`.

The security checks for card-less payments add a throwaway student on each
run. Reset the database with `docker compose down -v` when needed.

## Testing on a separate copy

To avoid touching your own data, run a second instance on another port:

```bash
export MSYS_NO_PATHCONV=1   # Git Bash only
APP_PORT=8090 docker compose -p unismart-verify up -d --build --wait
# create the admin and terminals with: docker compose -p unismart-verify exec -T app php /opt/unismartpay/scripts/...
APP_ROOT=http://localhost:8090/UniSmartPay python tests/smoke_test.py
docker compose -p unismart-verify down -v
```

Docker can report MySQL as healthy slightly before it accepts connections. If
the first requests fail with a database error, wait a few seconds and run again.
