# UniSmart Pay - Guide du terminal RFID ESP32

Ce guide concerne `unismart_pay_terminal.ino`, le sketch recommandé. Le fichier
`unismart_pay.ino` est un ancien prototype avec écran LCD et boutons; ne pas le
utiliser pour le câblage décrit ici.

## Matériel

| Composant | Modèle | Qté |
|---|---|---:|
| Microcontrôleur | ESP32 WROOM-32 | 1 |
| Lecteur RFID | RC522 | 1 |
| LED verte / rouge | 5 mm | 1 / 1 |
| Résistances | 330 Ω | 2 |
| Breadboard et câbles | Jumpers | - |

## Câblage

Le RC522 doit être alimenté en **3,3 V**, pas en 5 V.

```text
ESP32 GPIO    RC522
-----------    --------
18             SCK
23             MOSI
19             MISO
5              SDA / SS / CS
4              RST
GND            GND
3V3            3.3V
```

LED verte: GPIO 21 -> résistance 330 ohms -> LED -> GND.
LED rouge: GPIO 22 -> résistance 330 ohms -> LED -> GND.

## Installation logicielle

1. Dans **Preferences > Additional Boards Manager URLs**, ajouter:
   `https://dl.espressif.com/dl/package_esp32_index.json`.
2. Installer **esp32 by Espressif Systems** dans le Boards Manager.
3. Sélectionner **ESP32 Dev Module** et le port USB de la carte.
4. Installer `MFRC522` et `ArduinoJson 6.x` dans le Library Manager.

`WiFi`, `HTTPClient` et `SPI` sont fournis par le package ESP32.

## Configuration

Modifier ces constantes dans `unismart_pay_terminal.ino`:

```cpp
const char* SSID = "YOUR_WIFI_SSID";
const char* PASSWORD = "YOUR_WIFI_PASSWORD";
const char* SERVER_URL =
  "http://YOUR_SERVER_IP/UniSmartPay/web/api/payment.php";
```

`YOUR_SERVER_IP` est l'adresse LAN du PC qui exécute XAMPP, par exemple
`192.168.1.25`. L'ESP32 ne peut pas atteindre le PC avec `localhost`.
Ne pas publier de vrais identifiants Wi-Fi.

Le terminal utilise l'identifiant `TERMINAL_ID` défini dans le sketch. Il doit
correspondre au terminal configuré dans l'application web.

## Téléversement et test

1. Connecter l'ESP32 en USB.
2. Sélectionner son port dans **Tools > Port**, puis téléverser le sketch.
3. Ouvrir le Serial Monitor à **115200 bauds**.
4. Vérifier la détection du firmware RC522 et la connexion Wi-Fi.
5. Présenter une carte RFID assignée à un étudiant.

Le PC et l'ESP32 doivent être sur le même réseau. Apache doit être démarré et
le pare-feu doit autoriser les connexions entrantes vers le serveur HTTP.

## Fonctionnement

Au démarrage, le terminal récupère le mode du terminal depuis le serveur.
En mode RESTO, il débite 3,50 DT. En mode BUVETTE, il réserve puis paie la
plus ancienne commande en attente créée depuis le site.

- LED verte: paiement accepté.
- LED rouge: paiement refusé ou erreur.
- LEDs éteintes: attente ou aucune commande BUVETTE.
- Une même carte est ignorée pendant 10 secondes après traitement pour éviter
  un double scan.

## Dépannage

| Problème | Vérification |
|---|---|
| RC522 non détecté | Vérifier 3,3 V, GND, SS=GPIO5, RST=GPIO4 et les lignes SPI. |
| Wi-Fi impossible | Vérifier SSID, mot de passe et réseau partagé avec le PC. |
| Timeout HTTP | Vérifier l'IP du PC, Apache, le pare-feu et l'URL complète. |
| LED éteinte | Vérifier résistance, polarité et GPIO21/GPIO22. |
| Upload impossible | Sélectionner le bon modèle et port; maintenir BOOT si nécessaire. |
| Carte introuvable | Assigner l'UID dans l'administration et vérifier son format. |

## Endpoints utilisés

Le sketch appelle notamment:

- `POST /web/api/card_check.php`
- `POST /web/api/payment.php` en mode RESTO
- `GET /web/api/terminal_order_claim.php` en mode BUVETTE
- `POST /web/api/terminal_order_pay.php` en mode BUVETTE
- `GET /web/api/terminal_mode_get.php`
- `POST /web/api/terminal/log.php`
