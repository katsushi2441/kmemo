#!/usr/bin/env bash
# kappstore で配布するzipを作る。設定の実物・メモデータは入れない。
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p outputs
stamp=$(date +%Y%m%d)
zip="outputs/kmemo-${stamp}.zip"
rm -f "$zip"
zip -r "$zip" \
  public/kmemo.php public/kmemo_config.php.example \
  scripts/check_kmemo.php scripts/deploy_demo.sh \
  skills docs README.md LICENSE \
  -x '*.json' -x '*kmemo_data*' >/dev/null
echo "built: $zip ($(du -h "$zip" | cut -f1))"
unzip -l "$zip" | tail -2
