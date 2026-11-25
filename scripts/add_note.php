<?php
/**
 * Kurage Memo に新しいメモを1件足す（AIエージェントが作った下書きなどを、ユーザーのメモに置く）。
 * 画面の保存（km_update）と同じく、台帳ファイルを排他ロックしてから足す。
 *   php add_note.php <データのフォルダ> <ユーザー> <本文のファイル>
 * 出力: 足したメモの ID（URL は https://exbridge.jp/memo/?n=<ID>）
 */
if ($argc < 4) { fwrite(STDERR, "使い方: php add_note.php <データのフォルダ> <ユーザー> <本文のファイル>\n"); exit(2); }
$dir = rtrim($argv[1], '/'); $user = preg_replace('/[^a-z0-9_-]/i', '', $argv[2]); $content = (string)file_get_contents($argv[3]);
if ($content === '' || mb_strlen($content, 'UTF-8') > 200000) { fwrite(STDERR, "本文が空か長すぎます\n"); exit(1); }
$path = "$dir/notes_$user.json";
if (!file_exists($path)) { fwrite(STDERR, "台帳がありません: $path\n"); exit(1); }
$fp = fopen($path, 'c+b'); if (!$fp || !flock($fp, LOCK_EX)) { fwrite(STDERR, "台帳をロックできません\n"); exit(1); }
$d = json_decode(stream_get_contents($fp), true); if (!is_array($d)) { $d = array(); }
if (!isset($d['notes']) || !is_array($d['notes'])) { $d['notes'] = array(); }
$now = microtime(true); $id = bin2hex(random_bytes(8));
$d['notes'][] = array('id' => $id, 'content' => $content, 'created' => $now, 'updated' => $now);
rewind($fp); ftruncate($fp, 0); fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE)); fflush($fp); flock($fp, LOCK_UN); fclose($fp);
echo $id, "\n";
