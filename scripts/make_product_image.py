#!/usr/bin/env python3
# kappstore用の商品画像。実画面(デモのスクリーンショット)を主役にする。
from PIL import Image, ImageDraw, ImageFont

W, H = 1200, 675
SHOT = "/tmp/km_product_shot.png"
OUT = "outputs/kmemo_product.png"
BLACK = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
BOLD = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"

img = Image.new("RGB", (W, H), "#f4f7fb")
d = ImageDraw.Draw(img)
for x in range(W):   # 上帯
    t = x / W
    r = int(0x33 + (0x22 - 0x33) * t); g = int(0x61 + (0x44 - 0x61) * t); b = int(0xcc + (0x99 - 0xcc) * t)
    d.line([(x, 0), (x, 9)], fill=(r, g, b))

f_t = ImageFont.truetype(BLACK, 54)
f_s = ImageFont.truetype(BOLD, 25)
f_b = ImageFont.truetype(BOLD, 21)
f_n = ImageFont.truetype(BOLD, 18)

# 右: 実画面(デモバナーは切って、一覧+本文の2ペインを見せる)
shot = Image.open(SHOT).convert("RGB").crop((0, 29, 1280, 720))
sw = 640
sh = int(shot.height * sw / shot.width)
shot = shot.resize((sw, sh), Image.LANCZOS)
fx, fy = W - sw - 40, 120
d.rounded_rectangle([fx - 10, fy - 10, fx + sw + 10, fy + sh + 10], radius=18, fill="#16202e")
img.paste(shot, (fx, fy))

x = 52
d.text((x, 82), "Kurage Memo", font=f_t, fill="#16202e")
d.text((x, 156), "自分のサーバーに置く、シンプルなメモ", font=f_s, fill="#3361cc")

lines = [
    "1行目がタイトル。考えずに書きはじめる",
    "保存ボタンなし。書いた端から自動保存",
    "全文を即時に検索。スマホ対応",
    "マルチユーザー。家族・社内で1台に同居",
]
y = 216
for ln in lines:
    d.ellipse([x + 2, y + 9, x + 12, y + 19], fill="#3361cc")
    d.text((x + 24, y), ln, font=f_b, fill="#41506a")
    y += 38

y = 396
for tag in ["1ファイルPHP", "データベース不要", "MIT ライセンス"]:
    tw = d.textlength(tag, font=f_n)
    d.rounded_rectangle([x, y, x + tw + 30, y + 38], radius=19, fill="#e4ebf9")
    d.text((x + 15, y + 8), tag, font=f_n, fill="#2b4699")
    x += tw + 42
    if x > 470: x = 52; y += 48

d.text((52, 560), "メモは自分のレンタルサーバーの中。月額ゼロ", font=f_s, fill="#16202e")
d.text((52, 604), "株式会社エクスブリッジ（名古屋）", font=f_n, fill="#6a7a94")
img.save(OUT)
print("built:", OUT, img.size)
