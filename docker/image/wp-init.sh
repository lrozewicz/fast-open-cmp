#!/bin/sh
# First-run setup, run before the web server starts (Kubernetes init container):
# waits for the database, installs WordPress if it is not installed yet, activates the plugin.
# Idempotent: on an installed site it only makes sure the plugin is active.
#
# Environment: everything wp-config.php reads, plus
#   WORDPRESS_ADMIN_USER, WORDPRESS_ADMIN_PASSWORD, WORDPRESS_ADMIN_EMAIL, WORDPRESS_TITLE
# Installing automatically (instead of the web installer) matters on a public URL:
# otherwise the first visitor could finish the installation and become the admin.
set -eu

echo "[wp-init] waiting for the database..."
i=0
# PHP's mysqli instead of `wp db check`, which needs the mysql client binaries.
until php -r '$c = @mysqli_connect(getenv("DB_HOST"), getenv("DB_USER"), getenv("DB_PASSWORD"), getenv("DB_NAME"), (int) (getenv("DB_PORT") ?: 3306)); exit($c ? 0 : 1);' 2>/dev/null; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then
    echo "[wp-init] database not reachable after 5 minutes" >&2
    exit 1
  fi
  sleep 5
done

if ! wp core is-installed; then
  : "${WORDPRESS_URL:?WORDPRESS_URL is required}"
  : "${WORDPRESS_ADMIN_PASSWORD:?WORDPRESS_ADMIN_PASSWORD is required for the first installation}"
  echo "[wp-init] installing WordPress at $WORDPRESS_URL"
  wp core install \
    --url="$WORDPRESS_URL" \
    --title="${WORDPRESS_TITLE:-Fast Open CMP}" \
    --admin_user="${WORDPRESS_ADMIN_USER:-admin}" \
    --admin_password="$WORDPRESS_ADMIN_PASSWORD" \
    --admin_email="${WORDPRESS_ADMIN_EMAIL:-admin@example.com}" \
    --skip-email
  wp rewrite structure '/%postname%/' --quiet
fi

wp plugin activate fast-open-cmp --quiet
echo "[wp-init] done"
