# Dockerfile
# Apache + PHP + SQLite の Container profile。
FROM php:8.3-apache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev sqlite3 libonig-dev libxml2-dev libzip-dev unzip \
    && docker-php-ext-install pdo_sqlite mbstring xml zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Apache の既定 DocumentRoot と検査用の作業ツリーの両方へ公開ファイルを配置する。
COPY public/ /var/www/html/
COPY public/ /var/www/public/
COPY src/ /var/www/src/
COPY migrations/ /var/www/migrations/
COPY tests/ /var/www/tests/
COPY bin/ /var/www/bin/
COPY bootstrap.php /var/www/bootstrap.php
COPY composer.json /var/www/composer.json
COPY composer.lock /var/www/composer.lock
COPY phpunit.xml.dist /var/www/phpunit.xml.dist
COPY phpstan.neon.dist /var/www/phpstan.neon.dist
RUN cd /var/www && composer install --no-interaction --prefer-dist
COPY docker-entrypoint.sh /usr/local/bin/relink-entrypoint.sh
COPY deploy/apache-docker.conf /etc/apache2/conf-enabled/relink.conf

RUN mkdir -p /var/lib/relink-resolver \
    && chmod +x /var/www/bin/*.sh /var/www/bin/*.php /usr/local/bin/relink-entrypoint.sh \
    && chown -R www-data:www-data /var/lib/relink-resolver \
    && chown -R root:root /var/www \
    && chmod -R a-w /var/www \
    && chmod +x /var/www/bin/*.sh /var/www/bin/*.php /usr/local/bin/relink-entrypoint.sh

ENV RELINK_DATA_DIR=/var/lib/relink-resolver
WORKDIR /var/www
ENTRYPOINT ["/usr/local/bin/relink-entrypoint.sh"]
CMD ["apache2-foreground"]
EXPOSE 80
