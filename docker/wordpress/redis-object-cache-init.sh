#!/bin/sh
# One-shot init for the production overlay: install + enable the Redis Object
# Cache drop-in so WordPress transients (incl. the CMP config / vendor-list
# caches) live in Redis instead of the wp_options table.
#
# Idempotent and safe to re-run. Runs as the `redis-init` service after the
# `wordpress` container is up. If the WordPress *site* isn't installed yet
# (fresh deploy, before you complete WP setup), it exits 0 without failing —
# just re-run it afterwards:  docker compose ... up -d redis-init
set -eu

# WP_REDIS_HOST / WP_REDIS_PORT are defined in wp-config (via
# WORDPRESS_CONFIG_EXTRA in docker-compose.prod.yml), so this script only has to
# install the plugin and flip on the drop-in.
WP="wp --path=/var/www/html"

echo "[redis-init] waiting for WordPress to be reachable (DB + wp-config)..."
i=0
until $WP core is-installed >/dev/null 2>&1; do
  i=$((i + 1))
  if [ "$i" -ge 30 ]; then
    echo "[redis-init] WordPress not installed yet after ~60s — skipping for now."
    echo "[redis-init] Re-run once the site is set up:  docker compose ... up -d redis-init"
    exit 0
  fi
  sleep 2
done

echo "[redis-init] ensuring redis-cache plugin is installed + active..."
$WP plugin is-installed redis-cache >/dev/null 2>&1 || $WP plugin install redis-cache
$WP plugin activate redis-cache

echo "[redis-init] enabling object-cache.php drop-in..."
$WP redis enable

echo "[redis-init] status:"
$WP redis status || true
echo "[redis-init] done."
