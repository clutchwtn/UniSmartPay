/*
 * ============================================
 * UniSmart Pay - RFID Payment Terminal
 * ESP32 WROOM-32 + RC522 RFID + 2 LEDs
 * ============================================
 * 
 * WIRING DIAGRAM (Standard ESP32 SPI Pins):
 * ESP32 Pin    RC522 Pin     LEDs
 * -----        -----         -----
 * G23  -----> MOSI
 * G19  -----> MISO
 * G18  -----> SCK
 * G5   -----> SDA/CS
 * G4   -----> RST
 * GND  -----> GND           GND (common)
 * +3.3V ----> +3.3V         +3.3V
 * G21  -----> (via 330Ω) ---- LED GREEN
 * G22  -----> (via 330Ω) ---- LED RED
 */

// Forward declaration (Arduino IDE auto-generates function prototypes before type definitions)
struct CardCheckResult;

#include <Wire.h>
#include <SPI.h>
#include <MFRC522.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>

WiFiClient wifiClient;

// ============================================
// SERIAL MONITOR LOGGER (prints + optional DB push)
// ============================================
String formatUptime() {
    unsigned long s = millis() / 1000UL;
    unsigned long hh = (s / 3600UL) % 24UL;
    unsigned long mm = (s / 60UL) % 60UL;
    unsigned long ss = s % 60UL;
    char buf[16];
    snprintf(buf, sizeof(buf), "%02lu:%02lu:%02lu", hh, mm, ss);
    return String(buf);
}

void logMessage(const String& type, const String& message) {
    Serial.print("[");
    Serial.print(formatUptime());
    Serial.print("] [");
    Serial.print(type);
    Serial.print("] ");
    Serial.println(message);
}

String apiUrlFromServerUrl(const char* serverUrl, const char* endpoint) {
    String base(serverUrl);
    const String paymentPath = "/web/api/payment.php";
    const int pathStart = base.indexOf(paymentPath);
    if (pathStart >= 0) {
        base = base.substring(0, pathStart) + "/web/api/" + endpoint;
    }
    return base;
}

// ============================================
// PINS CONFIGURATION (Standard ESP32 SPI)
// ============================================
#define SCK_PIN   18   // SCK (Serial Clock)     - GPIO18
#define MOSI_PIN  23   // MOSI (Master Out)     - GPIO23
#define MISO_PIN  19   // MISO (Master In)      - GPIO19
#define SS_PIN    5    // SDA/CS (Chip Select)  - GPIO5
#define RST_PIN   4    // RST (Reset)           - GPIO4

#define LED_GREEN 21   // Succès/Paiement accepté
#define LED_RED   22   // Erreur/Paiement refusé

// ============================================
// LED WIRING MODE
// ============================================
// If your LED is wired as: GPIO -> resistor -> LED -> GND, then LED is ACTIVE HIGH.
// If your LED is wired as: 3.3V -> resistor -> LED -> GPIO, then LED is ACTIVE LOW (common mistake).
// Symptom of ACTIVE LOW wiring: LED stays ON when pin is LOW (even at startup).
const bool LED_ACTIVE_LOW = false; // set to true if your LEDs are wired to 3.3V

// How long to keep LEDs ON after a result
const unsigned long LED_SUCCESS_HOLD_MS = 5000;
const unsigned long LED_FAIL_HOLD_MS = 5000;

inline void setLed(int pin, bool on) {
    if (LED_ACTIVE_LOW) {
        digitalWrite(pin, on ? LOW : HIGH);
    } else {
        digitalWrite(pin, on ? HIGH : LOW);
    }
}

// ============================================
// WIFI CONFIGURATION
// ============================================
const char* SSID = "YOUR_WIFI_SSID";
const char* PASSWORD = "YOUR_WIFI_PASSWORD";
// IMPORTANT: si le serveur est sur XAMPP (htdocs/UniSmartPay), l'URL est souvent:
//   http://<IP_DU_PC>/UniSmartPay/web/api/payment.php
const char* SERVER_URL = "http://YOUR_SERVER_IP/UniSmartPay/web/api/payment.php";

