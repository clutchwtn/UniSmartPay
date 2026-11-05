# UniSmart Pay - Installation web (XAMPP)

## Prérequis

- XAMPP avec Apache et MySQL
- PHP 8.x recommandé
- Navigateur récent (Chrome/Edge recommandé pour caméra et Web Serial)

## Installation

1. Copier le projet dans `C:\xampp\htdocs\UniSmartPay`.
2. Vérifier `web/config/config.php`:
   - `DB_NAME = unismart_pay`
   - `DB_USER = root`
   - `DB_PASS = ''` (configuration XAMPP courante)
3. Importer `database/unismart_pay_schema.sql` avec phpMyAdmin.
4. Démarrer **Apache** et **MySQL** dans le panneau XAMPP.
5. Ouvrir `http://localhost/UniSmartPay/web/`.

Si Apache utilise un port différent de 80, adapter les URLs de l'application
et du terminal ESP32.

## URLs principales

- Accueil: `http://localhost/UniSmartPay/web/`
- Administration: `http://localhost/UniSmartPay/web/admin/login.php`
- Portail étudiant: `http://localhost/UniSmartPay/web/etudiant/login.php`

Les comptes créés par le script SQL sont indiqués dans
[`../DATABASE-SETUP.md`](../DATABASE-SETUP.md). Ils sont destinés au
développement uniquement; changez-les avant toute utilisation réelle.

## Structure

- `web/config/`: configuration et connexion PDO.
- `web/includes/`: sessions, CSRF, helpers et mise en page.
- `web/etudiant/`: portail étudiant et paiement buvette.
- `web/admin/`: administration, étudiants, cartes et terminaux.
- `web/api/`: recherche produit, checkout et API du terminal RFID.

## Vérification fonctionnelle

1. Se connecter à l'administration.
2. Vérifier qu'un étudiant possède un compte et un solde.
3. Assigner un UID RFID à cet étudiant.
4. En mode BUVETTE, créer une commande depuis la page de terminal.
5. Présenter la carte au terminal ESP32 et vérifier le statut de la commande.

## Terminal RFID

Voir [`../esp32/SETUP_GUIDE.md`](../esp32/SETUP_GUIDE.md). Pour un ESP32 sur le
réseau local, `SERVER_URL` doit utiliser l'adresse LAN du PC:

```text
http://192.168.1.25/UniSmartPay/web/api/payment.php
```

L'ESP32 et le PC doivent être sur le même réseau. Apache doit être démarré et
le pare-feu doit autoriser les connexions vers le serveur HTTP.

## Sécurité

- Utiliser HTTPS en production.
- Ne pas conserver les comptes de démonstration.
- Ne pas placer de mots de passe Wi-Fi dans un dépôt public.
- Garder la validation serveur et la protection CSRF activées.
- Restreindre l'accès MySQL et les endpoints du terminal au réseau nécessaire.

## Dépannage

### Erreur de connexion MySQL

Vérifier que MySQL est démarré, que `web/config/config.php` correspond à votre
installation et que `unismart_pay` a été importée.

### Carte RFID introuvable

Vérifier que l'UID est assigné à l'étudiant et que la carte est active. Le
terminal accepte l'UID hexadécimal compact et sa forme séparée par `:`.

### Timeout ESP32

Vérifier l'IP LAN dans `SERVER_URL`, le réseau Wi-Fi, Apache et le pare-feu du
PC. `localhost` ne fonctionne pas depuis l'ESP32.

### Erreur de session

Vérifier que les cookies sont autorisés et que PHP peut écrire dans son
répertoire de sessions. Consulter les logs Apache/PHP dans XAMPP.

## Documentation associée

- [`../DATABASE-SETUP.md`](../DATABASE-SETUP.md): import et vérification MySQL.
- [`../esp32/SETUP_GUIDE.md`](../esp32/SETUP_GUIDE.md): montage ESP32.
- [`../SERIAL_MONITOR.md`](../SERIAL_MONITOR.md): logs et diagnostic.
