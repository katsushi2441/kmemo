<?php
/**
 * Kurage Memo (kmemo) — Simplenote型のプレーンテキストメモ。1ファイルPHP。
 *
 *   ・左＝検索＋メモ一覧 / 右＝素のテキストエリア（リッチテキスト無し）
 *   ・1行目がそのままタイトル。別欄は無い
 *   ・保存ボタンも無い。打った端から自動保存（0.7秒デバウンス＋画面離脱時）
 *   ・マルチユーザー。ログインは kcaldav と同じフォーム式、ユーザーは設定に列挙
 *   ・保存はユーザーごとのJSON＋flock。DBもComposerも使わない
 *
 * 検証: php scripts/check_kmemo.php （KMEMO_TEST定義時は画面処理を実行しない）
 */

date_default_timezone_set('Asia/Tokyo');

if (!defined('KMEMO_DATA_DIR')) { define('KMEMO_DATA_DIR', __DIR__ . '/kmemo_data'); }
define('KMEMO_MAX_CHARS', 200000);   // 1メモの上限。超えたら4xxで断る

function km_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function km_id() { return bin2hex(random_bytes(8)); }

/* ============================================================
 * 台帳 — ユーザーごとに1ファイル。更新は必ずロックの中で
 * ============================================================ */

function km_path($user) {
    // ユーザー名は設定に列挙されたものしか来ないが、念のためファイル名を無害化する
    return KMEMO_DATA_DIR . '/notes_' . preg_replace('/[^a-z0-9_-]/i', '', $user) . '.json';
}

function km_ensure_dir() {
    if (!is_dir(KMEMO_DATA_DIR)) { @mkdir(KMEMO_DATA_DIR, 0700, true); }
    $ht = KMEMO_DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht,
            "# メモ(個人情報)を直接読ませない\n"
          . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
          . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
}

function km_load($user) {
    $p = km_path($user);
    if (!file_exists($p)) { return array(); }
    $fp = fopen($p, 'rb');
    if (!$fp) { return array(); }
    flock($fp, LOCK_SH);
    $j = stream_get_contents($fp);
    flock($fp, LOCK_UN); fclose($fp);
    $d = json_decode($j, true);
    return (is_array($d) && isset($d['notes']) && is_array($d['notes'])) ? $d['notes'] : array();
}

/** 排他ロックの中で更新。$fnが文字列を返したらエラー(保存しない)。 */
function km_update($user, $fn) {
    km_ensure_dir();
    $fp = fopen(km_path($user), 'c+b');
    if (!$fp) { return array(false, '台帳を開けません'); }
    if (!flock($fp, LOCK_EX)) { fclose($fp); return array(false, '台帳をロックできません'); }
    $d = json_decode(stream_get_contents($fp), true);
    if (!is_array($d)) { $d = array(); }
    if (!isset($d['notes']) || !is_array($d['notes'])) { $d['notes'] = array(); }
    $r = $fn($d['notes']);
    if (is_string($r)) { flock($fp, LOCK_UN); fclose($fp); return array(false, $r); }
    rewind($fp); ftruncate($fp, 0);
    fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return array(true, $r);
}

/* ============================================================
 * メモの形 — タイトルは持たない。常に本文1行目から導く
 * ============================================================ */

function km_title($content) {
    foreach (preg_split('/\r\n|\r|\n/', (string)$content) as $line) {
        $line = trim($line);
        if ($line !== '') {
            return mb_strlen($line, 'UTF-8') > 60 ? mb_substr($line, 0, 60, 'UTF-8') . '…' : $line;
        }
    }
    return '(無題)';
}

function km_excerpt($content) {
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);
    array_shift($lines);                       // 1行目はタイトルとして使うので除く
    $rest = trim(implode(' ', array_map('trim', $lines)));
    if ($rest === '') { return ''; }
    return mb_strlen($rest, 'UTF-8') > 80 ? mb_substr($rest, 0, 80, 'UTF-8') . '…' : $rest;
}

