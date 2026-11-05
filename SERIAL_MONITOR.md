# Serial monitoring and terminal logs

UniSmart Pay exposes terminal activity in three places:

1. The ESP32 Serial Monitor.
2. The terminal web pages, using logs stored in `code_appareil`.
3. The browser Web Serial panel, which reads the ESP32 USB port directly.

## ESP32 output

Open the Arduino Serial Monitor at **115200 baud**. Messages use:

```text
[HH:MM:SS] [TYPE] MESSAGE
```

Common types include `SYSTEME`, `RFID`, `PAIEMENT`, `RESPONSE`, `SOLDE`,
`SUCCESS`, `WARNING`, `ERROR` and `USER`.

Useful startup messages:

- RC522 firmware version.
- Wi-Fi connection and local IP.
- Terminal ID and active mode.
- Server URL.

## Web logs

The terminal pages display logs saved in `code_appareil`. A log can be written
with:

```http
POST /web/api/terminal/log.php
Content-Type: application/json
```

Example payload:

```json
{
  "id_terminal": 1,
  "type_message": "RFID",
  "message": "Card detected",
  "uid_carte": "AABBCCDD",
  "donnees_json": {
    "mode": "RESTO"
  }
}
```

The terminal itself sends these logs on a best-effort basis. A failed log
request must not be interpreted as a failed payment; the payment API response
is authoritative.

## Browser Web Serial

Use Chrome or Edge on a desktop computer:

1. Open the RESTO or BUVETTE terminal page.
2. Close Arduino IDE's Serial Monitor and Serial Plotter.
3. Click **Connect** in the USB Serial Monitor.
4. Select the ESP32 port.

Web Serial requires HTTPS or `http://localhost` / `http://127.0.0.1`. It is not
reliably available on mobile browsers.

## Network troubleshooting

For HTTP timeouts:

1. Confirm Apache is running.
2. Confirm the ESP32 and computer use the same network.
3. Use the computer's LAN IP in `SERVER_URL`.
4. Do not use `localhost` in the ESP32 sketch.
5. Check the computer firewall and Apache error log.

## Database troubleshooting

If logs do not appear in the web interface, verify that:

- MySQL is running.
- `web/config/config.php` points to the correct database.
- The terminal ID exists and is active.
- The `code_appareil` table exists.

Do not alter the `type_message` column based on old documentation without
checking `database/unismart_pay_schema.sql` and the current API validation.
