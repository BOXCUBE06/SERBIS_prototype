"""Draw numbered red boxes on screenshots.

Reads screenshots/annotations.json:
    {"ADM-03": [{"x": 120, "y": 80, "w": 300, "h": 44, "label": 1}, ...], ...}
Coordinates are pixels in the raw image (top-left origin).

    python annotate.py                 annotate every image that has boxes
    python annotate.py ADM-03 MOB-12   only these
    python annotate.py --preview ADM-03   grid + box coordinates, to screenshots/preview/
    python annotate.py --init          add an empty entry for every ID in SHOT-LIST.md
"""
import argparse
import json
import re
import sys

from PIL import Image, ImageDraw, ImageFont

from common import ANNOTATED, ANNOTATIONS, PREVIEW, RAW, ROOT, print_width_mm

RED = (214, 32, 32)
CIRCLE_MM = 7.0  # printed circle diameter; the spec minimum is 6 mm


def font(size: int, bold: bool = True) -> ImageFont.FreeTypeFont:
    for name in (("arialbd.ttf", "DejaVuSans-Bold.ttf") if bold else ("arial.ttf", "DejaVuSans.ttf")):
        try:
            return ImageFont.truetype(name, size)
        except OSError:
            continue
    return ImageFont.load_default(size)


def load() -> dict:
    return json.loads(ANNOTATIONS.read_text(encoding="utf-8")) if ANNOTATIONS.exists() else {}


def circle_centre(x: int, y: int, w: int, h: int, r: int, width: int, height: int, taken: list) -> tuple[int, int]:
    """Centred on a corner of the box: top-left first, then top-right,
    bottom-left, bottom-right, taking the first that stays on the image and
    clears the circles already drawn. Else top-left, pulled onto the image."""
    fits = lambda cx, cy: r <= cx <= width - r and r <= cy <= height - r
    clear = lambda cx, cy: all((cx - tx) ** 2 + (cy - ty) ** 2 >= (2 * r + 4) ** 2 for tx, ty in taken)
    corners = ((x, y), (x + w, y), (x, y + h), (x + w, y + h))
    for cx, cy in corners:
        if fits(cx, cy) and clear(cx, cy):
            return cx, cy
    for cx, cy in corners:
        if fits(cx, cy):
            return cx, cy
    return min(max(x, r), width - r), min(max(y, r), height - r)


def draw_boxes(img: Image.Image, boxes: list) -> Image.Image:
    img = img.convert("RGB")
    d = ImageDraw.Draw(img)
    # Size marks from the printed width so circles come out >= 6 mm on paper.
    px_per_mm = img.width / print_width_mm(img.width, img.height)
    r = max(8, round(CIRCLE_MM * px_per_mm / 2))
    stroke = max(2, round(0.6 * px_per_mm))
    f = font(round(r * 1.25))
    taken = []
    for b in boxes:
        x, y, w, h = b["x"], b["y"], b["w"], b["h"]
        d.rectangle([x, y, x + w, y + h], outline=RED, width=stroke)
        # "side": "left" / "right" puts the circle beside the box, mid-height,
        # for boxes whose corners sit on text the reader needs.
        if b.get("side") in ("left", "right"):
            cx = x - r - stroke if b["side"] == "left" else x + w + r + stroke
            cx, cy = min(max(cx, r), img.width - r), y + h // 2
        else:
            cx, cy = circle_centre(x, y, w, h, r, img.width, img.height, taken)
        taken.append((cx, cy))
        d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=RED, outline="white", width=max(1, stroke // 2))
        d.text((cx, cy), str(b["label"]), fill="white", font=f, anchor="mm")
    return img


def preview(img: Image.Image, boxes: list, step: int) -> Image.Image:
    img = draw_boxes(img, boxes)
    d = ImageDraw.Draw(img)
    f = font(12, bold=False)
    for x in range(0, img.width, step):
        d.line([x, 0, x, img.height], fill=(0, 120, 255), width=1)
        d.text((x + 2, 2), str(x), fill=(0, 80, 200), font=f)
    for y in range(0, img.height, step):
        d.line([0, y, img.width, y], fill=(0, 120, 255), width=1)
        d.text((2, y + 2), str(y), fill=(0, 80, 200), font=f)
    for b in boxes:
        d.text((b["x"] + 4, b["y"] + b["h"] + 4), f'{b["label"]}: {b["x"]},{b["y"]} {b["w"]}x{b["h"]}',
               fill=(0, 0, 0), font=f, stroke_width=2, stroke_fill="white")
    return img


def init() -> None:
    ids = re.findall(r"^\| ((?:ADM|MOB)-\d+) \|", (ROOT / "SHOT-LIST.md").read_text(encoding="utf-8"), re.M)
    data = load()
    for i in ids:
        data.setdefault(i, [])
    ANNOTATIONS.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")
    print(f"{len(ids)} IDs in {ANNOTATIONS}")


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("ids", nargs="*")
    p.add_argument("--preview", action="store_true", help="write grid previews to screenshots/preview/")
    p.add_argument("--grid", type=int, default=50, help="preview grid step in pixels")
    p.add_argument("--init", action="store_true")
    a = p.parse_args()

    if a.init:
        init()
        return 0

    data = load()
    ids = a.ids or [i for i, boxes in data.items() if boxes or a.preview]
    out = PREVIEW if a.preview else ANNOTATED
    out.mkdir(parents=True, exist_ok=True)
    done = missing = 0
    for i in ids:
        src = RAW / f"{i}.png"
        if not src.exists():
            missing += 1
            continue
        with Image.open(src) as img:
            boxes = data.get(i, [])
            result = preview(img, boxes, a.grid) if a.preview else draw_boxes(img, boxes)
        result.save(out / f"{i}.png")
        done += 1
        if a.preview:
            print(out / f"{i}.png")
    print(f"{done} written to {out}, {missing} without a raw screenshot")
    return 0


if __name__ == "__main__":
    sys.exit(main())