// ============================================
// TERMINAL MODE (RESTO / BUVETTE)
// ============================================
// Mode is dynamic and comes from the server (table `terminaux.type`) via /web/api/terminal_mode_get.php
// The same physical ESP32 can switch between RESTO and BUVETTE.
enum TerminalMode { MODE_RESTO, MODE_BUVETTE };

// IMPORTANT: set this to the terminal id used by BOTH web pages (resto.php and buvette.php).
// Default in this repo is now terminal_id=1.
const int TERMINAL_ID = 1;

// Cache terminal mode to avoid calling the server too often
TerminalMode g_mode = MODE_RESTO;
String g_modeLabel = "RESTO";
unsigned long g_modeLastFetchMs = 0;

const float AMOUNT_RESTO = 3.50;
const float AMOUNT_BUVETTE = 5.50;
const unsigned long CARD_COOLDOWN_MS = 10000;
String g_lastProcessedUid = "";
unsigned long g_lastProcessedMs = 0;

bool sendLogToServer(int terminalId, const String& type, const String& message, const String& uidCard = "", float amount = -1, int orderId = 0) {
    if (WiFi.status() != WL_CONNECTED) return false;

    String url = apiUrlFromServerUrl(SERVER_URL, "terminal/log.php");

    DynamicJsonDocument doc(512);
    doc["id_terminal"] = terminalId;
    doc["type_message"] = type;
    doc["message"] = message;
    if (uidCard.length() > 0) doc["uid_carte"] = uidCard;

    JsonObject data = doc.createNestedObject("donnees_json");
    if (amount >= 0) data["amount"] = amount;
    if (orderId > 0) data["order_id"] = orderId;
    // Dynamic mode label (from server)
    data["mode"] = g_modeLabel;

    String payload;
    serializeJson(doc, payload);

    HTTPClient http;
    http.setTimeout(5000);
    http.begin(wifiClient, url);
    http.addHeader("Content-Type", "application/json");
    int code = http.POST(payload);
    http.end();
    return code > 0;
}

// ============================================
// TERMINAL MODE FROM SERVER
// ============================================
bool refreshTerminalMode(bool force = false) {
    const unsigned long now = millis();
    if (!force && (now - g_modeLastFetchMs) < 2000UL) {
        return true;
    }
    g_modeLastFetchMs = now;

    if (WiFi.status() != WL_CONNECTED) {
        return false;
    }

    String url = apiUrlFromServerUrl(SERVER_URL, "terminal_mode_get.php");
    url += "?terminal_id=" + String(TERMINAL_ID);

    HTTPClient http;
    http.setTimeout(5000);
    http.begin(wifiClient, url);
    int code = http.GET();
    String body = http.getString();
    http.end();

    if (code <= 0) {
        return false;
    }

    DynamicJsonDocument resp(512);
    DeserializationError err = deserializeJson(resp, body);
    if (err) {
        return false;
    }

    bool ok = resp["ok"] | false;
    if (!ok) {
        return false;
    }

    String mode = String((const char*)(resp["mode"] | "RESTO"));
    mode.toUpperCase();
    if (mode == "BUVETTE") {
        g_mode = MODE_BUVETTE;
        g_modeLabel = "BUVETTE";
    } else {
        g_mode = MODE_RESTO;
        g_modeLabel = "RESTO";
    }
    return true;
}

// ============================================
// CARD CHECK (verify card exists + active + balance)
// ============================================
struct CardCheckResult {
    bool ok = false;
    String error;
    String carteStatut;
    float solde = -1;
    String prenom;
    String nom;
};

