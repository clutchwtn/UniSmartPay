# Roadmap

Known limitations, in priority order. The first group should be done before
the system handles real money.

## Before real use

- **Require a request id from terminals.** Payments without `request_id` are
  still accepted so terminals with older firmware keep working. Once every
  terminal runs the new firmware, reject them.
- **HTTPS for the terminal.** Move the firmware to `WiFiClientSecure` with a
  pinned certificate, then enable `UNISMARTPAY_FORCE_HTTPS`.

## Next

- Recharge limits (per recharge and per day) and a way to cancel a recharge.
- A daily spending limit per student, since card UIDs can be cloned.
- Run `tests/smoke_test.py` automatically on every push (GitHub Actions).

## Later

- A terminal status page, and an admin action to release a stuck `IN_PROGRESS` order.
- Transaction export for students and admins.
- Low-balance notifications.
