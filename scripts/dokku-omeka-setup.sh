#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: dokku-omeka-setup.sh [options]

Required:
  --app APP_NAME
  --domain DOMAIN
  --email EMAIL
  --db-password DB_PASSWORD

Optional:
  --db-name DB_NAME           Default: APP_NAME with '-' replaced by '_'
  --db-user DB_USER           Default: DB_NAME
  --mysql-service NAME        Default: omeka-db
  --image IMAGE               Default: erseco/alpine-omeka-s:latest
  --storage-base PATH         Default: /mnt/volume-1/omeka
  --volume-uid UID            Default: 65534
  --volume-gid GID            Default: 65534
  --admin-email EMAIL         Default: admin@admin.com
  --admin-password PASSWORD   Default: admin
  --admin-name NAME           Default: Omeka Admin
  --site-title TITLE          Default: Omeka Site
  --timezone TZ               Default: UTC
  --locale LOCALE             Default: en_US

Example:
  ./dokku-omeka-setup.sh --app kpa-omeka \
    --domain kpa-omeka.survos.com \
    --email tacman@gmail.com \
    --db-password kpa_omeka_secret \
    --site-title "KPA Omeka" \
    --admin-email tacman@gmail.com
USAGE
}

APP_NAME=""
DOMAIN=""
EMAIL=""
DB_PASSWORD=""

MYSQL_SERVICE="omeka-db"
IMAGE="erseco/alpine-omeka-s:latest"
STORAGE_BASE="/mnt/volume-1/omeka"
VOLUME_UID="65534"
VOLUME_GID="65534"
ADMIN_EMAIL="admin@admin.com"
ADMIN_PASSWORD="admin"
ADMIN_NAME="Omeka Admin"
SITE_TITLE="Omeka Site"
TIMEZONE="UTC"
LOCALE="en_US"
DB_NAME=""
DB_USER=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --app) APP_NAME="$2"; shift 2;;
    --domain) DOMAIN="$2"; shift 2;;
    --email) EMAIL="$2"; shift 2;;
    --db-password) DB_PASSWORD="$2"; shift 2;;
    --db-name) DB_NAME="$2"; shift 2;;
    --db-user) DB_USER="$2"; shift 2;;
    --mysql-service) MYSQL_SERVICE="$2"; shift 2;;
    --image) IMAGE="$2"; shift 2;;
    --storage-base) STORAGE_BASE="$2"; shift 2;;
    --volume-uid) VOLUME_UID="$2"; shift 2;;
    --volume-gid) VOLUME_GID="$2"; shift 2;;
    --admin-email) ADMIN_EMAIL="$2"; shift 2;;
    --admin-password) ADMIN_PASSWORD="$2"; shift 2;;
    --admin-name) ADMIN_NAME="$2"; shift 2;;
    --site-title) SITE_TITLE="$2"; shift 2;;
    --timezone) TIMEZONE="$2"; shift 2;;
    --locale) LOCALE="$2"; shift 2;;
    -h|--help) usage; exit 0;;
    *) echo "Unknown option: $1"; usage; exit 1;;
  esac
done

if [[ -z "$APP_NAME" || -z "$DOMAIN" || -z "$EMAIL" || -z "$DB_PASSWORD" ]]; then
  usage
  exit 1
fi

if [[ -z "$DB_NAME" ]]; then
  DB_NAME="${APP_NAME//-/_}"
fi
if [[ -z "$DB_USER" ]]; then
  DB_USER="$DB_NAME"
fi

if dokku mysql:exists "$MYSQL_SERVICE" >/dev/null 2>&1; then
  :
else
  dokku mysql:create "$MYSQL_SERVICE"
fi

if dokku apps:exists "$APP_NAME" >/dev/null 2>&1; then
  :
else
  dokku apps:create "$APP_NAME"
fi

dokku mysql:link "$MYSQL_SERVICE" "$APP_NAME" >/dev/null 2>&1 || true

ROOT_PASSWORD=$(dokku mysql:enter "$MYSQL_SERVICE" env | awk -F= '/MYSQL_ROOT_PASSWORD/{print $2}')

dokku mysql:enter "$MYSQL_SERVICE" mysql -uroot -p"$ROOT_PASSWORD" -e "\
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;
CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'%' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%';
FLUSH PRIVILEGES;"

dokku config:set "$APP_NAME" \
  OMEKA_ADMIN_EMAIL="$ADMIN_EMAIL" \
  OMEKA_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
  OMEKA_ADMIN_NAME="$ADMIN_NAME" \
  OMEKA_SITE_TITLE="$SITE_TITLE" \
  OMEKA_TIMEZONE="$TIMEZONE" \
  OMEKA_LOCALE="$LOCALE" \
  DB_HOST="dokku-mysql-$MYSQL_SERVICE" \
  DB_NAME="$DB_NAME" \
  DB_USER="$DB_USER" \
  DB_PASSWORD="$DB_PASSWORD"

mkdir -p "$STORAGE_BASE/$APP_NAME/files" \
  "$STORAGE_BASE/$APP_NAME/files/config" \
  "$STORAGE_BASE/$APP_NAME/files/files" \
  "$STORAGE_BASE/$APP_NAME/files/modules" \
  "$STORAGE_BASE/$APP_NAME/files/themes" \
  "$STORAGE_BASE/$APP_NAME/logs"
chown -R "$VOLUME_UID:$VOLUME_GID" "$STORAGE_BASE/$APP_NAME/files" "$STORAGE_BASE/$APP_NAME/logs"
chmod -R u+rwX,g+rwX "$STORAGE_BASE/$APP_NAME/files" "$STORAGE_BASE/$APP_NAME/logs"
dokku storage:mount "$APP_NAME" "$STORAGE_BASE/$APP_NAME/files:/var/www/html/volume"
dokku storage:mount "$APP_NAME" "$STORAGE_BASE/$APP_NAME/logs:/var/www/html/logs"

dokku git:from-image "$APP_NAME" "$IMAGE"
dokku ps:rebuild "$APP_NAME"

dokku enter "$APP_NAME" web sh -lc "\
if [ -d /var/www/html/themes ] && [ -d /var/www/html/volume/themes ]; then
  cp -a /var/www/html/themes/. /var/www/html/volume/themes/ 2>/dev/null || true
fi
if [ -d /var/www/html/modules ] && [ -d /var/www/html/volume/modules ]; then
  cp -a /var/www/html/modules/. /var/www/html/volume/modules/ 2>/dev/null || true
fi
"

dokku domains:set "$APP_NAME" "$DOMAIN"
dokku ports:set "$APP_NAME" http:80:8080

dokku letsencrypt:set "$APP_NAME" email "$EMAIL"
dokku letsencrypt:enable "$APP_NAME"

echo "Omeka deployment complete: http://$DOMAIN"
