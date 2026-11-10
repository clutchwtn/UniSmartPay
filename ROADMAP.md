# Roadmap

Known limitations, in priority order. The first group should be done before
the system handles real money.

## Before real use

- **Server-side restaurant price.** `payment.php` charges the amount the
  terminal sends. For RESTO terminals, the server should use
  `config_resto.prix_ticket` instead.
- **Block lost or stolen cards.** The `cartes` table has the fields
  (`statut`, `motif_blocage`, `bloquee_par`), but no page sets them yet. Add a
  "block my card" action for students and block/unblock for admins.
- **No double charge on retry.** If the Wi-Fi drops after a payment succeeds,
  the terminal can retry and charge again. Send a unique request id with each
  payment and ignore repeats.
- **HTTPS for the terminal.** Move the firmware to `WiFiClientSecure` with a
  pinned certificate, then enable `UNISMARTPAY_FORCE_HTTPS`.
- **Remove unused endpoints.** `web/api/terminal/paiement.php` and
  `web/api/terminal/solde.php` are not used by the firmware.

## Next

- Recharge limits (per recharge and per day) and a way to cancel a recharge.
- A daily spending limit per student, since card UIDs can be cloned.
- Unique payment references from `random_bytes()` instead of time + 4 digits.
- A single shared payment function instead of four copies of the logic.
- Run `tests/smoke_test.py` automatically on every push (GitHub Actions).
- Nightly database backups, with restore instructions.

## Later

- A terminal status page, and an admin action to release a stuck `IN_PROGRESS` order.
- Transaction export for students and admins.
- Low-balance notifications.
