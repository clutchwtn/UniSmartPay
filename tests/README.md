# Tests

`smoke_test.py` checks that the app still works and tracks the security fixes from the audit.
It uses only the Python standard library and acts as the ESP32 terminal itself, so no device is needed.

## Run

```bash
docker compose up -d --build
python tests/smoke_test.py
```

- **Functional** tests must always say `PASS`. The script exits with code 1 if any of them fails.
- **Security** checks say `OPEN` until the matching fix (`#1`, `#2`, ...) lands, then `FIXED`.
- `--only func` / `--only sec` runs one group. `--lockout` also runs the #9 login-lockout check, which locks the test student for a while.

Reset the database to the seed data with `docker compose down -v && docker compose up -d --build`.

## After fix #1

The seed tokens and admin password go away, so pass the new ones as environment variables (Git Bash):

```bash
export RESTO_TOKEN=... BUVETTE_TOKEN=... ADMIN_PASS=...
python tests/smoke_test.py
```

Other settings: `APP_ROOT` (default `http://localhost:8080/UniSmartPay`), `RESTO_TERMINAL`, `BUVETTE_TERMINAL`, `ADMIN_EMAIL`, `STUDENT_MAT`, `STUDENT_PASS`, `CARD_UID`, `PRODUCT_ID`, `PRODUCT_PRICE`.

## Rule for each fix

1. Run the tests before the change.
2. Make the fix.
3. Run them again: functional still all `PASS`, and the fix's check flips to `FIXED`.
4. If a fix intentionally changes a flow (#3 changes order claiming), update the test in the same commit.
