# syntax=docker/dockerfile:1
#
# Container image: WordPress + the Fast Open CMP plugin, for the shared CI/CD
# (github.com/lrozewicz/.github). Stages:
#   test     - SDK (type check, ESLint, Prettier, Vitest, bundle in sync with source)
#              and plugin (PHPUnit, PHPCS); the build fails if any of them fails
#   runtime  - immutable WordPress: core, plugin and config are part of the image;
#              runs as UID 10001 on port 8080 with a read-only root filesystem
#              (writable: /tmp and wp-content/uploads), GET /healthz answers 200
#
# Local development keeps using docker-compose.yml (see docs/development.md).

# ---------------------------------------------------------------- tests
FROM node:22-alpine AS sdk-test
WORKDIR /src
COPY sdk/package.json sdk/package-lock.json sdk/
RUN cd sdk && npm ci --no-audit --no-fund
COPY . .
RUN cd sdk && npm run typecheck && npm run lint && npm run format:check && npm test
RUN node scripts/verify-bundle.mjs && node scripts/sync-version.mjs --check \
    && touch /sdk-ok

FROM php:8.3-cli-alpine AS php-test
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apk add --no-cache git unzip
WORKDIR /src/plugin
COPY plugin/composer.json plugin/composer.lock ./
RUN composer install --no-interaction --no-progress
COPY plugin/ ./
RUN composer test && composer phpcs && touch /php-ok

FROM scratch AS test
COPY --from=sdk-test /sdk-ok /
COPY --from=php-test /php-ok /

# ---------------------------------------------------------------- runtime
# Written out (not an ARG) so Dependabot proposes WordPress updates.
# Keep the PHP version in sync with the php-test stage above.
FROM wordpress:7.1.3-php8.3-apache AS runtime

ARG WP_CLI_VERSION=2.12.0
ARG WP_CLI_SHA512=be928f6b8ca1e8dfb9d2f4b75a13aa4aee0896f8a9a0a1c45cd5d2c98605e6172e6d014dda2e27f88c98befc16c040cbb2bd1bfa121510ea5cdf5f6a30fe8832
RUN set -eux; \
    curl -fsSL -o /usr/local/bin/wp \
      "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"; \
    echo "${WP_CLI_SHA512}  /usr/local/bin/wp" | sha512sum -c -; \
    chmod +x /usr/local/bin/wp

# WordPress core baked into the document root (the stock entrypoint would copy it at
# start-up, which needs a writable filesystem). Default themes/plugins we don't use are dropped.
RUN set -eux; \
    rm -rf /var/www/html; \
    mv /usr/src/wordpress /var/www/html; \
    rm -rf /var/www/html/wp-config-docker.php /var/www/html/wp-config-sample.php \
           /var/www/html/wp-content/plugins/akismet /var/www/html/wp-content/plugins/hello.php; \
    mkdir -p /var/www/html/wp-content/uploads; \
    chown -R root:root /var/www/html; \
    chown 10001:10001 /var/www/html/wp-content/uploads

COPY docker/image/wp-config.php /var/www/html/wp-config.php
COPY docker/image/htaccess /var/www/html/.htaccess
COPY docker/image/healthz.php /var/www/healthz.php
COPY docker/image/wp-init.sh /usr/local/bin/wp-init
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/zz-focmp-uploads.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-focmp-opcache.ini
COPY docker/image/apache.conf /etc/apache2/sites-available/focmp.conf
RUN set -eux; \
    a2enmod -q remoteip rewrite; \
    a2dissite -q 000-default; \
    a2ensite -q focmp; \
    : > /etc/apache2/ports.conf; \
    printf '%s\n' ': ${APACHE_PUBLIC_ORIGIN:=${WORDPRESS_URL:-http://localhost:8080}}' 'export APACHE_PUBLIC_ORIGIN' \
      >> /etc/apache2/envvars

COPY --chown=root:root plugin/ /var/www/html/wp-content/plugins/fast-open-cmp/
RUN rm -rf /var/www/html/wp-content/plugins/fast-open-cmp/tests \
           /var/www/html/wp-content/plugins/fast-open-cmp/phpunit.xml \
           /var/www/html/wp-content/plugins/fast-open-cmp/phpcs.xml.dist \
           /var/www/html/wp-content/plugins/fast-open-cmp/composer.*

# Apache runtime files go to /tmp (the only writable path besides uploads).
ENV APACHE_RUN_DIR=/tmp/apache2 \
    APACHE_PID_FILE=/tmp/apache2/apache2.pid \
    APACHE_LOCK_DIR=/tmp/apache2/lock \
    APACHE_LOG_DIR=/tmp/apache2/log \
    APACHE_RUN_USER=#10001 \
    APACHE_RUN_GROUP=#10001 \
    HOME=/tmp \
    WP_CLI_CACHE_DIR=/tmp/wp-cli-cache

USER 10001:10001
WORKDIR /var/www/html
EXPOSE 8080
ENTRYPOINT []
CMD ["apache2-foreground"]
