#!/usr/bin/env bash
# デモの公開。https://proto.exbridge.jp/kmemo/
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; . /home/kojima/work/aixec/.env; set +a
remote="/web/proto_exbridge_jp/kmemo"
up() { curl --fail --silent --show-error --ftp-create-dirs -T "$1" \
  "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${2}"; echo "up: $2"; }
printf 'AddHandler php8.3-script .php\nDirectoryIndex index.php\n' > /tmp/kmemo_demo_ht
up public/kmemo.php index.php
up demo/kmemo_config.php kmemo_config.php
up /tmp/kmemo_demo_ht .htaccess
echo "published: https://proto.exbridge.jp/kmemo/"
# デモを検索に載せる（製品が出す noindex を、デモ用の head に置き換える）。配置し直すたびに要る
/usr/bin/python3 /home/kojima/work/proto_exbridge_jp/scripts/demo_index_head.py kmemo
