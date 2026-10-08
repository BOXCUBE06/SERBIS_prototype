"""Impose an A5 (or half-Letter) PDF for saddle-stitch printing.

    python impose_booklet.py manual-reading.pdf manual-booklet.pdf [--paper A4|Letter]

The input's first page is the front cover and its last page the back cover.
If the page count is not a multiple of 4, a Notes page and then blank pages
are added before the back cover. Two pages go on each side of a landscape
sheet, ordered so the printed stack folds into a booklet with no cutting.
Over 48 pages, the output is split into -part1, -part2 ... booklets.

The imposed PDF is for printing only. Edit the Markdown, then regenerate.
"""
import argparse
import sys
from pathlib import Path

from pypdf import PageObject, PdfReader, PdfWriter, Transformation
from pypdf.generic import DecodedStreamObject, DictionaryObject, NameObject

from common import BOOKLET_MAX_PAGES, MM, PAPER

INSTRUCTIONS = """To print the booklet:
  1. Print double-sided (duplex), flip on SHORT edge.
  2. Scale 100% / Actual size. Turn off "Fit to page".
  3. Keep the sheets in order, fold the stack in half, and staple on the fold if you like."""


def notes_page(w: float, h: float) -> PageObject:
    """A "Notes" heading over ruled lines, drawn as a plain PDF page."""
    page = PageObject.create_blank_page(width=w, height=h)
    left, right, top = 15 * MM, w - 15 * MM, h - 15 * MM
    ops = [f"BT /F1 16 Tf {left:.2f} {top - 16:.2f} Td (Notes) Tj ET", "0.6 G 0.5 w"]
    y = top - 14 * MM
    while y > 20 * MM:
        ops.append(f"{left:.2f} {y:.2f} m {right:.2f} {y:.2f} l S")
        y -= 8 * MM
    stream = DecodedStreamObject()
    stream.set_data("\n".join(ops).encode())
    font = DictionaryObject({NameObject("/Type"): NameObject("/Font"),
                             NameObject("/Subtype"): NameObject("/Type1"),
                             NameObject("/BaseFont"): NameObject("/Helvetica")})
    page[NameObject("/Resources")] = DictionaryObject(
        {NameObject("/Font"): DictionaryObject({NameObject("/F1"): font})})
    page[NameObject("/Contents")] = stream
    return page


def padded(pages: list) -> list:
    """Pad to a multiple of 4 before the back cover: Notes first, then blanks."""
    missing = -len(pages) % 4
    if not missing:
        return pages
    w, h = float(pages[0].mediabox.width), float(pages[0].mediabox.height)
    fill = [notes_page(w, h)] + [PageObject.create_blank_page(width=w, height=h) for _ in range(missing - 1)]
    return pages[:-1] + fill + pages[-1:]


def impose(pages: list, paper: str) -> PdfWriter:
    """Saddle-stitch order. Sheet s, front: [n-2s, 1+2s]; back: [2+2s, n-1-2s]."""
    n = len(pages)
    assert n % 4 == 0, "pad first"
    sw, sh = (v * MM for v in PAPER[paper]["sheet"])
    out = PdfWriter()
    for s in range(n // 4):
        for left, right in ((n - 2 * s, 1 + 2 * s), (2 + 2 * s, n - 1 - 2 * s)):
            sheet = PageObject.create_blank_page(width=sw, height=sh)
            for slot, num in enumerate((left, right)):
                src = pages[num - 1]
                pw, ph = float(src.mediabox.width), float(src.mediabox.height)
                # Same size as a half sheet; centre in its half in case of rounding.
                tx = slot * sw / 2 + (sw / 2 - pw) / 2
                ty = (sh - ph) / 2
                sheet.merge_transformed_page(src, Transformation().translate(tx, ty))
            out.add_page(sheet)
    return out


def write_booklets(pages: list, out: Path, paper: str) -> list[Path]:
    """Impose one booklet, or split into parts of at most 48 pages."""
    pages = padded(pages)
    chunks = [pages[i:i + BOOKLET_MAX_PAGES] for i in range(0, len(pages), BOOKLET_MAX_PAGES)]
    if len(chunks) > 1:
        print(f"  {len(pages)} pages: split into {len(chunks)} booklets of up to {BOOKLET_MAX_PAGES}. "
              "Split at a section break in the Markdown for proper covers.")
    paths = []
    for i, chunk in enumerate(chunks, 1):
        path = out if len(chunks) == 1 else out.with_name(f"{out.stem}-part{i}{out.suffix}")
        with path.open("wb") as f:
            impose(chunk, paper).write(f)
        print(f"  {path.name}: {len(chunk)} pages on {len(chunk) // 4} sheets")
        paths.append(path)
    return paths


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("input")
    p.add_argument("output")
    p.add_argument("--paper", choices=PAPER, default="A4")
    a = p.parse_args()
    write_booklets(list(PdfReader(a.input).pages), Path(a.output), a.paper)
    print(INSTRUCTIONS)
    return 0


if __name__ == "__main__":
    sys.exit(main())
