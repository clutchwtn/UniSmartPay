# Terminal logs and serial debugging

Terminal activity can be followed in three places:

1. **Arduino Serial Monitor:** the ESP32's own output.
2. **Terminal pages:** logs stored in the `code_appareil` table.
3. **Web Serial panel:** on the terminal pages, it reads the ESP32's USB port directly.

## Serial output

Open the Serial Monitor at **115200 baud**. Lines look like:

```text
[HH:MM:SS] [TYPE] MESSAGE
```

At startup, check for the RC522 firmware version, the Wi-Fi connection and
local IP, the terminal id and mode, and the server URL.

## Stored logs

The terminal sends its logs to the server; they are best-effort, so a failed
log doesn't mean a failed payment (the payment response is what counts).
Admins can read them with `GET /web/api/terminal_logs.php?terminal_id=1`.
Card UIDs are masked in that response.

To write a log by hand, for example when testing without the device:

```bash
curl -X POST http://localhost:8080/UniSmartPay/web/api/terminal/log.php \
  -H "Content-Type: application/json" \
  -H "X-Terminal-Token: YOUR_TERMINAL_TOKEN" \
  -d '{"id_terminal": 1, "type_message": "RFID", "message": "Card detected", "uid_carte": "AABBCCDD"}'
```

Unknown `type_message` values are stored as `INFO`.

## Web Serial panel

1. Use Chrome or Edge on a desktop computer, on `http://localhost` or HTTPS.
2. Close the Arduino IDE Serial Monitor and Serial Plotter (only one program
   can use the port).
3. Open the RESTO or BUVETTE terminal page, click **Connecter** and pick the
   ESP32's port.

## Common problems

| Symptom | Check |
|---|---|
| ESP32 gets HTTP timeouts | Server running, LAN IP and port in `SERVER_URL`, same network, firewall. |
| Logs don't appear on the page | Terminal token and id are right (a wrong token returns 401), and the terminal is active. |
| Web Serial can't connect | The Arduino IDE still has the port open, or the page isn't on `localhost`/HTTPS. |
