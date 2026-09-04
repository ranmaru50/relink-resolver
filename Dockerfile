# Dockerfile
# Apache + PHP + SQLite の Container profile。
FROM php:8.3-apache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev sqlite3 libonig-dev libxml2-dev libzip-dev unzip \
    && docker-php-ext-install pdo_sqlite mbstring xml zip \
    && a2enmod rewrite headers reqtimeout ssl \
    && rm -rf /var/lib/apt/lists/*

# Apache の既定 DocumentRoot と検査用の作業ツリーの両方へ公開ファイルを配置する。
COPY public/ /var/www/html/
COPY public/ /var/www/public/
COPY deploy/ /var/www/deploy/
COPY src/ /var/www/src/
COPY migrations/ /var/www/migrations/
COPY tests/ /var/www/tests/
COPY bin/ /var/www/bin/
COPY bootstrap.php /var/www/bootstrap.php
COPY composer.json /var/www/composer.json
COPY composer.lock /var/www/composer.lock
COPY phpunit.xml.dist /var/www/phpunit.xml.dist
COPY phpstan.neon.dist /var/www/phpstan.neon.dist
# GitHub API の一時的な通信失敗に備え、依存関係の取得を直列化して再試行する。
RUN set -eux; \
    cd /var/www; \
    attempts=0; \
    until COMPOSER_MAX_PARALLEL_HTTP=1 composer install --no-interaction --no-progress --prefer-dist; do \
        attempts=$((attempts + 1)); \
        if [ "$attempts" -ge 3 ]; then \
            exit 1; \
        fi; \
        sleep 5; \
    done
COPY docker-entrypoint.sh /usr/local/bin/relink-entrypoint.sh
# 受入 profile でだけ有効化する Apache 直接 TLS VirtualHost。
COPY deploy/apache-ssl-vhost.conf /etc/apache2/sites-available/relink-ssl.conf
# Apache の既定 security.conf より後に読み込み、ServerTokens を確実に上書きする。
COPY deploy/apache-docker.conf /etc/apache2/conf-enabled/zz-relink-security.conf
COPY deploy/php-security.ini /usr/local/etc/php/conf.d/relink-security.ini

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
EXPOSE 80 443
