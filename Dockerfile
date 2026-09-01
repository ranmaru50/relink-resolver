# Dockerfile
# Apache + PHP + SQLite の Container profile。
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Apache の既定 DocumentRoot と検査用の作業ツリーの両方へ公開ファイルを配置する。
COPY public/ /var/www/html/
COPY public/ /var/www/public/
COPY src/ /var/www/src/
COPY migrations/ /var/www/migrations/
COPY tests/ /var/www/tests/
COPY bootstrap.php /var/www/bootstrap.php
COPY deploy/apache-docker.conf /etc/apache2/conf-enabled/relink.conf

RUN mkdir -p /var/lib/relink-resolver \
    && chown -R www-data:www-data /var/lib/relink-resolver /var/www

ENV RELINK_DATA_DIR=/var/lib/relink-resolver
WORKDIR /var/www
EXPOSE 80
