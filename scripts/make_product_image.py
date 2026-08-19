#!/usr/bin/env python3
# kappstore用の商品画像。キオスク実画面+マスコット。
import sys
from PIL import Image, ImageDraw, ImageFont

W, H = 1200, 675
SHOT = sys.argv[1]
MASCOT = "public/kkintai_assets/kurage_mascot.png"
OUT = "outputs/kkintai_product.png"
BLACK = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
BOLD = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"

img = Image.new("RGB", (W, H), "#f2f8f4")
d = ImageDraw.Draw(img)
for x in range(W):
    t = x / W
    r = int(0x2c + (0x3c - 0x2c) * t); g = int(0x6e + (0x8e - 0x6e) * t); b = int(0x49 + (0x59 - 0x49) * t)
    d.line([(x, 0), (x, 9)], fill=(r, g, b))

f_t = ImageFont.truetype(BLACK, 52)
f_t2 = ImageFont.truetype(BLACK, 30)
f_s = ImageFont.truetype(BOLD, 24)
f_b = ImageFont.truetype(BOLD, 21)
f_n = ImageFont.truetype(BOLD, 17)

shot = Image.open(SHOT).convert("RGB").crop((110, 0, 650, 620))
sw = 340
sh = int(shot.height * sw / shot.width)
shot = shot.resize((sw, sh), Image.LANCZOS)
fx, fy = W - sw - 40, 110
d.rounded_rectangle([fx - 10, fy - 10, fx + sw + 10, fy + sh + 10], radius=18, fill="#12241a")
img.paste(shot, (fx, fy))
d.text((fx - 6, fy + sh + 18), "実画面: タブレット1台が顔打刻機になる", font=f_n, fill="#5a6f60")

m = Image.open(MASCOT).convert("RGB").resize((110, 110), Image.LANCZOS)
d.rounded_rectangle([40, 40, 158, 158], radius=20, fill="#ffffff")
img.paste(m, (44, 44))

lx = 178
d.text((lx, 44), "顔打刻つき 勤怠システム", font=f_t, fill="#12241a")
d.text((lx, 108), "Kurage Kintai", font=f_t2, fill="#2c6e49")

feats = [
    "タブレット1台で顔打刻(専用機不要)",
    "照合はサーバーの決定的コードが正",
    "打刻は丸めない・修正は全て監査ログ",
    "月次集計と給与ソフト向けCSV",
    "顔データは同意記録+削除機能つき",
    "PHP+SQLite。レンタルサーバーで動く",
]
y = 190
lx2 = 44
for f in feats:
    d.ellipse([lx2, y + 7, lx2 + 12, y + 19], fill="#2c6e49")
    d.text((lx2 + 24, y), f, font=f_b, fill="#2f4438")
    y += 44

d.rounded_rectangle([lx2, y + 16, lx2 + 430, y + 74], radius=12, fill="#2c6e49")
d.text((lx2 + 22, y + 30), "買い切り 55,000円(税込) / 月額なし", font=f_s, fill="#ffffff")

img.save(OUT)
print("saved:", OUT)
