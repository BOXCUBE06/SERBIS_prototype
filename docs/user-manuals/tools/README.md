# Manual tools

Run these from `docs/user-manuals/tools/` with the venv's Python:

```
.venv\Scripts\python.exe <script> ...
```

The venv is gitignored. To recreate it, run `py -3.12 -m venv .venv`, then `.venv\Scripts\pip install Pillow pypdf python-docx`. LibreOffice must be installed for PDF output.

## 1. Capture

Save each screenshot as `../screenshots/raw/<ID>.png`. The ID, screen and state for each one are in `../SHOT-LIST.md`.

## 2. Mark it up

1. In `../screenshots/annotations.json`, list the boxes for the image. Use pixel coordinates in the raw image, with `label` matching the legend number in the manual:
   ```json
   "ADM-01": [
     {"x": 412, "y": 260, "w": 360, "h": 48, "label": 1},
     {"x": 412, "y": 330, "w": 360, "h": 48, "label": 2}
   ]
   ```
2. Check the positions with `annotate.py --preview ADM-01`. It writes `../screenshots/preview/ADM-01.png` with a pixel grid and each box's coordinates. Adjust and repeat.
3. Run `annotate.py` to write every marked-up image to `../screenshots/annotated/`. To redo only some, list them: `annotate.py ADM-01 MOB-05`.
4. If you add IDs to `SHOT-LIST.md`, run `annotate.py --init` to add an empty entry for each new ID.

Circles are sized from the width the image prints at, so they come out at about 7 mm on paper.

## 3. Build

```
build.py                         both manuals, A4 printing sheets
build.py admin-panel             one manual
build.py --paper Letter          half-Letter pages (5.5 x 8.5 in) on Letter sheets
build.py --docx-only             only the .docx, no PDFs
```

The output goes to `../export/`:

| File | Use |
|---|---|
| `<manual>.docx` | Editable copy with the same structure |
| `<manual>-reading.pdf` | A5 pages in order, for reading on screen |
| `<manual>-booklet.pdf` | For printing (admin: `-booklet-part1.pdf`, `-booklet-part2.pdf`) |

How the build works:
- It takes `annotated/` images first, then `raw/`. A missing screenshot prints as a grey placeholder, so you can build at any time.
- LibreOffice fills in the contents page numbers when it makes the PDF (`lo_export.py`).
- The page count is padded to a multiple of 4: a Notes page first, then blank pages, all before the back cover.
- In `admin-panel.md`, the `<!-- part-break -->` line splits the printed booklet into part 1 (sections 1–5) and part 2 (sections 6–10). Each part gets its own cover. Each booklet must stay at 48 pages or fewer.

## 4. Print the booklet

1. Print double-sided (duplex), flipping on the **short edge**.
2. Set the scale to 100% / Actual size. Turn off "Fit to page".
3. Fold the stack in half.

The booklet PDF is for printing only. To change the content, edit the Markdown, then run `build.py` again.

`impose_booklet.py in.pdf out.pdf [--paper A4|Letter]` imposes any A5 PDF whose first page is the front cover and last page the back cover.
