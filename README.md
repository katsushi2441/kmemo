# Kurage Memo (kmemo)

Simplenote型のプレーンテキストメモ。1ファイルPHP・DB不要・マルチユーザー。

- 左＝検索＋メモ一覧 / 右＝素のテキストエリア。**1行目がタイトル**（別欄なし）
- **保存ボタンなし。自動保存**（0.7秒デバウンス＋画面離脱時フラッシュ）
- ログインは kcaldav と同じフォーム式。ユーザーは `kmemo_config.php` に列挙
- 保存はユーザーごとのJSON＋flock。データ置き場には deny の .htaccess を自動生成

本番: https://exbridge.jp/memo/ （/web/exbridge_jp/memo/）

検証: `php scripts/check_kmemo.php`（26件）
