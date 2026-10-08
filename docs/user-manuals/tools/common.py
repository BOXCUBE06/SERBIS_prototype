"""Paths and page geometry shared by the manual tools."""
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent          # docs/user-manuals
REPO = ROOT.parent.parent
RAW = ROOT / "screenshots" / "raw"
ANNOTATED = ROOT / "screenshots" / "annotated"
PREVIEW = ROOT / "screenshots" / "preview"
ANNOTATIONS = ROOT / "screenshots" / "annotations.json"
EXPORT = ROOT / "export"
LOGOS = REPO / "Web" / "serbis-admin-vue" / "public" / "logos"

MM = 72 / 25.4  # points per mm

# Booklet page (half the printing sheet) and the sheet it is printed on, in mm.
PAPER = {
    "A4": {"page": (148.0, 210.0), "sheet": (297.0, 210.0)},
    "Letter": {"page": (139.7, 215.9), "sheet": (279.4, 215.9)},  # 5.5 x 8.5 in on 11 x 8.5 in
}

# Margins in mm. Inner is the fold side (gutter).
MARGIN_OUTER, MARGIN_INNER, MARGIN_TOP, MARGIN_BOTTOM = 15.0, 20.0, 15.0, 15.0

BOOKLET_MAX_PAGES = 48  # 12 sheets folded together


def text_width_mm(paper: str) -> float:
    return PAPER[paper]["page"][0] - MARGIN_OUTER - MARGIN_INNER


def print_width_mm(img_w: int, img_h: int, paper: str = "Letter") -> float:
    """How wide a screenshot is printed. Phone shots (portrait) are narrower
    so they stay short enough for a page; desktop shots take the full width.
    Letter is the narrower page, so it is the safe default for sizing marks."""
    full = text_width_mm(paper)
    return min(full, 50.0) if img_h > img_w else full