CardCheckResult checkCardInDb(const String& cardUID) {
    CardCheckResult r;

    if (WiFi.status() != WL_CONNECTED) {
        r.ok = false;
        r.error = "WiFi non connecté";
        return r;
    }

    String url = apiUrlFromServerUrl(SERVER_URL, "card_check.php");

    DynamicJsonDocument doc(192);
    doc["uid"] = cardUID;
    String payload;
    serializeJson(doc, payload);

    HTTPClient http;
    http.setTimeout(8000);
    http.begin(wifiClient, url);
    http.addHeader("Content-Type", "application/json");

    int code = http.POST(payload);
    String body = http.getString();
    http.end();

    if (code < 0) {
        r.ok = false;
        r.error = String("HTTP error: ") + http.errorToString(code);
        return r;
    }

    DynamicJsonDocument resp(1024);
    DeserializationError err = deserializeJson(resp, body);
    if (err) {
        r.ok = false;
        r.error = String("JSON parse error: ") + err.c_str();
        return r;
    }

    bool ok = resp["ok"] | false;
    if (!ok) {
        r.ok = false;
        r.error = (const char*)(resp["error"] | "Carte invalide");
        return r;
    }

    r.ok = true;
    r.carteStatut = String((const char*)(resp["carte_statut"] | ""));
    r.solde = resp["solde"] | -1;
    r.prenom = String((const char*)(resp["etudiant"]["prenom"] | ""));
    r.nom = String((const char*)(resp["etudiant"]["nom"] | ""));
    return r;
}

// ============================================
// RESTO PAYMENT (ticket fixe)
// ============================================
bool payRestoTicket(const String& cardUID) {
    const int terminalId = TERMINAL_ID;
    const float amount = AMOUNT_RESTO;

    HTTPClient http;

    DynamicJsonDocument doc(256);
    doc["card_uid"] = cardUID;
    doc["amount"] = amount;
    doc["terminal_id"] = terminalId;

    String jsonPayload;
    serializeJson(doc, jsonPayload);

    logMessage("RESTO", String("Prix ticket : ") + String(amount, 2) + " DT");
    logMessage("PAIEMENT", "Envoi au serveur...");
    sendLogToServer(terminalId, "RESTO", String("Prix ticket : ") + String(amount, 2) + " DT", "", amount);
    sendLogToServer(terminalId, "PAIEMENT", "Envoi au serveur...", cardUID, amount);

    http.setTimeout(15000);
    http.begin(wifiClient, SERVER_URL);
    http.addHeader("Content-Type", "application/json");

    int httpCode = http.POST(jsonPayload);

    logMessage("RESPONSE", String("HTTP status: ") + httpCode);

    if (httpCode < 0) {
        logMessage("ERROR", String("HTTP error: ") + http.errorToString(httpCode));
        logMessage("INFO", "Hint: vérifie SERVER_URL (IP du PC + /UniSmartPay/...), Apache ON, firewall port 80.");
        sendLogToServer(terminalId, "ERROR", String("HTTP error: ") + http.errorToString(httpCode), cardUID, amount);
        failedPayment();
        http.end();
        return false;
    }

    String response = http.getString();
    logMessage("RESPONSE", String("Body: ") + response);

    DynamicJsonDocument responseDoc(1024);
    DeserializationError jerr = deserializeJson(responseDoc, response);

    if (jerr) {
        logMessage("ERROR", String("JSON parse error: ") + jerr.c_str());
        sendLogToServer(terminalId, "ERROR", String("JSON parse error: ") + jerr.c_str(), cardUID, amount);
        failedPayment();
        http.end();
        return false;
    }

    bool success = responseDoc["success"] | false;
    const char* errorMsg = responseDoc["error"] | "";
    const char* reference = responseDoc["reference"] | "";
    float soldeApres = responseDoc["solde_apres"] | -1;

    const char* prenom = responseDoc["etudiant"]["prenom"] | "";
    const char* nom = responseDoc["etudiant"]["nom"] | "";
    if (strlen(prenom) || strlen(nom)) {
        String fullName = String(prenom) + (strlen(prenom) ? " " : "") + String(nom);
        logMessage("USER", String("Étudiant : ") + fullName);
        sendLogToServer(terminalId, "USER", String("Étudiant : ") + fullName, cardUID, amount);
    }

    if (success) {
        logMessage("SUCCESS", "Paiement accepté ✔");
        if (reference[0] != '\0') {
            logMessage("INFO", String("Reference: ") + reference);
        }
        if (soldeApres >= 0) {
            logMessage("SOLDE", String("Nouveau solde : ") + String(soldeApres, 2) + " DT");
        }
        sendLogToServer(terminalId, "SUCCESS", String("Paiement OK ref=") + reference, cardUID, amount);
        if (soldeApres >= 0) sendLogToServer(terminalId, "SOLDE", String("Nouveau solde : ") + String(soldeApres, 2) + " DT", cardUID, amount);
        successPayment();
        http.end();
        return true;
    }

    logMessage("ERROR", "Paiement refusé");
    if (errorMsg[0] != '\0') {
        logMessage("ERROR", String("Error: ") + errorMsg);
        sendLogToServer(terminalId, "ERROR", String("Paiement KO: ") + errorMsg, cardUID, amount);
    }
    failedPayment();
    http.end();
    return false;
}

