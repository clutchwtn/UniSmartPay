# 🔌 UniSmart Pay - RFID Terminal Setup Guide

## 📋 Hardware Components

| Composant | Modèle | Quantité |
|-----------|--------|----------|
| Microcontroller | ESP32 WROOM-32 | 1 |
| RFID Reader | RC522 | 1 |
| LED Green | 5mm | 1 |
| LED Red | 5mm | 1 |
| Resistor | 330Ω | 2 |
| Breadboard + Cables | Jumpers | - |
| Power Supply | USB 5V ou +3.3V | 1 |

---

## 🔗 WIRING DIAGRAM

### ESP32 ↔ RC522 (RFID Reader)

```
ESP32 Pin     RC522 Pin
─────────     ─────────
G19    ───→   SDA (Chip Select)
G18    ───→   SCK (Serial Clock)
G23    ───→   MOSI (Master Out)
G0     ───→   MISO (Master In)
GND    ───→   GND
+3.3V  ───→   +3.3V
RST    ───→   RST (Pin 5 recommended)
```

### ESP32 ↔ LEDs

```
ESP32 Pin     LED Connection
─────────     ──────────────
G21    ───→   [330Ω] ──→ GREEN LED ──→ GND
G22    ───→   [330Ω] ──→ RED LED   ──→ GND
+3.3V  ───→   Both LED positive terminals (optional second rail)
GND    ───→   Common ground
```

---

## ⚙️ Software Setup

### 1. Arduino IDE Configuration

**Install ESP32 Plugin:**
- File > Preferences
- Additional Boards Manager: `https://dl.espressif.com/dl/package_esp32_index.json`
- Tools > Board > Boards Manager → Search "ESP32" → Install

**Select Board:**
- Tools > Board > ESP32 Arduino > ESP32 Dev Module

### 2. Required Libraries

Install via Sketch > Include Library > Manage Libraries:

```
✅ MFRC522 by GithubCommunity (v1.4.10)
✅ ArduinoJson by Benoit Blanchon (v6.21.0)
✅ HTTPClient (built-in)
✅ WiFi (built-in)
✅ SPI (built-in)
✅ Wire (built-in)
```

### 3. Configure WiFi Credentials

Edit these constants in `unismart_pay_terminal.ino`:

```cpp
const char* SSID = "YOUR_WIFI_SSID";
const char* PASSWORD = "YOUR_WIFI_PASSWORD";
const char* SERVER_URL =
  "http://YOUR_SERVER_IP/UniSmartPay/web/api/payment.php";
```

---

## 🔧 Configuration Updates

### Update Constants:

```cpp
// PINS (Already configured for your setup)
#define SCK_PIN   18   // SCK
#define MOSI_PIN  23   // MOSI
#define MISO_PIN  19   // MISO
#define SS_PIN    5    // SDA/CS
#define RST_PIN   4    // RST
#define LED_GREEN 21   // Green LED pin
#define LED_RED   22   // Red LED pin

// WiFi
SSID = "YourNetworkName"
PASSWORD = "YourPassword"

// Server
SERVER_URL = "http://YOUR_SERVER_IP/UniSmartPay/web/api/payment.php"
```

Use the computer's LAN IP for `YOUR_SERVER_IP`; do not use `localhost`, because
the ESP32 resolves that name to itself. Do not commit real Wi-Fi credentials.

---

## 🚀 Upload & Test

### 1. Connect ESP32 to Computer
- USB cable to ESP32 WROOM-32

### 2. Upload Code
- Tools > Port > Select COM port (ESP32)
- Sketch > Upload
- Wait for upload complete

### 3. Open Serial Monitor
- Tools > Serial Monitor (115200 baud)
- Should see initialization messages

### Expected Output:

```
============================================
UniSmart Pay - RFID Payment Terminal
============================================
Initializing RFID Reader...
RFID Firmware Version: 0x92
✅ RFID Reader initialized successfully!
✅ WiFi connected!
IP Address: 192.168.1.XXX
============================================
🔴 Waiting for RFID card...
```

---

## 🧪 Testing LEDs

### Manual LED Test (Optional)

Create a test sketch:

```cpp
void setup() {
    pinMode(21, OUTPUT);  // Green
    pinMode(16, OUTPUT);  // Red
}

void loop() {
    // Blink green
    digitalWrite(21, HIGH);
    delay(500);
    digitalWrite(21, LOW);
    delay(500);
    
    // Blink red
    digitalWrite(16, HIGH);
    delay(500);
    digitalWrite(16, LOW);
    delay(500);
}
```

---

## 📱 Testing RFID Payment

1. **Place RFID card near reader**
2. **Watch Serial Monitor for:**
   - Card UID detected
   - API request sent
   - Response received

3. **LED Behavior:**
   - 🟢 **GREEN:** Payment successful
   - 🔴 **RED:** Payment failed
   - Both OFF: Idle state

---

## 🔌 Physical Assembly

### Breadboard Layout:

```
┌─────────────────────────┐
│   ESP32 Dev Board       │
│                         │
│ G19 ──┐   ┌── G21 ──┬──────┐
│ G18 ──┼─R1─┤       │330Ω  │ LED Green
│ G23 ──┤   └─ GND ──┴──────┘
│ G0  ──┤   ┌── G16 ──┬──────┐
│ GND ──┼─R2─┤       │330Ω  │ LED Red
│ +3.3V ┴   └─ GND ──┴──────┘
└─────────────────────────┘
        │
        ↓ (SPI Bus)
    RC522 Board
    (RFID Reader)
```

---

## ⚠️ Troubleshooting

| Issue | Solution |
|-------|----------|
| RFID not detected | Check SPI wiring, verify firmware version |
| WiFi won't connect | Check SSID/password, verify router signal |
| LEDs not lighting | Check 330Ω resistors, verify GPIO pins |
| Serial garbage | Change baud rate to 115200 |
| Upload fails | Select correct board & COM port |

---

## 📡 API Endpoint Expected

Your server should have:

**POST /api/payment**

Request:
```json
{
    "card_uid": "1A2B3C4D",
    "amount": 5.50,
    "terminal_id": 1
}
```

Response:
```json
{
    "success": true,
    "balance": 95.50,
    "message": "Payment successful"
}
```

---

## 🔐 Security Notes

⚠️ **Important:**
- Don't commit WiFi credentials to public repos
- Use environment variables or secure config
- Implement HTTPS in production
- Validate card UID server-side
- Add transaction logging

---

## 📚 References

- [ESP32 Documentation](https://docs.espressif.com/projects/esp-idf/en/latest/)
- [MFRC522 Library](https://github.com/miguelbalboa/rfid)
- [RC522 Datasheet](https://datasheetspdf.com/pdf/MFRC522)

---

**Version:** 1.0  
**Last Updated:** April 2, 2026  
**Status:** ✅ Production Ready
