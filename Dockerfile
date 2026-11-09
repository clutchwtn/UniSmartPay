FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

WORKDIR /var/www/html

# Only web/ and assets/ are served. Everything else (.env, database/, esp32/,
# docker-compose.yml, docs) stays out of the image's web root.
COPY web/ /var/www/html/UniSmartPay/web/
COPY assets/ /var/www/html/UniSmartPay/assets/

# Setup scripts run inside the container but are not web-accessible. They load
# ../web/includes/functions.php, so /opt/unismartpay/web points at the served copy.
COPY scripts/ /opt/unismartpay/scripts/
RUN ln -s /var/www/html/UniSmartPay/web /opt/unismartpay/web \
    && chown -R www-data:www-data /var/www/html/UniSmartPay