function km_note_out($n) {
    return array(
        'id' => $n['id'],
        'title' => km_title($n['content']),
        'excerpt' => km_excerpt($n['content']),
        'content' => $n['content'],
        'updated' => $n['updated'],
    );
}

/** 一覧(更新の新しい順)。 */
function km_list($user) {
    $notes = km_load($user);
    usort($notes, function ($a, $b) { return $b['updated'] <=> $a['updated']; });
    return array_map('km_note_out', $notes);
}

/** 保存。idが無ければ新規。戻り=array(ok, note|エラー文) */
function km_save($user, $id, $content) {
    $content = (string)$content;
    if (mb_strlen($content, 'UTF-8') > KMEMO_MAX_CHARS) {
        return array(false, 'メモが長すぎます(' . number_format(KMEMO_MAX_CHARS) . '文字まで)');
    }
    $saved = null;
    list($ok, $err) = km_update($user, function (&$notes) use ($id, $content, &$saved) {
        $now = microtime(true);
        if ($id !== '' && $id !== null) {
            foreach ($notes as $i => $n) {
                if ($n['id'] === $id) {
                    $notes[$i]['content'] = $content;
                    $notes[$i]['updated'] = $now;
                    $saved = $notes[$i];
                    return true;
                }
            }
            return 'そのメモはありません';   // 他人のIDや削除済みIDをここで弾く
        }
        $saved = array('id' => km_id(), 'content' => $content,
                       'created' => $now, 'updated' => $now);
        $notes[] = $saved;
        return true;
    });
    return $ok ? array(true, km_note_out($saved)) : array(false, $err);
}

function km_delete($user, $id) {
    return km_update($user, function (&$notes) use ($id) {
        foreach ($notes as $i => $n) {
            if ($n['id'] === $id) { array_splice($notes, $i, 1); return true; }
        }
        return 'そのメモはありません';
    });
}

/* ============================================================
 * 認証 — kcaldavと同じ: 設定のユーザー表 + フォーム + session
 * ============================================================ */

function km_users() { return function_exists('kmemo_users') ? kmemo_users() : array(); }

function km_auth_check($user, $pass) {
    $users = km_users();
    if (!isset($users[$user])) { return false; }
    $hash = (string)$users[$user];
    return (strpos($hash, '$') === 0) ? password_verify((string)$pass, $hash)
                                      : hash_equals($hash, (string)$pass);
}

function km_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        @ini_set('session.use_only_cookies', '1');
        @session_name('KMEMOSESSID');
        @session_start();
    }
}

function km_current_user() {
    km_session_start();
    $u = isset($_SESSION['km_user']) ? (string)$_SESSION['km_user'] : '';
    // 設定から消されたユーザーは、セッションが残っていても締め出す
    return ($u !== '' && isset(km_users()[$u])) ? $u : '';
}

