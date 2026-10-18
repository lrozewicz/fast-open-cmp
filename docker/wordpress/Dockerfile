# WordPress image for the production overlay: stock image + the phpredis
# extension (required for a Redis object cache) + WP-CLI (used once, by the
# redis-init sidecar, to install and enable the Redis Object Cache drop-in).
#
# Built via `build:` in docker-compose.prod.yml; not used by the dev stack.
FROM wordpress:php8.3-apache

# phpredis — the C extension WordPress' object cache talks to.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*

# WP-CLI (used by docker/wordpress/redis-object-cache-init.sh).
RUN set -eux; \
    curl -fsSL -o /usr/local/bin/wp \
      https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar; \
    chmod +x /usr/local/bin/wp; \
    wp --allow-root --version