// ============================================
// RFID READER INITIALIZATION
// ============================================
MFRC522 rfid(SS_PIN, RST_PIN);

// ============================================
// SETUP
// ============================================
void setup() {
    Serial.begin(115200);
    delay(1000);
    
    // Initialize pins
    pinMode(LED_GREEN, OUTPUT);
    pinMode(LED_RED, OUTPUT);
    // Force OFF state immediately (handles both active-high and active-low wiring)
    allLedsOff();
    
    // Initialize SPI
    SPI.begin(SCK_PIN, MISO_PIN, MOSI_PIN, SS_PIN);
    
    // Initialize RFID reader
    rfid.PCD_Init();
    
    Serial.println("\n\n");
    Serial.println("============================================");
    Serial.println("UniSmart Pay - RFID Payment Terminal");
    Serial.println("============================================");
    logMessage("SYSTEME", String("Terminal ID: ") + TERMINAL_ID);
    logMessage("SYSTEME", "Initializing RFID Reader...");
    
    // Check RFID firmware
    byte version = rfid.PCD_ReadRegister(MFRC522::VersionReg);
    Serial.print("RFID Firmware Version: 0x");
    Serial.println(version, HEX);
    
    if ((version == 0x00) || (version == 0xFF)) {
        logMessage("ERROR", "RFID Reader not detected!");
        blinkError(5);
        while(1);
    } else {
        logMessage("SUCCESS", "RFID Reader initialized successfully!");
        blinkSuccess(2);
    }
    
    // Connect WiFi
    connectWiFi();

    // Fetch current mode from server (best-effort)
    if (refreshTerminalMode(true)) {
        logMessage("SYSTEME", String("Mode (serveur): ") + g_modeLabel);
    } else {
        logMessage("WARNING", "Mode (serveur): indisponible, fallback RESTO");
        g_mode = MODE_RESTO;
        g_modeLabel = "RESTO";
    }

    logMessage("SYSTEME", String("Server URL: ") + SERVER_URL);
    logMessage("SYSTEME", String("WiFi SSID: ") + WiFi.SSID());
    logMessage("SYSTEME", String("Gateway: ") + WiFi.gatewayIP().toString());
    logMessage("SYSTEME", String("Local IP: ") + WiFi.localIP().toString());
    
    Serial.println("============================================");
    logMessage("INFO", "Waiting for RFID card...");
    Serial.println("============================================\n");
}

// ============================================
// MAIN LOOP
// ============================================
void loop() {
    // Check if new card is present
    if (!rfid.PICC_IsNewCardPresent()) {
        return;
    }
    
    // Select one of the cards
    if (!rfid.PICC_ReadCardSerial()) {
        return;
    }

    // Reset LEDs for a new transaction
    allLedsOff();
    
    logMessage("RFID", "Carte détectée");
    
    // Get card UID
    String cardUID = "";
    String cardUIDColon = "";
    for (byte i = 0; i < rfid.uid.size; i++) {
        if (rfid.uid.uidByte[i] < 0x10) {
            cardUID += "0";
        }
        cardUID += String(rfid.uid.uidByte[i], HEX);

        if (i > 0) {
            cardUIDColon += ":";
        }
        if (rfid.uid.uidByte[i] < 0x10) {
            cardUIDColon += "0";
        }
        cardUIDColon += String(rfid.uid.uidByte[i], HEX);
    }
    cardUID.toUpperCase();
    cardUIDColon.toUpperCase();
    logMessage("RFID", String("Carte détectée : ") + cardUIDColon);

    const unsigned long now = millis();
    if (cardUID == g_lastProcessedUid && now - g_lastProcessedMs < CARD_COOLDOWN_MS) {
        logMessage("INFO", "Carte ignoree pendant le delai anti-double scan");
        rfid.PICC_HaltA();
        rfid.PCD_StopCrypto1();
        return;
    }
    g_lastProcessedUid = cardUID;
    g_lastProcessedMs = now;
    
    // Process payment
    processPayment(cardUID);
    
    // Stop reading
    rfid.PICC_HaltA();
    rfid.PCD_StopCrypto1();
}

