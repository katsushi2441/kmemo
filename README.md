# Kurage Memo (kmemo)

Simplenote型のプレーンテキストメモ。1ファイルPHP・DB不要・マルチユーザー。

- 左＝検索＋メモ一覧 / 右＝素のテキストエリア。**1行目がタイトル**（別欄なし）
- **保存ボタンなし。自動保存**（0.7秒デバウンス＋画面離脱時フラッシュ）
- ログインは kcaldav と同じフォーム式。ユーザーは `kmemo_config.php` に列挙
- 保存はユーザーごとのJSON＋flock。データ置き場には deny の .htaccess を自動生成

本番: https://exbridge.jp/memo/ （/web/exbridge_jp/memo/）

検証: `php scripts/check_kmemo.php`（26件）

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
