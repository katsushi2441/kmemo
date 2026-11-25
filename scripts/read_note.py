#!/usr/bin/env python3
"""Kurage Memo のメモを、URL（https://exbridge.jp/memo/?n=<メモID>）で指定して読む（読み取りのみ）。

ユーザーが「このメモを見て」と URL を渡したとき、AIエージェントがその中身を読むための道具。
本番のデータ（/web/exbridge_jp/memo/kmemo_data/notes_<ユーザー>.json）を FTP で読み、
メモID が一致するメモを表示する。ユーザー名は URL に無いので、全ユーザーのファイルから探す。

  set -a; . /home/kojima/work/aixec/.env; set +a
  /usr/bin/python3 scripts/read_note.py 'https://exbridge.jp/memo/?n=770810e8628946bc'
  /usr/bin/python3 scripts/read_note.py 770810e8628946bc
  /usr/bin/python3 scripts/read_note.py --list            # メモの一覧（ID・更新日時・タイトル）
"""
import ftplib, io, json, os, re, sys, datetime

DIR = "/web/exbridge_jp/memo/kmemo_data"


def load_all():
    f = ftplib.FTP(); f.encoding = "utf-8"
    f.connect(os.environ["FTP_HOST"], 21, timeout=60); f.login(os.environ["FTP_USER"], os.environ["FTP_PASS"])
    f.voidcmd("TYPE I"); f.cwd(DIR)
    out = {}
    for name in f.nlst():
        m = re.fullmatch(r"notes_([A-Za-z0-9_-]+)\.json", name)
        if not m:
            continue
        b = io.BytesIO(); f.retrbinary("RETR " + name, b.write)
        d = json.loads(b.getvalue().decode("utf-8") or "{}")
        out[m.group(1)] = d.get("notes", d) if isinstance(d, dict) else d
    f.quit()
    return out


def when(t):
    try:
        return datetime.datetime.fromtimestamp(float(t)).strftime("%Y-%m-%d %H:%M")
    except Exception:
        return str(t)


def main():
    if len(sys.argv) < 2:
        print(__doc__); sys.exit(2)
    arg = sys.argv[1]
    data = load_all()
    if arg == "--list":
        for user, notes in data.items():
            for n in sorted(notes, key=lambda n: -float(n.get("updated") or 0)):
                print(user, n.get("id"), when(n.get("updated")), (n.get("content") or "").split("\n", 1)[0][:60])
        return
    m = re.search(r"[?&]n=([0-9a-f]{8,32})", arg) or re.fullmatch(r"([0-9a-f]{8,32})", arg)
    if not m:
        sys.exit("メモの URL（…/memo/?n=…）か メモID を渡してください")
    nid = m.group(1)
    for user, notes in data.items():
        for n in notes:
            if n.get("id") == nid:
                print(f"# メモ {nid}（{user}・最終更新 {when(n.get('updated'))}）\n")
                print(n.get("content") or "")
                return
    sys.exit(f"メモ {nid} は見つかりませんでした（削除された可能性があります）")


if __name__ == "__main__":
    main()
