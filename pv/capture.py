#!/usr/bin/env python3
"""Kurage Memo の PV 用の画面を 1920x1080 で撮る（2026-11-25）。
手元で kmemo を php -S で動かし（架空のメモだけ）、実際に kmemo_mcp.php の memo_get / memo_create を呼んで、その結果を画面にする。
  /usr/bin/python3 pv/capture.py"""
import json, os, subprocess, tempfile, shutil, socket, time, html, re
from playwright.sync_api import sync_playwright
HERE = os.path.dirname(os.path.abspath(__file__)); ROOT = os.path.dirname(HERE); CAPS = f"{HERE}/caps"
T = tempfile.mkdtemp(); shutil.copy(f"{ROOT}/public/kmemo.php", f"{T}/index.php")
h = subprocess.run(["php", "-r", "echo password_hash('demo', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
open(f"{T}/kmemo_config.php", "w").write("<?php\nfunction kmemo_users() { return array('demo' => '%s'); }\nfunction kmemo_api_tokens() { return array('tok-pv-1234567890123456789012' => 'demo'); }\n" % h)
now = time.time()
seed = [
 ("取引先からの相談（納期の件）\n\n株式会社さくら精機 佐藤様より\n\n先日ご注文いただいた制御盤の部品ですが、メーカーの生産の都合で、\n納期が12月10日から12月20日に延びる見込みです。\n現場の工程に影響があるか、ご確認いただけますでしょうか。\n代わりの部品で早く納める案もあります。", 3600),
 ("定例会議 11/25\n\n・新しい受発注システムの説明会は12月3日\n・見積もりの書式を統一する（担当：田中）\n・年末の在庫確認は12月26日まで", 7200),
 ("見積もりの条件（青葉工業様）\n\n・設置作業込み\n・支払いは月末締め翌月末\n・有効期限は30日", 86400),
 ("展示会で聞いたこと\n\n・ブースの来場者は製造業の総務が多い\n・紙の申請書をなくしたい会社が多い", 172800),
 ("買うもの\n\n・プリンターのトナー\n・名刺の追加", 259200),
]
import secrets
notes = [{"id": secrets.token_hex(8), "content": c, "created": now - a, "updated": now - a} for i, (c, a) in enumerate(seed)]
os.makedirs(f"{T}/kmemo_data", exist_ok=True); json.dump({"notes": notes}, open(f"{T}/kmemo_data/notes_demo.json", "w"), ensure_ascii=False)
s = socket.socket(); s.bind(("127.0.0.1", 0)); P = s.getsockname()[1]; s.close()
srv = subprocess.Popen(["php", "-S", f"127.0.0.1:{P}", "-t", T], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL); time.sleep(0.8)
B = f"http://127.0.0.1:{P}/"; SHOW = "proto.exbridge.jp/kmemo/"

def mcp(name, args):
    env = dict(os.environ, KMEMO_URL=B, KMEMO_TOKEN="tok-pv-1234567890123456789012")
    msgs = [{"jsonrpc": "2.0", "id": 0, "method": "initialize", "params": {}}, {"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": name, "arguments": args}}]
    p = subprocess.run(["php", f"{ROOT}/public/kmemo_mcp.php"], input="\n".join(json.dumps(m, ensure_ascii=False) for m in msgs) + "\n", capture_output=True, text=True, env=env, timeout=60)
    return json.loads(json.loads(p.stdout.splitlines()[1])["result"]["content"][0]["text"])

src_id = notes[0]["id"]
got = mcp("memo_get", {"id": f"https://{SHOW}?n={src_id}"})
reply = "納期の件への返信案（さくら精機 佐藤様）\n\n佐藤様\n\nご連絡ありがとうございます。\n納期が12月20日になる件、承知しました。\n現場の据え付けが12月18日の予定のため、代わりの部品で早く納める案を\nご提案いただけますでしょうか。\n部品の型番と納期をお知らせいただければ、こちらで確認いたします。"

CSS = """body{margin:0;background:#1e1f24;font-family:"Noto Sans Mono CJK JP","Noto Sans CJK JP",monospace;color:#e6e6e6}
.win{margin:150px auto 0;width:1760px;height:700px;background:#1a1b20;border:1px solid #3a3b42;border-radius:14px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.4)}
.bar{height:44px;background:#2a2b31;display:flex;align-items:center;padding:0 18px;gap:9px;color:#9aa0aa;font-size:20px}
.dot{width:14px;height:14px;border-radius:50%;display:inline-block}.body{padding:26px 40px;font-size:27px;line-height:1.55;white-space:pre-wrap}
.u{color:#fff}.u:before{content:"> ";color:#d97757;font-weight:700}.tool{color:#8fd3a0;margin-top:14px}.res{color:#b7bcc6;border-left:3px solid #44464f;padding-left:18px;margin:6px 0 14px}
.ai{color:#e6e6e6;margin-top:8px}.ai:before{content:"● ";color:#d97757}.url{color:#7cc4ff}"""
def term(title, parts, out):
    body = "".join(f'<div class="{c}">{t}</div>' for c, t in parts)
    page = f'<html><head><meta charset="utf-8"><style>{CSS}</style></head><body><div class="win"><div class="bar"><span class="dot" style="background:#ff5f57"></span><span class="dot" style="background:#febc2e"></span><span class="dot" style="background:#28c840"></span>&nbsp; {html.escape(title)}</div><div class="body">{body}</div></div></body></html>'
    f = f"{T}/{out}.html"; open(f, "w").write(page); return f
e = html.escape
FRAME = """<html><head><meta charset="utf-8"><style>body{margin:0;background:#dfe5ea;font-family:"Noto Sans CJK JP",sans-serif}
.w{margin:26px auto;width:1840px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 14px 40px rgba(20,40,60,.18)}
.b{height:58px;background:#f1f3f5;display:flex;align-items:center;padding:0 20px;gap:14px;border-bottom:1px solid #dde3e8}
.a{flex:1;background:#fff;border:1px solid #d5dce2;border-radius:20px;padding:8px 18px;font-size:23px;color:#33414e}.a b{color:#3361cc}
img{display:block;width:1840px}</style></head><body><div class="w"><div class="b"><span style="font-size:22px;color:#889">◀ ▶ ⟳</span><div class="a">ADDR</div></div><img src="SHOT"></div></body></html>"""
try:
    with sync_playwright() as p:
        br = p.chromium.launch()
        ctx = br.new_context(viewport={"width": 1280, "height": 672}, device_scale_factor=1.44)
        pg = ctx.new_page(); pg.goto(B); pg.fill("input[name=login_user]", "demo"); pg.fill("input[name=login_pass]", "demo"); pg.click("button.btn"); pg.wait_for_load_state()
        def shot(nid, fn):
            pg.goto(B + (f"?n={nid}" if nid else "")); pg.wait_for_timeout(900); pg.screenshot(path=f"{T}/{fn}.png")
        shot(None, "raw0"); shot(src_id, "raw1")
        made = mcp("memo_create", {"content": reply}); new_id = made["note"]["id"]
        shot(new_id, "raw2")
        global t1, t2
        t1 = term("Claude Code — MCP: kmemo", [
         ("u", e(f"https://{SHOW}?n={src_id} このメモを読んで、返信案を作って")),
         ("tool", "kmemo - memo_get (MCP)"),
         ("res", e(got["note"]["title"]) + "\n" + e("\n".join(got["note"]["content"].split("\n")[2:6])) + "\n…"),
         ("ai", e("さくら精機の佐藤様から、制御盤の部品の納期が12月20日に延びるという相談です。\n現場への影響と、代わりの部品の案を聞く返信案を作りました。")),
        ], "t1")
        t2 = term("Claude Code — MCP: kmemo", [
         ("u", e("いいね。memo に登録して")),
         ("tool", "kmemo - memo_create (MCP)"),
         ("res", e(f'"title": "{made["note"]["title"]}"\n"url": ') + f'<span class="url">https://{SHOW}?n={new_id}</span>'),
         ("ai", e("メモに登録しました。画面で開いて、そのまま直せます。\n") + f'<span class="url">https://{SHOW}?n={new_id}</span>'),
        ], "t2")
        v = br.new_page(viewport={"width": 1920, "height": 1080})
        for raw, addr, out in (("raw0", f"{SHOW}", "01-list"), ("raw1", f"{SHOW}?n=<b>{src_id}</b>", "02-url"), ("raw2", f"{SHOW}?n=<b>{new_id}</b>", "05-created")):
            open(f"{T}/{out}.html", "w").write(FRAME.replace("ADDR", addr).replace("SHOT", f"{raw}.png"))
            v.goto(f"file://{T}/{out}.html"); v.wait_for_timeout(400); v.screenshot(path=f"{CAPS}/{out}.png", clip={"x": 0, "y": 0, "width": 1920, "height": 1080})
        for f, out in ((t1, "03-ai-get"), (t2, "04-ai-create")):
            v.goto(f"file://{f}"); v.wait_for_timeout(300); v.screenshot(path=f"{CAPS}/{out}.png")
        br.close()
finally:
    srv.terminate(); shutil.rmtree(T)
print("撮った:", sorted(os.listdir(CAPS)))