function km_csrf() {
    km_session_start();
    if (empty($_SESSION['km_csrf'])) { $_SESSION['km_csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['km_csrf'];
}

function km_csrf_ok($t) {
    km_session_start();
    return !empty($_SESSION['km_csrf']) && hash_equals($_SESSION['km_csrf'], (string)$t);
}

/* ============================================================
 * ここから画面とAPI（検証時は実行しない）
 * ============================================================ */
if (defined('KMEMO_TEST')) { return; }

$self = strtok($_SERVER['REQUEST_URI'], '?');
km_session_start();

/* ---- ログイン/ログアウト ---- */
if (isset($_GET['do']) && $_GET['do'] === 'logout') {
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ' . $self); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'login') {
    $u = isset($_POST['login_user']) ? trim((string)$_POST['login_user']) : '';
    $p = isset($_POST['login_pass']) ? (string)$_POST['login_pass'] : '';
    if (km_auth_check($u, $p)) {
        session_regenerate_id(true);
        $_SESSION['km_user'] = $u;
        header('Location: ' . $self); exit;
    }
    km_login_page($self, 'ユーザー名またはパスワードが違います');
    exit;
}

$user = km_current_user();

/* ---- API ---- */
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($user === '') { http_response_code(401); echo json_encode(array('error' => 'ログインが必要です')); exit; }
    $api = (string)$_GET['api'];

    if ($api === 'notes' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(array('notes' => km_list($user)), JSON_UNESCAPED_UNICODE); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(array('error' => 'POSTで呼んでください')); exit; }
    $csrf = isset($_SERVER['HTTP_X_CSRF']) ? $_SERVER['HTTP_X_CSRF'] : '';
    if (!km_csrf_ok($csrf)) { http_response_code(400); echo json_encode(array('error' => '画面を開き直してください')); exit; }
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) { $in = array(); }

    if ($api === 'save') {
        list($ok, $r) = km_save($user, isset($in['id']) ? (string)$in['id'] : '',
                                isset($in['content']) ? (string)$in['content'] : '');
        if (!$ok) { http_response_code(400); echo json_encode(array('error' => $r), JSON_UNESCAPED_UNICODE); exit; }
        echo json_encode($r, JSON_UNESCAPED_UNICODE); exit;
    }
    if ($api === 'delete') {
        list($ok, $r) = km_delete($user, isset($in['id']) ? (string)$in['id'] : '');
        if (!$ok) { http_response_code(404); echo json_encode(array('error' => $r), JSON_UNESCAPED_UNICODE); exit; }
        echo json_encode(array('ok' => true)); exit;
    }
    http_response_code(404); echo json_encode(array('error' => 'そのAPIはありません')); exit;
}

/* ---- 画面 ---- */
if ($user === '') { km_login_page($self, ''); exit; }
km_app_page($self, $user);
exit;

/* ============================================================
 * ログイン画面（kcaldavと同じ見た目・📝版）
 * ============================================================ */
function km_login_page($self, $err) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow"><title>Kurage Memo</title>'
       . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#eef2f5;'
       . 'font-family:-apple-system,"Hiragino Sans","Noto Sans JP",sans-serif;color:#22303c}'
       . '.box{background:#fff;border:1px solid #dbe3ea;border-radius:16px;padding:26px 22px;width:min(340px,88vw);'
       . 'box-shadow:0 10px 30px rgba(20,40,60,.08);text-align:center}'
       . '.box .ic{font-size:40px}h1{font-size:18px;margin:6px 0 16px}'
       . 'label{display:block;text-align:left;font-size:12px;color:#5a6b7a;font-weight:700;margin:10px 0 3px}'
       . 'input{width:100%;box-sizing:border-box;border:1px solid #cfd9e2;border-radius:10px;padding:12px;font:inherit;background:#fbfdfe}'
       . '.btn{margin-top:16px;width:100%;border:0;border-radius:11px;background:#3361cc;color:#fff;font:800 15px inherit;padding:13px;cursor:pointer}'
       . '.err{background:#fdf1f1;border:1px solid #edc4c4;color:#a33;border-radius:10px;padding:8px 12px;font-size:13px;margin:0 0 10px}'
       . '</style></head><body><form class="box" method="post" action="' . km_e($self) . '">'
       . '<div class="ic">📝</div><h1>Kurage Memo にログイン</h1>'
       . ($err !== '' ? '<div class="err">' . km_e($err) . '</div>' : '')
       . '<input type="hidden" name="action" value="login">'
       . '<label>ユーザー名</label><input name="login_user" autocapitalize="none" autocorrect="off" required autofocus>'
       . '<label>パスワード</label><input name="login_pass" type="password" required>'
       . '<button class="btn">ログイン</button></form></body></html>';
}

/* ============================================================
 * 本体（Simplenote型: 左=検索+一覧 / 右=素のテキストエリア / 自動保存）
 * ============================================================ */
