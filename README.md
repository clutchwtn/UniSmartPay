# UniSmart Pay

UniSmart Pay est une plateforme de paiement universitaire basée sur PHP/PDO et MySQL.
Elle comprend un portail étudiant, une gestion de buvette et un terminal RFID ESP32
optionnel.

## Fonctionnalités

- Portail étudiant: connexion, solde et historique des opérations.
- Buvette: scan de codes-barres, panier et paiement transactionnel.
- Terminal RFID: ESP32 + RC522, paiement RESTO à montant fixe ou paiement d'une
  commande BUVETTE créée depuis le site.
- Journalisation des scans, paiements et erreurs dans `code_appareil`.

## Démarrage rapide

Prérequis: XAMPP avec Apache/MySQL, PHP 8.x recommandé, et un navigateur récent.

1. Copier le projet dans `C:\xampp\htdocs\UniSmartPay`.
2. Démarrer **Apache** et **MySQL** dans XAMPP.
3. Importer `database/unismart_pay_schema.sql` avec phpMyAdmin.
4. Ouvrir `http://localhost/UniSmartPay/web/`.

Configuration MySQL par défaut: base `unismart_pay`, utilisateur `root`, mot de
passe vide. Modifier `web/config/config.php` si votre installation diffère.

Les identifiants de démonstration sont documentés dans
[`DATABASE-SETUP.md`](DATABASE-SETUP.md). Changez-les avant toute utilisation réelle.

## Terminal ESP32

Le sketch recommandé est [`esp32/unismart_pay_terminal.ino`](esp32/unismart_pay_terminal.ino).
Il lit l'UID d'une carte RC522, vérifie la carte, puis appelle le serveur PHP.
Suivre [`esp32/SETUP_GUIDE.md`](esp32/SETUP_GUIDE.md) pour le câblage, les bibliothèques
et la configuration réseau.

Le PC et l'ESP32 doivent être sur le même réseau. Dans le sketch, `SERVER_URL`
doit utiliser l'adresse LAN du PC, jamais `localhost`.

## API principale

| Usage | Endpoint |
|---|---|
| Vérifier une carte | `POST web/api/card_check.php` |
| Paiement RESTO legacy | `POST web/api/payment.php` |
| Réserver une commande BUVETTE | `GET web/api/terminal_order_claim.php?terminal_id=1` |
| Payer une commande BUVETTE | `POST web/api/terminal_order_pay.php` |
| Lire le mode du terminal | `GET web/api/terminal_mode_get.php?terminal_id=1` |
| Ajouter un log terminal | `POST web/api/terminal/log.php` |

## Documentation

- [`web/INSTALLATION.md`](web/INSTALLATION.md): installation de l'application.
- [`DATABASE-SETUP.md`](DATABASE-SETUP.md): import et vérification MySQL.
- [`esp32/SETUP_GUIDE.md`](esp32/SETUP_GUIDE.md): montage et téléversement ESP32.
- [`SERIAL_MONITOR.md`](SERIAL_MONITOR.md): logs et diagnostic.
- [`NODE_RFID_SERVER.md`](NODE_RFID_SERVER.md): serveur série Node.js alternatif.
