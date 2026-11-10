# ESP32 RFID terminal setup

This guide covers the supported sketch:

```text
esp32/unismart_pay_terminal.ino
```

## Hardware

- ESP32 WROOM-32.
- RC522 RFID reader.
- One green and one red LED.
- Two 330 ohm resistors.
- Breadboard and jumper wires.

The RC522 is a 3.3 V device. Do not power it from 5 V.

## Wiring

| ESP32 | RC522 |
|---|---|
| GPIO 18 | SCK |
| GPIO 23 | MOSI |
| GPIO 19 | MISO |
| GPIO 5 | SDA / SS / CS |
| GPIO 4 | RST |
| GND | GND |
| 3V3 | 3.3V |

Connect GPIO 21 through a 330 ohm resistor to the green LED and GPIO 22
through a 330 ohm resistor to the red LED. Connect the other LED terminals to
GND.

## Arduino IDE

1. Add this Boards Manager URL:
   `https://dl.espressif.com/dl/package_esp32_index.json`
2. Install **esp32 by Espressif Systems**.
3. Select **ESP32 Dev Module**.
4. Install the `MFRC522` and `ArduinoJson 6.x` libraries.
5. Select the ESP32 USB port.

`WiFi`, `HTTPClient` and `SPI` are supplied by the ESP32 board package.

## Configure the sketch

Set these values in `unismart_pay_terminal.ino`:

```cpp
const char* SSID = "YOUR_WIFI_SSID";
const char* PASSWORD = "YOUR_WIFI_PASSWORD";
const char* SERVER_URL =
  "http://YOUR_SERVER_IP/UniSmartPay/web/api/payment.php";
const char* TERMINAL_TOKEN = "YOUR_TERMINAL_TOKEN";
```

`YOUR_SERVER_IP` is the computer's LAN address. Do not use `localhost`, and do
not commit real credentials. Set `TERMINAL_TOKEN` to the token assigned to the
same terminal in the `terminaux` table. `TERMINAL_ID` must match that terminal.

## Upload and test

1. Connect the ESP32 by USB.
2. Select the correct board and port.
3. Upload the sketch.
4. Open Serial Monitor at 115200 baud.
5. Confirm that the RC522 firmware is detected and Wi-Fi connects.
6. Present an active card assigned to a student.

The computer and ESP32 must be on the same network. Apache must be running, and
the computer firewall must allow the HTTP connection.

## Payment behavior

- **RESTO:** charges the fixed `AMOUNT_RESTO` value.
- **BUVETTE:** claims the oldest pending order for the terminal, then charges
  the order amount.
- Green LED: payment accepted.
- Red LED: payment rejected or an error occurred.
- A card already processed is ignored for 10 seconds to reduce duplicate scans.

## Troubleshooting

| Symptom | Check |
|---|---|
| RC522 not detected | 3.3 V, GND, GPIO 5, GPIO 4 and all SPI wires. |
| Wi-Fi fails | SSID, password and shared network. |
| HTTP timeout | LAN IP, Apache, firewall and full `SERVER_URL`. |
| Card not found | UID assignment, active status and UID format. |
| Upload fails | Board, port and BOOT button. |
| LED does not light | Polarity, resistor and GPIO 21/22. |

## TODO on device

The sketch currently talks plain HTTP. Before exposing the server with
`UNISMARTPAY_FORCE_HTTPS=1` (which redirects HTTP to HTTPS), move the firmware
to `WiFiClientSecure` with a pinned certificate (or CA) so the
`X-Terminal-Token` header is never sent in clear text. The HTTP redirect would
otherwise break the terminals.

## API calls

The sketch uses:

- `POST /web/api/card_check.php`
- `POST /web/api/payment.php`
- `GET /web/api/terminal_order_claim.php`
- `POST /web/api/terminal_order_pay.php`
- `GET /web/api/terminal_mode_get.php`
- `POST /web/api/terminal/log.php`