// ============================================
// PROCESS PAYMENT
// ============================================
void processPayment(String cardUID) {
    logMessage("PAIEMENT", "Paiement en cours...");
    
    if (WiFi.status() != WL_CONNECTED) {
        logMessage("WARNING", "WiFi disconnected. Reconnecting...");
        connectWiFi();
    }
    
    const int terminalId = TERMINAL_ID;

    // Refresh mode from server right before processing
    if (WiFi.status() == WL_CONNECTED) {
        refreshTerminalMode(false);
    }
    logMessage("SYSTEME", String("Mode actif: ") + g_modeLabel + " • Terminal ID=" + terminalId);

    // Push RFID log to DB (best-effort)
    sendLogToServer(terminalId, "RFID", String("Carte détectée : ") + cardUID, cardUID);

    // 0) Always verify card exists in DB + status + balance BEFORE doing anything else
    logMessage("SYSTEME", "Vérification carte en BD...");
    sendLogToServer(terminalId, "SYSTEME", "Vérification carte en BD...", cardUID);

    CardCheckResult check = checkCardInDb(cardUID);
    if (!check.ok) {
        logMessage("ERROR", String("Carte: ") + check.error);
        sendLogToServer(terminalId, "ERROR", String("Carte: ") + check.error, cardUID);
        failedPayment();
        return;
    }

    if (check.carteStatut.length() > 0 && check.carteStatut != "active") {
        logMessage("ERROR", String("Carte non active (statut=") + check.carteStatut + ")");
        sendLogToServer(terminalId, "ERROR", String("Carte non active (statut=") + check.carteStatut + ")", cardUID);
        failedPayment();
        return;
    }

    if (check.prenom.length() || check.nom.length()) {
        String fullName = check.prenom + (check.prenom.length() ? " " : "") + check.nom;
        logMessage("USER", String("Étudiant : ") + fullName);
        sendLogToServer(terminalId, "USER", String("Étudiant : ") + fullName, cardUID);
    }
    if (check.solde >= 0) {
        logMessage("SOLDE", String("Solde actuel : ") + String(check.solde, 2) + " DT");
        sendLogToServer(terminalId, "SOLDE", String("Solde actuel : ") + String(check.solde, 2) + " DT", cardUID);
    }

    if (g_mode == MODE_BUVETTE) {
        // 1) Claim pending order for this terminal
        String claimUrl = apiUrlFromServerUrl(SERVER_URL, "terminal_order_claim.php");
        claimUrl += "?terminal_id=" + String(terminalId);

        logMessage("PAIEMENT", "Recherche commande en attente...");
        sendLogToServer(terminalId, "PAIEMENT", "Recherche commande en attente...");

        HTTPClient httpClaim;
        httpClaim.setTimeout(15000);
        httpClaim.begin(wifiClient, claimUrl);
        int claimCode = httpClaim.GET();
        logMessage("RESPONSE", String("HTTP claim status: ") + claimCode);

        if (claimCode < 0) {
            logMessage("ERROR", String("HTTP claim error: ") + httpClaim.errorToString(claimCode));
            sendLogToServer(terminalId, "ERROR", String("Claim error: ") + httpClaim.errorToString(claimCode));
            failedPayment();
            httpClaim.end();
            return;
        }

        String claimBody = httpClaim.getString();
        logMessage("RESPONSE", String("Claim body: ") + claimBody);
        httpClaim.end();

        DynamicJsonDocument claimDoc(2048);
        DeserializationError claimErr = deserializeJson(claimDoc, claimBody);
        if (claimErr) {
            logMessage("ERROR", String("JSON parse error (claim): ") + claimErr.c_str());
            sendLogToServer(terminalId, "ERROR", String("Claim JSON parse error: ") + claimErr.c_str());
            failedPayment();
            return;
        }

        bool ok = claimDoc["ok"] | false;
        bool found = claimDoc["found"] | false;
        const char* errMsg = claimDoc["error"] | "";

        if (!ok) {
            logMessage("ERROR", "Claim failed!");
            if (errMsg[0] != '\0') {
                logMessage("ERROR", String("Error: ") + errMsg);
                sendLogToServer(terminalId, "ERROR", String("Claim failed: ") + errMsg);
            }
            failedPayment();
            return;
        }

        if (!found) {
            logMessage("WARNING", "Panier vide / aucune commande web en attente.");
            sendLogToServer(terminalId, "WARNING", "Panier vide / aucune commande web en attente.");
            // Not a payment failure: keep LEDs off.
            allLedsOff();
            return;
        }

        int orderId = claimDoc["order"]["order_id"] | 0;
        float amount = claimDoc["order"]["montant"] | 0;

        if (orderId <= 0 || amount <= 0) {
            Serial.println("❌ Invalid order data.");
            logMessage("ERROR", "Commande invalide.");
            sendLogToServer(terminalId, "ERROR", "Commande invalide.", cardUID);
            failedPayment();
            return;
        }

        logMessage("PAIEMENT", String("Commande détectée : #") + orderId + " montant=" + String(amount, 2) + " DT");
        sendLogToServer(terminalId, "PAIEMENT", String("Commande détectée : #") + orderId + " montant=" + String(amount, 2) + " DT", "", amount, orderId);

        // 1bis) Check balance >= amount BEFORE charging
        if (check.solde >= 0 && check.solde < amount) {
            logMessage("ERROR", "Solde insuffisant");
            sendLogToServer(terminalId, "ERROR", "Solde insuffisant", cardUID, amount, orderId);
            failedPayment();
            return;
        }

        // 2) Pay that order with card UID
        String payUrl = apiUrlFromServerUrl(SERVER_URL, "terminal_order_pay.php");

        DynamicJsonDocument doc(384);
        doc["order_id"] = orderId;
        doc["terminal_id"] = terminalId;
        doc["card_uid"] = cardUID;

        String jsonPayload;
        serializeJson(doc, jsonPayload);

        logMessage("PAIEMENT", "Envoi au serveur...");
        sendLogToServer(terminalId, "PAIEMENT", "Envoi au serveur...", cardUID, amount, orderId);

        HTTPClient httpPay;
        httpPay.setTimeout(15000);
        httpPay.begin(wifiClient, payUrl);
        httpPay.addHeader("Content-Type", "application/json");

        int httpCode = httpPay.POST(jsonPayload);
        logMessage("RESPONSE", String("HTTP pay status: ") + httpCode);

        if (httpCode < 0) {
            logMessage("ERROR", String("HTTP pay error: ") + httpPay.errorToString(httpCode));
            logMessage("INFO", "Hint: vérifie SERVER_URL (IP du PC + /UniSmartPay/...), Apache ON, firewall port 80.");
            sendLogToServer(terminalId, "ERROR", String("Pay HTTP error: ") + httpPay.errorToString(httpCode), cardUID, amount, orderId);
            failedPayment();
            httpPay.end();
            return;
        }

        String response = httpPay.getString();
        logMessage("RESPONSE", String("Body: ") + response);

        DynamicJsonDocument responseDoc(2048);
        DeserializationError jerr = deserializeJson(responseDoc, response);

        if (jerr) {
            logMessage("ERROR", String("JSON parse error (pay): ") + jerr.c_str());
            sendLogToServer(terminalId, "ERROR", String("Pay JSON parse error: ") + jerr.c_str(), cardUID, amount, orderId);
            failedPayment();
            httpPay.end();
            return;
        }

        bool success = responseDoc["success"] | false;
        const char* errorMsg = responseDoc["error"] | "";
        const char* reference = responseDoc["reference"] | "";
        float soldeApres = responseDoc["solde_apres"] | -1;

        const char* prenom = responseDoc["etudiant"]["prenom"] | "";
        const char* nom = responseDoc["etudiant"]["nom"] | "";
        if (strlen(prenom) || strlen(nom)) {
            String fullName = String(prenom) + (strlen(prenom) ? " " : "") + String(nom);
            logMessage("USER", String("Étudiant : ") + fullName);
            sendLogToServer(terminalId, "USER", String("Étudiant : ") + fullName, cardUID, amount, orderId);
        }

        if (success) {
            logMessage("SUCCESS", "Paiement validé ✔");
            if (reference[0] != '\0') {
                logMessage("INFO", String("Reference: ") + reference);
            }
            if (soldeApres >= 0) {
                logMessage("SOLDE", String("Nouveau solde : ") + String(soldeApres, 2) + " DT");
            }
            sendLogToServer(terminalId, "SUCCESS", String("Paiement OK ref=") + reference, cardUID, amount, orderId);
            if (soldeApres >= 0) sendLogToServer(terminalId, "SOLDE", String("Nouveau solde : ") + String(soldeApres, 2) + " DT", cardUID, amount, orderId);
            successPayment();
        } else {
            logMessage("ERROR", "Paiement refusé");
            if (errorMsg[0] != '\0') {
                logMessage("ERROR", String("Error: ") + errorMsg);
                sendLogToServer(terminalId, "ERROR", String("Paiement KO: ") + errorMsg, cardUID, amount, orderId);
            }
            failedPayment();
        }

        httpPay.end();
        return;
    }

    // RESTO mode: ticket fixe (check balance before paying)
    if (check.solde >= 0 && check.solde < AMOUNT_RESTO) {
        logMessage("ERROR", "Solde insuffisant");
        sendLogToServer(terminalId, "ERROR", "Solde insuffisant", cardUID, AMOUNT_RESTO);
        failedPayment();
        return;
    }
    payRestoTicket(cardUID);
}

