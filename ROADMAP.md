# Roadmap

This roadmap is intentionally ordered by reliability and safety rather than
feature count.

## Phase 1 - verify the current system

- Compile the ESP32 sketch with the selected board package and libraries.
- Test RC522 detection, Wi-Fi connection and API reachability.
- Test RESTO payment with an active card and insufficient balance.
- Test BUVETTE order claim, payment, expiration and duplicate scans.
- Run PHP syntax checks and database smoke tests in a local XAMPP setup.

## Phase 2 - protect the terminal API

- Add device authentication for ESP32 requests.
- Use HTTPS or a trusted local network for terminal traffic.
- Add request timestamps or nonces to limit replay.
- Rate-limit invalid card and terminal requests.
- Avoid returning more student information than the terminal needs.

## Phase 3 - improve operations

- Add a terminal health/status page.
- Show clearer order ownership and recovery when a terminal disconnects.
- Add an operator action to release a stuck `IN_PROGRESS` order.
- Add structured correlation IDs for payment and device logs.
- Add automated backups and restore instructions.

## Phase 4 - user-facing improvements

- Improve mobile and accessibility support.
- Add searchable transaction exports.
- Add configurable RESTO prices and terminal settings.
- Add notifications for successful payments and low balances.

## Out of scope until the core flow is stable

- Offline payments.
- Machine-learning fraud detection.
- Cryptocurrency or multi-currency settlement.
- Native mobile applications.

These features require explicit threat modeling, accounting rules and recovery
design before implementation.
