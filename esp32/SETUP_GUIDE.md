# ESP32 RFID terminal setup

Firmware: `esp32/unismart_pay_terminal.ino`.

## Hardware

- ESP32 WROOM-32 (ESP32 Dev Module).
- RC522 RFID reader. It runs on 3.3 V: do not power it from 5 V.
- One green and one red LED, each with a 330 Ω resistor.

## Wiring

| ESP32 | RC522 |
|---|---|
| GPIO 18 | SCK |
| GPIO 23 | MOSI |
| GPIO 19 | MISO |
| GPIO 5 | SDA / SS |
| GPIO 4 | RST |
| GND | GND |
| 3V3 | 3.3V |

LEDs: GPIO 21 → resistor → green LED → GND, and GPIO 22 → resistor → red LED → GND.
If you wire them to 3.3 V instead of GND, set `LED_ACTIVE_LOW = true` in the sketch.

## Arduino IDE

1. In **Preferences → Additional boards manager URLs**, add
   `https://espressif.github.io/arduino-esp32/package_esp32_index.json`.
2. In the Boards Manager, install **esp32 by Espressif Systems**.
3. In the Library Manager, install **MFRC522** and **ArduinoJson** (version 6.x).
4. Select **ESP32 Dev Module** and the ESP32's USB port.

## Configure the sketch

```cpp
const char* SSID = "YOUR_WIFI_SSID";
const char* PASSWORD = "YOUR_WIFI_PASSWORD";
const char* SERVER_URL = "http://YOUR_SERVER_IP:8080/UniSmartPay/web/api/payment.php";
const char* TERMINAL_TOKEN = "YOUR_TERMINAL_TOKEN";
const int TERMINAL_ID = 1;
const float AMOUNT_RESTO = 3.50;
```

- **`SERVER_URL`:** use the server computer's LAN IP, for example `192.168.1.25`.
  `localhost` would point at the ESP32 itself. Include the port: `8080` with
  Docker, none with XAMPP on port 80. The other endpoints are derived from this URL.
- **`TERMINAL_TOKEN` and `TERMINAL_ID`:** the token printed by
  `scripts/create_terminal.php`, and the id of that same terminal.
- **`AMOUNT_RESTO`:** only used for the terminal's own messages. The server
  always charges the price in the `config_resto` table, whatever the terminal
  sends, so keep the two equal to avoid confusing logs.

Don't commit real Wi-Fi credentials or tokens.

## How it works

One ESP32 serves both modes. The mode is stored on the server: opening the
RESTO or BUVETTE terminal page in the browser switches terminal 1 to that mode,
and the ESP32 reads it before each payment.

- **RESTO:** a card is presented → the terminal checks the card → the server
  charges the configured ticket price.
- **BUVETTE:** the cashier scans products on the BUVETTE page and starts the
  payment → a card is presented → the terminal claims that waiting order and pays it.
- Green LED: payment accepted. Red LED: rejected or error.
- The same card is ignored for 10 seconds after a payment, to avoid double scans.

## Upload and test

1. Upload the sketch, then open the Serial Monitor at 115200 baud.
2. Check that the RC522 firmware version is shown and Wi-Fi connects.
3. Present a card that is assigned to a student and active.

## Troubleshooting

| Symptom | Check |
|---|---|
| RC522 not detected | 3.3 V and GND, the SPI wires, GPIO 5 and GPIO 4. |
| Wi-Fi does not connect | SSID and password; the ESP32 only supports 2.4 GHz networks. |
| HTTP timeout | LAN IP and port in `SERVER_URL`, server running, and the computer's firewall. |
| `Terminal not authorized` | `TERMINAL_TOKEN` and `TERMINAL_ID` belong to the same active terminal. |
| `Carte introuvable` | The card UID is assigned to a student (Administration → assign card). |
| Upload fails | Board and port; hold the BOOT button while uploading. |
| LED stays off | LED polarity, resistor, GPIO 21/22 and `LED_ACTIVE_LOW`. |

More on logs and serial debugging: [`../SERIAL_MONITOR.md`](../SERIAL_MONITOR.md).

## HTTPS (to do)

The firmware uses plain HTTP, so the terminal token can be read by anyone on
the same network. Use it only on a trusted network for now. Before enabling
`UNISMARTPAY_FORCE_HTTPS=1` on the server, move the firmware to
`WiFiClientSecure` with a pinned certificate. Otherwise the HTTPS redirect
breaks the terminal.
