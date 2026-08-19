#!/usr/bin/env bash
# デモの公開。https://proto.exbridge.jp/kkintai/
set -euo pipefail
cd "$(dirname "$0")/.."
php scripts/check_kkintai.php >/dev/null || { echo "自己テスト失敗→デプロイ中止" >&2; exit 1; }
set -a; . /home/kojima/work/aixec/.env; set +a
PW=$(grep -m1 '^KKINTAI_DEMO_PASSWORD=' .env | cut -d= -f2)
KI=$(grep -m1 '^KKINTAI_DEMO_KIOSK=' .env | cut -d= -f2)
[ -n "$PW" ] && [ -n "$KI" ] || { echo ".env不足" >&2; exit 1; }
remote="/web/proto_exbridge_jp/kkintai"
up() { curl --fail --silent --show-error --ftp-create-dirs -T "$1" \
  "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${2}"; echo "up: $2"; }
tmp=$(mktemp)
sed -e "s/__KK_DEMO_PASSWORD__/${PW}/" -e "s/__KK_DEMO_KIOSK__/${KI}/" demo/kkintai_config.php > "$tmp"
up public/kkintai.php kkintai.php
up "$tmp" kkintai_config.php
rm -f "$tmp"
up demo/index.php index.php
up demo/.htaccess .htaccess
up public/kkintai_data/.htaccess kkintai_data/.htaccess
up public/kkintai_assets/face-api.min.js kkintai_assets/face-api.min.js
up public/kkintai_assets/kurage_mascot.png kkintai_assets/kurage_mascot.png
for f in public/kkintai_assets/models/*; do up "$f" "kkintai_assets/models/$(basename "$f")"; done
echo "published: https://proto.exbridge.jp/kkintai/"
