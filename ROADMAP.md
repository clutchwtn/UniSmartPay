# Roadmap

Known limitations, in priority order. The first group should be done before
the system handles real money.

## Before real use

- **No double charge on retry.** If the Wi-Fi drops after a payment succeeds,
  the terminal can retry and charge again. Send a unique request id with each
  payment and ignore repeats.
- **HTTPS for the terminal.** Move the firmware to `WiFiClientSecure` with a
  pinned certificate, then enable `UNISMARTPAY_FORCE_HTTPS`.

## Next

- Recharge limits (per recharge and per day) and a way to cancel a recharge.
- A daily spending limit per student, since card UIDs can be cloned.
- A single shared payment function instead of four copies of the logic.
- Run `tests/smoke_test.py` automatically on every push (GitHub Actions).

## Later

- A terminal status page, and an admin action to release a stuck `IN_PROGRESS` order.
- Transaction export for students and admins.
- Low-balance notifications.
