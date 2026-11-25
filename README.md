# Kurage Memo (kmemo)

Simplenote型のプレーンテキストメモ。1ファイルPHP・DB不要・マルチユーザー。

- 左＝検索＋メモ一覧 / 右＝素のテキストエリア。**1行目がタイトル**（別欄なし）
- **保存ボタンなし。自動保存**（0.7秒デバウンス＋画面離脱時フラッシュ）
- ログインは kcaldav と同じフォーム式。ユーザーは `kmemo_config.php` に列挙
- 保存はユーザーごとのJSON＋flock。データ置き場には deny の .htaccess を自動生成

本番: https://exbridge.jp/memo/ （/web/exbridge_jp/memo/）

検証: `php scripts/check_kmemo.php`（26件）

## AIエージェントから使う（MCP）

Claude Code・Codex などのAIエージェントから、メモを読んだり、下書きをメモに置いたりできる。
メモはこれまでどおり自社のサーバーの1ファイルにあり、AIには MCP の窓口だけを渡す。

- **メモを指定して読ませる**: メモを開くと URL が `…/memo/?n=<メモID>` になる。この URL を AI に貼れば中身を読む
- **下書きをメモに置く**: 「メモに登録して」で、AIが書いた返信案・議事録を新しいメモにする（返ってきた URL を開けば画面で直せる）
- **探して使う**: 「L-Zone の件のメモを探して」で、AIが検索してから読む

道具: `memo_list`（一覧・検索）/ `memo_get`（1件を読む）/ `memo_create` / `memo_append`（末尾に書き足す）/ `memo_update`（書き換え）/ `memo_delete`（設定で許したときだけ）

サーバー側（`kmemo_config.php`）:

```php
function kmemo_api_tokens() { return array('（openssl rand -hex 24 などで作った文字列）' => 'kojima'); }
// define('KMEMO_API_DELETE', true);   // AIからの削除を許すとき（既定は不可）
```

手元のPC:

```
claude mcp add kmemo -e KMEMO_URL=https://example.com/memo/ -e KMEMO_TOKEN=（トークン） -- php /path/to/kmemo_mcp.php
```

読み取りだけにするときは `-e KMEMO_MCP_READONLY=1`。検証: `python3 scripts/check_mcp.py`（10件・手元で php -S を立てて試す）。


## バックアップ

管理画面の左下から、自分のメモを丸ごとZIPでダウンロードできる。
最終取得日から30日経つと枠が色付きになり、取り忘れを知らせる。

```
kmemo_backup_<user>_2026-08-24_1714.zip
  data/notes.json   原本。これを kmemo_data/notes_<user>.json に置けば戻る
  data/notes.csv    表計算で中身を確認する用（BOM付き・改行はセル内）
  manifest.json     製品名・件数・項目の説明
  RESTORE.md        戻しかた。AIに渡す指示文つき
```

設計の理由:

- **cronを使わない。** 置き場所によってcronの可否が変わるので「標準」と言えない。
  アプリのコードだけで完結させる。日次の自動取得はオプション扱い。
- **復元UIを作らない。** ZIPをAIエージェントに渡せば戻せる。そのために
  原本(JSON)・項目の説明(manifest)・指示文(RESTORE.md)を必ず同梱する。
  CSVだけだと改行・NULL・日付形式が落ちてAIでも詰まる。
- **最終取得日時を画面に出す。** 「取れているつもり」がいちばんまずい。
- **ZipArchiveが無い環境ではJSONだけを返す。** 共有サーバーで落ちないように。
  （実測: exbridge.jp=PHP8.3.33・kurage=8.2.33 とも ZipArchive あり）

検証済み: 本番データ5件でZIP取得 → RESTORE.md の手順で復元 → アプリから
5件読めることを確認（項目の欠けなし）。
