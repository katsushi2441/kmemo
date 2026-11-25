<?php
/**
 * Kurage Memo — MCPサーバー（1ファイル・依存ライブラリなし）
 *
 * サーバーで動いている Kurage Memo（kmemo.php）に、Claude Code / Codex などのAIエージェントから
 * HTTPS で繋ぐ。手元にメモのデータを置く必要はない。
 *
 * できること:
 *   memo_list    メモの一覧・検索（タイトル・抜粋・URL）
 *   memo_get     1件を読む（URL https://…/memo/?n=… か メモID）
 *   memo_create  新しいメモを足す（1行目がタイトル）
 *   memo_append  メモの末尾に書き足す
 *   memo_update  メモの本文を書き換える
 *   memo_delete  メモを削除する（サーバーの設定で KMEMO_API_DELETE を true にしたときだけ）
 *
 * サーバー側の準備（kmemo_config.php）:
 *   function kmemo_api_tokens() { return array('（推測されない24文字以上）' => 'ユーザー名'); }
 *
 * 手元のPCでの登録:
 *   claude mcp add kmemo \
 *     -e KMEMO_URL=https://example.com/memo/ \
 *     -e KMEMO_TOKEN=（サーバーに書いたトークン） \
 *     -- php /path/to/kmemo_mcp.php
 *
 *   Codex CLI の場合は ~/.codex/config.toml に:
 *     [mcp_servers.kmemo]
 *     command = "php"
 *     args = ["/path/to/kmemo_mcp.php"]
 *     env = { KMEMO_URL = "https://example.com/memo/", KMEMO_TOKEN = "..." }
 *
 * 読み取りだけにする場合: KMEMO_MCP_READONLY=1
 * プロトコル: MCP stdio transport（JSON-RPC 2.0 / 1行1メッセージ）。
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('KMEMO_MCP_VERSION', '1.0.0');

$URL      = getenv('KMEMO_URL') ?: '';
$TOKEN    = getenv('KMEMO_TOKEN') ?: '';
$READONLY = (getenv('KMEMO_MCP_READONLY') === '1');

if ($URL === '' || $TOKEN === '') {
    fwrite(STDERR, "KMEMO_URL と KMEMO_TOKEN を環境変数で渡してください。\n"
        . "例: claude mcp add kmemo -e KMEMO_URL=https://example.com/memo/ -e KMEMO_TOKEN=xxxx -- php " . __FILE__ . "\n");
    exit(1);
}
if (stripos($URL, 'https://') !== 0 && stripos($URL, 'http://127.0.0.1') !== 0 && stripos($URL, 'http://localhost') !== 0) {
    fwrite(STDERR, "KMEMO_URL は https:// で指定してください（トークンが平文で流れるため）。\n");
    exit(1);
}

/** サーバーの kmemo.php の AIエージェント用の入口を呼ぶ。戻り値 = array(成功したか, 返す文字列) */
function km_call($act, array $body = array(), $method = 'POST')
{
    $u = $GLOBALS['URL'] . ((strpos($GLOBALS['URL'], '?') === false) ? '?' : '&') . 'agent=' . rawurlencode($act);
    $ch = curl_init($u);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'X-KMEMO-Token: ' . $GLOBALS['TOKEN']),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
    ));
    $res = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($res === false) { return array(false, 'サーバーに繋がりません: ' . $err); }
    $j = json_decode($res, true);
    if (!is_array($j)) { return array(false, 'Kurage Memo の応答ではありません（HTTP ' . $code . '）。KMEMO_URL がメモの画面の URL（…/memo/）か確かめてください。'); }
    return array(!empty($j['ok']), json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

$T_ID = array('type' => 'string', 'description' => 'メモの URL（https://…/memo/?n=…）か メモID');
$TOOLS_READ = array(
    array('name' => 'memo_list',
          'description' => 'メモの一覧を、更新の新しい順に返す（ID・タイトル・抜粋・更新日時・URL。本文は含まない）。q を渡すと本文で絞り込む。利用者が「あのメモ」と言ったときは、まずこれで探す。',
          'inputSchema' => array('type' => 'object', 'properties' => array(
              'q' => array('type' => 'string', 'description' => '本文に含まれる語（任意）'),
              'limit' => array('type' => 'integer', 'description' => '最大件数（任意・既定50・最大200）')))),
    array('name' => 'memo_get',
          'description' => 'メモを1件読む（本文の全文）。利用者が https://…/memo/?n=… の URL を貼ったら、これで中身を読む。',
          'inputSchema' => array('type' => 'object', 'properties' => array('id' => $T_ID), 'required' => array('id'))),
);
$TOOLS_WRITE = array(
    array('name' => 'memo_create',
          'description' => '新しいメモを足す。1行目がタイトルになる。AIが作った下書き（返信案・議事録など）を利用者のメモに置くときに使う。返ってきた URL を利用者に伝える。',
          'inputSchema' => array('type' => 'object', 'properties' => array(
              'content' => array('type' => 'string', 'description' => 'メモの本文（1行目がタイトル）')), 'required' => array('content'))),
    array('name' => 'memo_append',
          'description' => '既存のメモの末尾に、空行をはさんで書き足す。元の本文は変えない。',
          'inputSchema' => array('type' => 'object', 'properties' => array('id' => $T_ID,
              'text' => array('type' => 'string', 'description' => '書き足す文')), 'required' => array('id', 'text'))),
    array('name' => 'memo_update',
          'description' => 'メモの本文を丸ごと書き換える。元の本文は残らないので、実行前に変更の内容を利用者に確認すること。書き足すだけなら memo_append を使う。',
          'inputSchema' => array('type' => 'object', 'properties' => array('id' => $T_ID,
              'content' => array('type' => 'string', 'description' => '新しい本文（全文）')), 'required' => array('id', 'content'))),
    array('name' => 'memo_delete',
          'description' => 'メモを削除する。取り消せないので、必ず利用者の同意を得てから使う。サーバーの設定で削除を許したときだけ使える。',
          'inputSchema' => array('type' => 'object', 'properties' => array('id' => $T_ID), 'required' => array('id'))),
);
$TOOLS = $READONLY ? $TOOLS_READ : array_merge($TOOLS_READ, $TOOLS_WRITE);

function km_tool($name, $a)
{
    $w = array('memo_create', 'memo_append', 'memo_update', 'memo_delete');
    if ($GLOBALS['READONLY'] && in_array($name, $w, true)) { return array(false, '読み取り専用モードのため書き込みはできません'); }
    switch ($name) {
        case 'memo_list':   return km_call('list', array('q' => (string)($a['q'] ?? ''), 'limit' => (int)($a['limit'] ?? 50)));
        case 'memo_get':    return km_call('get', array('id' => (string)($a['id'] ?? '')));
        case 'memo_create': return km_call('create', array('content' => (string)($a['content'] ?? '')));
        case 'memo_append': return km_call('append', array('id' => (string)($a['id'] ?? ''), 'text' => (string)($a['text'] ?? '')));
        case 'memo_update': return km_call('update', array('id' => (string)($a['id'] ?? ''), 'content' => (string)($a['content'] ?? '')));
        case 'memo_delete': return km_call('delete', array('id' => (string)($a['id'] ?? '')));
    }
    return array(false, '不明なツールです: ' . $name);
}

function km_send($m) { echo json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"; flush(); }
function km_result($id, $r) { km_send(array('jsonrpc' => '2.0', 'id' => $id, 'result' => $r)); }

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') { continue; }
    $req = json_decode($line, true);
    if (!is_array($req)) { continue; }
    $id = isset($req['id']) ? $req['id'] : null;
    $method = isset($req['method']) ? $req['method'] : '';
    $params = isset($req['params']) && is_array($req['params']) ? $req['params'] : array();
    if ($id === null && strpos($method, 'notifications/') === 0) { continue; }
    switch ($method) {
        case 'initialize':
            km_result($id, array(
                'protocolVersion' => isset($params['protocolVersion']) ? (string)$params['protocolVersion'] : '2024-11-05',
                'capabilities' => array('tools' => new stdClass()),
                'serverInfo' => array('name' => 'kmemo', 'version' => KMEMO_MCP_VERSION),
                'instructions' => '利用者の Kurage Memo（自社サーバーのメモ）を読み書きする窓口です。利用者が https://…/memo/?n=… の URL を貼ったら memo_get で中身を読んでください。'
                    . '「メモに登録して」と言われたら memo_create で足し、返ってきた URL を伝えてください。本文を書き換える・削除する前は、必ず利用者に確認してください。',
            ));
            break;
        case 'ping': km_result($id, new stdClass()); break;
        case 'tools/list': km_result($id, array('tools' => $GLOBALS['TOOLS'])); break;
        case 'tools/call':
            $name = isset($params['name']) ? (string)$params['name'] : '';
            $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : array();
            $known = false; foreach ($GLOBALS['TOOLS'] as $t) { if ($t['name'] === $name) { $known = true; break; } }
            if (!$known) { km_result($id, array('content' => array(array('type' => 'text', 'text' => 'このサーバーでは使えないツールです: ' . $name)), 'isError' => true)); break; }
            list($ok, $text) = km_tool($name, $args);
            km_result($id, array('content' => array(array('type' => 'text', 'text' => $text)), 'isError' => !$ok));
            break;
        default:
            if ($id !== null) { km_send(array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => -32601, 'message' => 'Method not found: ' . $method))); }
    }
}
