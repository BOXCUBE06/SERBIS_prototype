"""Open a .docx in headless LibreOffice, refresh its table of contents, save as PDF.

Run with LibreOffice's own Python (it has the `uno` module), not the venv:
    "C:\\Program Files\\LibreOffice\\program\\python.exe" lo_export.py in.docx out.pdf
build.py calls this for you.
"""
import os
import subprocess
import sys
import tempfile
import time
from pathlib import Path

import uno
from com.sun.star.beans import PropertyValue


def prop(name, value):
    p = PropertyValue()
    p.Name, p.Value = name, value
    return p


def main(src: str, dst: str) -> None:
    soffice = Path(sys.executable).with_name("soffice.exe")
    port = 2083
    profile = Path(tempfile.mkdtemp(prefix="serbis-lo-")).as_uri()
    office = subprocess.Popen([str(soffice), "--headless", "--invisible", "--nologo", "--norestore",
                               f"--accept=socket,host=127.0.0.1,port={port};urp;",
                               f"-env:UserInstallation={profile}"])
    try:
        resolver = uno.getComponentContext().ServiceManager.createInstanceWithContext(
            "com.sun.star.bridge.UnoUrlResolver", uno.getComponentContext())
        for _ in range(240):  # first start with a fresh profile is slow
            try:
                ctx = resolver.resolve(f"uno:socket,host=127.0.0.1,port={port};urp;StarOffice.ComponentContext")
                break
            except Exception:
                time.sleep(0.5)
        else:
            raise RuntimeError("LibreOffice did not start")

        desktop = ctx.ServiceManager.createInstanceWithContext("com.sun.star.frame.Desktop", ctx)
        doc = desktop.loadComponentFromURL(Path(src).resolve().as_uri(), "_blank", 0, (prop("Hidden", True),))
        # The TOC field from Word holds placeholder text until it is updated here.
        # Two passes: the first can change the page count, the second fixes numbers.
        for _ in range(2):
            indexes = doc.getDocumentIndexes()
            for i in range(indexes.getCount()):
                indexes.getByIndex(i).update()
            doc.refresh()
        filter_data = uno.Any("[]com.sun.star.beans.PropertyValue", (
            prop("IsSkipEmptyPages", False),   # keep blank pages that put sections on the right
            prop("ExportBookmarks", True),
            prop("UseTaggedPDF", True),
        ))
        doc.storeToURL(Path(dst).resolve().as_uri(), (prop("FilterName", "writer_pdf_Export"),
                                                      prop("FilterData", filter_data)))
        doc.close(True)
    finally:
        try:
            desktop.terminate()
        except Exception:
            pass
        try:
            office.wait(timeout=20)
        except subprocess.TimeoutExpired:
            office.kill()


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
    os._exit(0)
