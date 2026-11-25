#!/usr/bin/env python3
"""Kurage Memo の PV を公開する（kpvgen ビルド完了後）。FTP は1接続。
  /usr/bin/python3 deploy_pv.py <pv.mp4> <poster.jpg>
1) https://kurage.exbridge.jp/pv/ に mp4 と poster を置く
2) kpv 台帳（kurage kpv_data/videos.json）に id=kmemo を登録・更新
3) kappstore の商品（d821883093629b57）の video_url / video_poster を入れる（台帳は退避してから書き戻し、件数を確かめる）
"""
import datetime, ftplib, io, json, os, subprocess, sys, urllib.request
PV, POSTER = sys.argv[1], sys.argv[2]
HERE = os.path.dirname(os.path.abspath(__file__))
BASE = "https://kurage.exbridge.jp/pv/"
VID, POS = BASE + "kmemo-pv.mp4", BASE + "kmemo-pv-poster.jpg"
APP = "2186dc017968081c"
SECS = int(round(float(subprocess.check_output(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", PV]).decode())))
for l in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
    if "=" in l and not l.startswith("#"):
        k, v = l.rstrip("\n").split("=", 1); os.environ.setdefault(k, v.strip().strip('"').strip("'"))
stamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
f = ftplib.FTP(os.environ["FTP_HOST"], timeout=600); f.login(os.environ["FTP_USER"], os.environ["FTP_PASS"])
f.cwd("/web/kurage_exbridge_jp/pv")
f.storbinary("STOR kmemo-pv.mp4", open(PV, "rb"), blocksize=1 << 18)
f.storbinary("STOR kmemo-pv-poster.jpg", open(POSTER, "rb"))
print("  pv/ に配置")
f.cwd("/web/kurage_exbridge_jp/kpv_data")
b = io.BytesIO(); f.retrbinary("RETR videos.json", b.write)
open(os.path.join(HERE, f"videos_backup_{stamp}.json"), "wb").write(b.getvalue())
k = json.loads(b.getvalue()); vs = k["videos"] if isinstance(k, dict) else k; n = len(vs)
row = {"id": "kmemo", "title": "Kurage Memo — MCP対応のメモ。メモのURLを貼るだけでAIが読み、AIの下書きを「メモに登録して」で残す",
       "video": VID, "poster": POS, "page": "https://proto.exbridge.jp/kmemo/", "seconds": SECS,
       "tags": ["kmemo", "mcp", "memo", "kappstore"], "hidden": 0}
hit = [v for v in vs if v.get("id") == "kmemo"]
if hit: hit[0].update(row)
else: vs.append({**row, "created_at": datetime.datetime.now().isoformat()})
f.storbinary("STOR videos.json", io.BytesIO(json.dumps(k, ensure_ascii=False, indent=1).encode("utf-8")))
print(f"  kpv 台帳: kmemo {'更新' if hit else '追加'}（{n}→{len(vs)}件）")
f.cwd("/web/kappstore_exbridge_jp/kapp_data")
b = io.BytesIO(); f.retrbinary("RETR apps.json", b.write)
open(os.path.join(HERE, f"apps_backup_{stamp}.json"), "wb").write(b.getvalue())
a = json.loads(b.getvalue()); n0 = len(a["apps"])
app = [x for x in a["apps"] if x["id"] == APP]
assert app, "商品が見つからない"
app[0]["video_url"], app[0]["video_poster"] = VID, POS
assert len(a["apps"]) == n0
f.storbinary("STOR apps.json", io.BytesIO(json.dumps(a, ensure_ascii=False, separators=(",", ":")).encode("utf-8")))
print(f"  kappstore: {APP} に video_url を入れた（台帳 {n0}件）")
f.quit()
for u in (VID, POS, "https://kurage.exbridge.jp/kpv/kmemo", f"https://kappstore.exbridge.jp/app.php?id={APP}"):
    r = urllib.request.urlopen(urllib.request.Request(u, method="HEAD" if u.endswith((".mp4", ".jpg")) else "GET", headers={"User-Agent": "kmemo-pv"}), timeout=90)
    print(f"  {r.status} {u}")
print(f"  {SECS}秒")