// ============================================
// PAYMENT SUCCESS
// ============================================
void successPayment() {
    logMessage("SUCCESS", "LED verte ON (paiement OK)");
    setLed(LED_RED, false);
    setLed(LED_GREEN, true);
    delay((int)LED_SUCCESS_HOLD_MS);
    allLedsOff();
}

// ============================================
// PAYMENT FAILED
// ============================================
void failedPayment() {
    logMessage("ERROR", "LED rouge ON (paiement KO)");
    setLed(LED_GREEN, false);
    setLed(LED_RED, true);
    delay((int)LED_FAIL_HOLD_MS);
    allLedsOff();
}

// ============================================
// LED HELPERS
// ============================================
void allLedsOff() {
    setLed(LED_GREEN, false);
    setLed(LED_RED, false);
}

void blinkSuccess(int times) {
    for (int i = 0; i < times; i++) {
        setLed(LED_GREEN, true);
        delay(200);
        setLed(LED_GREEN, false);
        delay(200);
    }
}

void blinkError(int times) {
    for (int i = 0; i < times; i++) {
        setLed(LED_RED, true);
        delay(200);
        setLed(LED_RED, false);
        delay(200);
    }
}

// ============================================
// WIFI FUNCTIONS
// ============================================
void connectWiFi() {
    Serial.println("\n📡 Connecting to WiFi...");
    WiFi.mode(WIFI_STA);
    WiFi.begin(SSID, PASSWORD);
    
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 20) {
        delay(500);
        Serial.print(".");
        attempts++;
    }
    
    if (WiFi.status() == WL_CONNECTED) {
        Serial.println("\n✅ WiFi connected!");
        Serial.print("IP Address: ");
        Serial.println(WiFi.localIP());
    } else {
        Serial.println("\n❌ WiFi connection failed!");
    }
}

// ============================================
// END OF CODE
// ============================================