function km_app_page($self, $user) {
    $csrf = km_csrf();
    header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#ffffff">
<title>Kurage Memo</title>
<style>
:root{--line:#e4e9ee;--sub:#6b7a88;--blue:#3361cc;--bg:#f7f9fa;--sel:#eef3fc}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:-apple-system,"Hiragino Sans","Noto Sans JP",sans-serif;color:#22303c;overflow:hidden}
.app{display:flex;height:100dvh}
/* --- 左: 一覧 --- */
.side{width:320px;min-width:240px;border-right:1px solid var(--line);display:flex;flex-direction:column;background:var(--bg)}
.side .top{display:flex;gap:8px;padding:10px;border-bottom:1px solid var(--line);align-items:center}
.side .top input{flex:1;border:1px solid var(--line);border-radius:9px;padding:9px 12px;font:inherit;font-size:14px;background:#fff}
.side .top button{border:0;background:var(--blue);color:#fff;border-radius:9px;width:38px;height:38px;font-size:22px;line-height:1;cursor:pointer;flex:none}
.list{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch}
.item{padding:12px 14px;border-bottom:1px solid var(--line);cursor:pointer}
.item:hover{background:#f0f4f8}
.item.sel{background:var(--sel)}
.item b{display:block;font-size:14px;line-height:1.5;word-break:break-all}
.item span{display:block;font-size:12px;color:var(--sub);line-height:1.5;margin-top:2px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.item small{font-size:11px;color:#9aa8b5}
.list .none{padding:30px 14px;color:var(--sub);font-size:13px;text-align:center}
.side .foot{padding:8px 12px;border-top:1px solid var(--line);font-size:12px;color:var(--sub);display:flex;justify-content:space-between;align-items:center}
.side .foot a{color:var(--sub)}
/* --- 右: エディタ --- */
.main{flex:1;display:flex;flex-direction:column;min-width:0;background:#fff}
.bar{display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid var(--line);min-height:44px}
.bar .back{display:none;border:0;background:none;font-size:20px;cursor:pointer;color:var(--blue);padding:4px 6px}
.bar .stat{font-size:12px;color:var(--sub);margin-left:auto}
.bar .del{border:0;background:none;color:#b3564d;font-size:13px;cursor:pointer;padding:6px 8px}
textarea{flex:1;border:0;outline:0;resize:none;padding:18px 20px;font:16px/1.9 -apple-system,"Hiragino Sans","Noto Sans JP",sans-serif;color:#22303c;-webkit-overflow-scrolling:touch}
.empty{flex:1;display:grid;place-items:center;color:#9aa8b5;font-size:14px}
/* --- スマホ: 1ペイン切替 --- */
@media(max-width:640px){
  .side{width:100%;min-width:0;border-right:0}
  .main{display:none}
  body.editing .side{display:none}
  body.editing .main{display:flex}
  .bar .back{display:block}
}
</style></head><body>
<div class="app">
  <div class="side">
    <div class="top">
      <input id="q" type="search" placeholder="検索" autocomplete="off">
      <button id="new" title="新しいメモ">＋</button>
    </div>
    <div class="list" id="list"></div>
    <div class="foot"><span>📝 <?php echo km_e($user); ?></span>
      <a href="<?php echo km_e($self); ?>?do=logout">ログアウト</a></div>
  </div>
  <div class="main" id="main">
    <div class="bar">
      <button class="back" id="back">‹</button>
      <button class="del" id="del" style="display:none">削除</button>
      <span class="stat" id="stat"></span>
    </div>
    <div class="empty" id="empty">メモを選ぶか、＋で新しく書きはじめてください</div>
    <textarea id="ed" style="display:none" placeholder="ここに書く…（1行目がタイトルになります）" spellcheck="false"></textarea>
  </div>
</div>
<script>
const CSRF = <?php echo json_encode($csrf); ?>;
const API  = <?php echo json_encode($self); ?>;
let notes = [], cur = null, timer = null, dirty = false;

const $ = id => document.getElementById(id);
const rel = t => {
  const s = (Date.now()/1000 - t);
  if (s < 60) return 'たった今';
  if (s < 3600) return Math.floor(s/60) + '分前';
  if (s < 86400) return Math.floor(s/3600) + '時間前';
  const d = new Date(t*1000);
  return (d.getMonth()+1) + '月' + d.getDate() + '日';
};

async function api(name, body) {
  const opt = body === undefined
    ? {}
    : {method:'POST', headers:{'Content-Type':'application/json','X-Csrf':CSRF},
       body: JSON.stringify(body), keepalive: true};
  const r = await fetch(API + '?api=' + name, opt);
  if (r.status === 401) { location.reload(); throw 0; }
  const j = await r.json();
  if (!r.ok) throw new Error(j.error || r.status);
  return j;
}

function render() {
  const q = $('q').value.trim().toLowerCase();
  const l = $('list'); l.innerHTML = '';
  const hit = notes.filter(n => !q || n.content.toLowerCase().includes(q));
  if (!hit.length) { l.innerHTML = '<div class="none">' + (q ? '見つかりません' : 'メモはまだありません') + '</div>'; return; }
  for (const n of hit) {
    const d = document.createElement('div');
    d.className = 'item' + (cur && cur.id === n.id ? ' sel' : '');
    d.innerHTML = '<b></b><span></span><small></small>';
    d.querySelector('b').textContent = n.title;
    d.querySelector('span').textContent = n.excerpt || '　';
    d.querySelector('small').textContent = rel(n.updated);
    d.onclick = () => open(n);
    l.appendChild(d);
  }
}

function open(n) {
  flush();
  cur = n;
  $('empty').style.display = 'none';
  $('ed').style.display = ''; $('del').style.display = '';
  $('ed').value = n.content;
  $('stat').textContent = '';
  document.body.classList.add('editing');
  render();
  $('ed').focus();
}

function newNote() {
  flush();
  cur = {id: null, content: '', title: '(無題)', excerpt: '', updated: Date.now()/1000};
  $('empty').style.display = 'none';
  $('ed').style.display = ''; $('del').style.display = '';
  $('ed').value = '';
  $('stat').textContent = '';
  document.body.classList.add('editing');
  render();
  $('ed').focus();
}

async function save() {
  if (!cur || !dirty) return;
  const content = $('ed').value;
  if (cur.id === null && content.trim() === '') return;   // 空の新規は送らない
  dirty = false;
  $('stat').textContent = '保存中…';
  try {
    const r = await api('save', {id: cur.id || '', content});
    const isNew = cur.id === null;
    cur.id = r.id; cur.title = r.title; cur.excerpt = r.excerpt;
    cur.content = content; cur.updated = r.updated;
    if (isNew) notes.unshift(cur);
    else { notes = notes.filter(n => n.id !== cur.id); notes.unshift(cur); }
    $('stat').textContent = '保存しました ✓';
    render();
  } catch (e) {
    dirty = true;
    $('stat').textContent = '保存できていません（' + e.message + '）';
  }
}
function flush() { clearTimeout(timer); if (dirty) save(); }

$('ed').addEventListener('input', () => {
  dirty = true;
  $('stat').textContent = '…';
  clearTimeout(timer); timer = setTimeout(save, 700);
});
$('q').addEventListener('input', render);
$('new').onclick = newNote;
$('back').onclick = () => { flush(); document.body.classList.remove('editing'); cur = null; render(); };
$('del').onclick = async () => {
  if (!cur) return;
  if (!confirm('このメモを削除します。よろしいですか')) return;
  if (cur.id) { try { await api('delete', {id: cur.id}); } catch (e) {} }
  notes = notes.filter(n => n.id !== cur.id);
  cur = null; dirty = false;
  $('ed').style.display = 'none'; $('del').style.display = 'none';
  $('empty').style.display = '';
  document.body.classList.remove('editing');
  render();
};
addEventListener('beforeunload', flush);
document.addEventListener('visibilitychange', () => { if (document.hidden) flush(); });

api('notes').then(r => { notes = r.notes; render(); });
</script>
</body></html>
<?php
}
