# Installing UniSmart Pay on a Linux server

This guide installs UniSmart Pay for real use on **Debian 12** or **Ubuntu 24.04**,
with Apache, PHP 8.2+ and MariaDB. For development on your own computer, use
Docker instead (see the [README](README.md)).

Run the commands as a user with `sudo`. Replace `pay.example.edu` with the
server's real host name everywhere.

## 1. Install the packages

```bash
sudo apt update
sudo apt install -y apache2 libapache2-mod-php php-mysql mariadb-server git openssl
```

## 2. Get the code

```bash
sudo git clone https://github.com/clutchwtn/UniSmartPay.git /opt/unismartpay
```

The code stays owned by `root`; Apache only needs to read it.

## 3. Create the database

Load the schema, then create a dedicated database user. The app only needs to
read and write data, not change tables:

```bash
sudo mysql < /opt/unismartpay/database/unismart_pay_schema.sql
DB_PASS=$(openssl rand -base64 24)
sudo mysql -e "CREATE USER 'unismart'@'localhost' IDENTIFIED BY '$DB_PASS';
               GRANT SELECT, INSERT, UPDATE, DELETE ON unismart_pay.* TO 'unismart'@'localhost';"
```

## 4. Store the settings

The app reads its database settings from environment variables. Keep them in
one file that only `root` can read:

```bash
sudo install -d -m 700 /etc/unismartpay
sudo install -m 600 /opt/unismartpay/deploy/unismartpay.env.example /etc/unismartpay/unismartpay.env
sudo sed -i "s|^UNISMARTPAY_DB_PASS=.*|UNISMARTPAY_DB_PASS=$DB_PASS|" /etc/unismartpay/unismartpay.env
unset DB_PASS
```

Apache loads the file when it starts:

```bash
echo 'set -a; . /etc/unismartpay/unismartpay.env; set +a' | sudo tee -a /etc/apache2/envvars
```

## 5. Configure Apache

Only the `web/` and `assets/` folders are published. The rest of the
repository (database files, firmware, docs) is never served.

```bash
sudo mkdir -p /var/www/unismartpay/UniSmartPay
sudo ln -s /opt/unismartpay/web    /var/www/unismartpay/UniSmartPay/web
sudo ln -s /opt/unismartpay/assets /var/www/unismartpay/UniSmartPay/assets

sudo cp /opt/unismartpay/deploy/apache/unismartpay.conf /etc/apache2/sites-available/
sudo sed -i 's/pay.example.edu/YOUR_HOST_NAME/' /etc/apache2/sites-available/unismartpay.conf

sudo a2enmod rewrite headers expires
sudo a2dissite 000-default
sudo a2ensite unismartpay
sudo systemctl restart apache2
```

Use `restart`, not `reload`, whenever the settings file changes: the variables
are only read when Apache starts.

Open `http://YOUR_HOST_NAME/`. You should see the UniSmart Pay home page.

## 6. Create the admin and the terminal

The scripts read the same settings file:

```bash
sudo bash -c 'set -a; . /etc/unismartpay/unismartpay.env; set +a;
  php /opt/unismartpay/scripts/create_admin.php admin@example.edu Nom Prenom super_admin'
sudo bash -c 'set -a; . /etc/unismartpay/unismartpay.env; set +a;
  php /opt/unismartpay/scripts/create_terminal.php "Terminal 1" RESTO 1'
```

- The admin script asks for a password (at least 12 characters).
- The terminal script prints the token **once**. Put it in `TERMINAL_TOKEN` in
  the ESP32 firmware (see [`esp32/SETUP_GUIDE.md`](esp32/SETUP_GUIDE.md)).
  One ESP32 handles both RESTO and BUVETTE; the terminal pages switch its mode.

Faculty admins: `create_admin.php <email> <nom> <prenom> admin_faculte <id_faculte>`.

## 7. Remove the test data

The schema includes a test student (`TEST/RFID/0001`, with a known password
and a 20 DT balance) and a test product. Remove them before real use:

```bash
sudo mysql unismart_pay < /opt/unismartpay/database/remove_test_data.sql
```

Run this before any real payments: it stops with a foreign-key error if the
test student already has transactions.

## 8. Enable HTTPS

Browsers should only use HTTPS: passwords and session cookies travel over the
connection.

1. Get a certificate. With a public host name, Let's Encrypt works:
   ```bash
   sudo apt install -y certbot python3-certbot-apache
   sudo certbot certonly --apache -d YOUR_HOST_NAME
   ```
   For a host name that is only reachable on campus, ask the university IT
   service for a certificate instead.
2. Enable the HTTPS site:
   ```bash
   sudo cp /opt/unismartpay/deploy/apache/unismartpay-ssl.conf /etc/apache2/sites-available/
   sudo sed -i 's/pay.example.edu/YOUR_HOST_NAME/' /etc/apache2/sites-available/unismartpay-ssl.conf
   sudo a2enmod ssl
   sudo a2ensite unismartpay-ssl
   sudo systemctl reload apache2
   ```
   If you use the university's certificate, edit the `SSLCertificateFile` and
   `SSLCertificateKeyFile` paths in that file first.
3. In `/etc/apache2/sites-available/unismartpay.conf`, uncomment the three
   `Rewrite` lines, then `sudo systemctl reload apache2`. Browsers are then sent
   to HTTPS, while the terminal API stays reachable over HTTP.

The ESP32 firmware does not support HTTPS yet, which is why the API stays on
HTTP. Keep the terminals on a trusted network, and **don't** set
`UNISMARTPAY_FORCE_HTTPS=1`, because it would redirect the terminals too.

## 9. Firewall

```bash
sudo apt install -y ufw
sudo ufw allow OpenSSH
sudo ufw allow "WWW Full"
sudo ufw enable
```

MariaDB only listens on `localhost` by default, so it is not reachable from
the network.

## 10. Backups

Back up the database every night and keep 14 days:

```bash
sudo tee /etc/cron.d/unismartpay-backup > /dev/null <<'EOF'
30 2 * * * root mysqldump --single-transaction unismart_pay | gzip > /var/backups/unismartpay-$(date +\%F).sql.gz && find /var/backups -name 'unismartpay-*.sql.gz' -mtime +14 -delete
EOF
```

To restore a backup:

```bash
gunzip -c /var/backups/unismartpay-YYYY-MM-DD.sql.gz | sudo mysql unismart_pay
```

Copy the backups to another machine too: a backup on the same disk doesn't
survive a disk failure.

## Updating

```bash
cd /opt/unismartpay
sudo git pull
```

Then apply any new files in `database/migrations/` once each, in order
(`sudo mysql unismart_pay < database/migrations/NNN_name.sql`), and run
`sudo systemctl restart apache2`. Back up the database first.

## Troubleshooting

| Symptom | Check |
|---|---|
| "Server configuration error." | `/etc/unismartpay/unismartpay.env` has the user and password, the `envvars` line was added, and Apache was **restarted**. |
| Page not found | The two symlinks in `/var/www/unismartpay/UniSmartPay/`, and `a2ensite unismartpay`. |
| Database connection error | MariaDB is running (`systemctl status mariadb`) and the password in the settings file matches the one used in step 3. |
| Anything else | `/var/log/apache2/unismartpay-error.log`. |
