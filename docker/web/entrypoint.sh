#!/bin/sh
set -eu

KEY_FILE="${TRAKFOG_APP_KEY_FILE:-/var/lib/trakfog/app_key}"
UPDATER_TOKEN_FILE="${TRAKFOG_UPDATER_TOKEN_FILE:-/var/lib/trakfog/updater_token}"
SETUP_TOKEN_FILE="${TRAKFOG_SETUP_TOKEN_FILE:-/var/lib/trakfog/setup_token}"
mkdir -p "$(dirname "$KEY_FILE")" "$(dirname "$UPDATER_TOKEN_FILE")" "$(dirname "$SETUP_TOKEN_FILE")" /var/www/html/storage/logs

if [ -n "${TRAKFOG_APP_KEY:-}" ]; then
  printf '%s' "$TRAKFOG_APP_KEY" > "$KEY_FILE"
elif [ -s "$KEY_FILE" ]; then
  TRAKFOG_APP_KEY="$(cat "$KEY_FILE")"
  export TRAKFOG_APP_KEY
else
  TRAKFOG_APP_KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
  export TRAKFOG_APP_KEY
  printf '%s' "$TRAKFOG_APP_KEY" > "$KEY_FILE"
fi

if [ ! -s "$UPDATER_TOKEN_FILE" ]; then
  umask 027
  openssl rand -hex 32 > "$UPDATER_TOKEN_FILE"
fi

# The first browser visitor MUST NOT be able to claim a new public instance.
# This secret is printed only on explicit local administrator request.
if [ ! -s "$SETUP_TOKEN_FILE" ]; then
  umask 077
  openssl rand -hex 32 > "$SETUP_TOKEN_FILE"
fi
chown www-data:www-data "$SETUP_TOKEN_FILE" 2>/dev/null || true
chmod 600 "$SETUP_TOKEN_FILE" || true

chown www-data:10001 "$KEY_FILE" 2>/dev/null || true
chmod 640 "$KEY_FILE" || true
chown www-data:www-data "$UPDATER_TOKEN_FILE" 2>/dev/null || true
chmod 640 "$UPDATER_TOKEN_FILE" || true
chown -R www-data:www-data /var/www/html/storage || true
chmod 755 /var/lib/trakfog || true

echo "[TrakFog] Waiting for database/bootstrap ..."
until php /var/www/html/bin/docker-bootstrap.php; do
  sleep 3
done

echo "[TrakFog] Web container ready."
exec "$@"
