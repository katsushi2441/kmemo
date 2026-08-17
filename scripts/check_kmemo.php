<?php
/**
 * kmemo の検証。実行: php scripts/check_kmemo.php
 * 一番見たいのは「他人のメモに触れない」(台帳がユーザー別に分離されている)こと。
 */
define('KMEMO_TEST', true);
define('KMEMO_DATA_DIR', sys_get_temp_dir() . '/kmemo_check_' . getmypid());
function kmemo_users() {
    return array('taro' => password_hash('pw-taro', PASSWORD_DEFAULT), 'hana' => 'plain-hana');
}
require dirname(__DIR__) . '/public/kmemo.php';

$pass = 0; $fail = 0;
function ok($c, $label, $got = null) {
    global $pass, $fail;
    if ($c) { $pass++; echo "  OK   $label\n"; }
    else { $fail++; echo "  FAIL $label" . ($got === null ? '' : ' → ' . var_export($got, true)) . "\n"; }
}

echo "\n[1] タイトルは1行目から\n";
ok(km_title("買い物リスト\n牛乳\n卵") === '買い物リスト', '1行目がタイトル');
ok(km_title("\n\n  ２行目から始まる\nx") === '２行目から始まる', '空行は飛ばす');
ok(km_title('') === '(無題)', '空メモは(無題)');
ok(mb_strlen(km_title(str_repeat('あ', 100)), 'UTF-8') === 61, '長い1行目は60字+…');
ok(km_excerpt("題\n本文です") === '本文です', '抜粋は2行目以降');
ok(km_excerpt("題だけ") === '', '本文が無ければ抜粋は空');

echo "\n[2] 保存と一覧\n";
list($ok1, $n1) = km_save('taro', '', "最初のメモ\n中身");
ok($ok1 && $n1['id'] !== '', '新規保存できる');
ok($n1['title'] === '最初のメモ', '保存結果にタイトルが付く');
usleep(20000);
list($ok2, $n2) = km_save('taro', '', "2つ目");
$l = km_list('taro');
ok(count($l) === 2, '2件になる');
ok($l[0]['id'] === $n2['id'], '一覧は更新の新しい順');
usleep(20000);
list($ok3, $n1b) = km_save('taro', $n1['id'], "最初のメモ(改)\n中身");
ok($ok3 && $n1b['title'] === '最初のメモ(改)', '上書き保存できる');
$l = km_list('taro');
ok($l[0]['id'] === $n1['id'], '更新したメモが先頭に上がる');
ok(count($l) === 2, '上書きで件数は増えない');

echo "\n[3] 他人のメモに触れない\n";
ok(km_list('hana') === array(), 'hanaには何も見えない');
list($ok4, $e4) = km_save('hana', $n1['id'], '乗っ取り');
ok($ok4 === false, '他人のIDでは保存できない', $e4);
ok(km_list('taro')[0]['content'] === "最初のメモ(改)\n中身", '中身が書き換わっていない');
list($ok5, $e5) = km_delete('hana', $n1['id']);
ok($ok5 === false, '他人のIDでは削除できない');

echo "\n[4] 削除と検証\n";
list($ok6, ) = km_delete('taro', $n2['id']);
ok($ok6 === true && count(km_list('taro')) === 1, '自分のメモは削除できる');
list($ok7, $e7) = km_save('taro', '', str_repeat('あ', KMEMO_MAX_CHARS + 1));
ok($ok7 === false, '長すぎるメモは保存を断る', $e7);
list($ok8, $e8) = km_save('taro', 'zzzz', 'x');
ok($ok8 === false, '存在しないIDは保存できない');

echo "\n[5] 認証\n";
ok(km_auth_check('taro', 'pw-taro') === true, 'ハッシュのユーザーで入れる');
ok(km_auth_check('taro', 'wrong') === false, 'パスワード違いは弾く');
ok(km_auth_check('hana', 'plain-hana') === true, '平文設定も後方互換で通る');
ok(km_auth_check('nobody', 'x') === false, '未登録ユーザーは弾く');

echo "\n[6] 保存先の保護\n";
km_ensure_dir();
ok(file_exists(KMEMO_DATA_DIR . '/.htaccess'), 'データ置き場に.htaccessが生える');
ok(strpos(file_get_contents(KMEMO_DATA_DIR . '/.htaccess'), 'Require all denied') !== false, '中身はdeny');

foreach (glob(KMEMO_DATA_DIR . '/*') as $f) { @unlink($f); }
@unlink(KMEMO_DATA_DIR . '/.htaccess'); @rmdir(KMEMO_DATA_DIR);
echo "\n================================\n  成功 $pass 件 / 失敗 $fail 件\n================================\n";
exit($fail === 0 ? 0 : 1);
