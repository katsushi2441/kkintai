#!/usr/bin/env bash
# kappstore で配布するzipを作る。設定の実物・データは入れない。
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p outputs
stamp=$(date +%Y%m%d)
zip="outputs/kkintai-${stamp}.zip"
rm -f "$zip"
zip -r "$zip" \
  public/kkintai.php public/kkintai_config.php.example \
  public/kkintai_data/.htaccess public/kkintai_assets \
  scripts/check_kkintai.php \
  README.md LICENSE \
  -x '*.sqlite' -x '*.log' >/dev/null
echo "built: $zip ($(du -h "$zip" | cut -f1))"
