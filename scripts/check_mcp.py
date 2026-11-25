#!/usr/bin/env python3
"""kmemo の AIエージェント用の入口と kmemo_mcp.php を、手元で試す（php -S で動かす・本番は触らない）。
  /usr/bin/python3 scripts/check_mcp.py"""
import json, os, subprocess, tempfile, shutil, socket, time, sys
HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
T = tempfile.mkdtemp(); shutil.copy(f"{HERE}/public/kmemo.php", f"{T}/index.php")
h = subprocess.run(["php", "-r", "echo password_hash('pw', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
open(f"{T}/kmemo_config.php", "w").write("<?php\nfunction kmemo_users() { return array('t' => '%s'); }\nfunction kmemo_api_tokens() { return array('tok-test-123456789012345678' => 't'); }\n" % h)
s = socket.socket(); s.bind(("127.0.0.1", 0)); P = s.getsockname()[1]; s.close()
srv = subprocess.Popen(["php", "-S", f"127.0.0.1:{P}", "-t", T], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL); time.sleep(0.8)
ok = fail = 0
def check(c, label):
    global ok, fail
    print(("  OK   " if c else "  FAIL ") + label); ok += bool(c); fail += (not c)
def run(msgs, **e):
    env = dict(os.environ, KMEMO_URL=f"http://127.0.0.1:{P}/", KMEMO_TOKEN="tok-test-123456789012345678"); env.update(e)
    p = subprocess.run(["php", f"{HERE}/public/kmemo_mcp.php"], input="\n".join(json.dumps(m, ensure_ascii=False) for m in msgs) + "\n", capture_output=True, text=True, env=env, timeout=60)
    return [json.loads(l) for l in p.stdout.splitlines()]
init = {"jsonrpc": "2.0", "id": 0, "method": "initialize", "params": {}}
call = lambda i, n, a: {"jsonrpc": "2.0", "id": i, "method": "tools/call", "params": {"name": n, "arguments": a}}
body = lambda r: json.loads(r["result"]["content"][0]["text"])
try:
    r = run([init, {"jsonrpc": "2.0", "id": 1, "method": "tools/list"}, call(2, "memo_create", {"content": "MCPテスト\n本文"})])
    check([t["name"] for t in r[1]["result"]["tools"]] == ["memo_list", "memo_get", "memo_create", "memo_append", "memo_update", "memo_delete"], "6つの道具が出る")
    n = body(r[2])["note"]; check(n["title"] == "MCPテスト" and n["url"].endswith("?n=" + n["id"]), "作成・1行目がタイトル・URL が返る")
    r = run([init, call(3, "memo_append", {"id": n["url"], "text": "追記"}), call(4, "memo_get", {"id": n["id"]}), call(5, "memo_list", {"q": "追記"}),
             call(6, "memo_update", {"id": n["id"], "content": "書き換え"}), call(7, "memo_delete", {"id": n["id"]}), call(8, "memo_get", {"id": "0000000000000000"})])
    check(body(r[2])["note"]["content"] == "MCPテスト\n本文\n\n追記", "URL で指定して末尾に書き足せる")
    check(body(r[3])["count"] == 1 and "content" not in body(r[3])["notes"][0], "検索できる・一覧に本文は含めない")
    check(body(r[4])["note"]["content"] == "書き換え", "書き換えられる")
    check(r[5]["result"]["isError"] and "許可されていません" in body(r[5])["error"], "削除は既定で断る")
    check(r[6]["result"]["isError"], "無いメモは 404")
    r = run([init, call(9, "memo_list", {})], KMEMO_TOKEN="wrong-token-xxxxxxxxxxxxxxxx"); check(r[1]["result"]["isError"], "違うトークンは断る")
    r = run([init, {"jsonrpc": "2.0", "id": 10, "method": "tools/list"}], KMEMO_MCP_READONLY="1"); check([t["name"] for t in r[1]["result"]["tools"]] == ["memo_list", "memo_get"], "読み取り専用は読む道具だけ")
    open(f"{T}/kmemo_config.php", "w").write("<?php\nfunction kmemo_users() { return array('t' => '%s'); }\n" % h)
    r = run([init, call(11, "memo_list", {})]); check(r[1]["result"]["isError"] and "無効" in body(r[1])["error"], "トークンを書いていない設定では入口は無効")
finally:
    srv.terminate(); shutil.rmtree(T)
print(f"\n  成功 {ok} 件 / 失敗 {fail} 件"); sys.exit(1 if fail else 0)
