"""All MOB-xx shots as labelled thumbnails in one grid: capture/contact-sheet.png."""
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

import sys
RAW = Path(__file__).resolve().parents[2] / "screenshots" / (sys.argv[1] if len(sys.argv) > 1 else "raw")
OUT = Path(__file__).with_name("contact-sheet.png" if RAW.name == "raw" else f"contact-sheet-{RAW.name}.png")
W, H, COLS, LABEL = 180, 390, 8, 28

ids = [f"MOB-{n:02d}" for n in range(1, 48)]
rows = (len(ids) + COLS - 1) // COLS
sheet = Image.new("RGB", (COLS * W, rows * (H + LABEL)), "white")
draw = ImageDraw.Draw(sheet)
font = ImageFont.truetype("arialbd.ttf", 22)
for i, id_ in enumerate(ids):
    x, y = (i % COLS) * W, (i // COLS) * (H + LABEL)
    draw.text((x + 6, y + 2), id_, fill="black", font=font)
    path = RAW / f"{id_}.png"
    if path.exists():
        im = Image.open(path).convert("RGB")
        im.thumbnail((W - 6, H - 4))
        sheet.paste(im, (x + 3, y + LABEL))
    else:
        draw.text((x + 6, y + LABEL + 20), "missing", fill="red", font=font)
sheet.save(OUT)
print(OUT)
