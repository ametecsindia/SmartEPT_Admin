#!/usr/bin/env python3
"""
SmartEPT: markdown -> branded PDF.

Rebuilt 27-Aug-2026 from the recorded pipeline. The shape is deliberate and each step of it
exists because something simpler failed before:

  - Playwright chromium page.pdf, NOT `chromium --headless --print-to-pdf`: only the former
    supports display_header_footer, which is where the page numbers come from.
  - An ABSOLUTE file:// path to page.goto(); a relative one fails ERR_INVALID_URL.
  - Empty <thead> rows are stripped: a markdown table with a blank header renders as a solid
    dark bar across the page.
  - `\\pagebreak` sentinels in the source become <div class="pb">.
  - The masthead logo is cropped with Image.getbbox() first - the source PNG has whitespace
    padding that otherwise leaves the logo floating in the corner.

Usage:  mkpdf.py <input.md> <output.pdf> "<Doc title>" [logo.png]
"""
import base64, io, re, sys, os
from pathlib import Path

import markdown
from PIL import Image

INK, TEAL, DEEP, TINT, RULE = '#101820', '#0C8A92', '#075B61', '#E8F4F5', '#CBD5D9'


def masthead(logo_path: str) -> str:
    """Base64 <img>, cropped to the logo's actual ink."""
    if not logo_path or not Path(logo_path).exists():
        return ''
    im = Image.open(logo_path).convert('RGBA')
    box = im.getbbox()
    if box:
        im = im.crop(box)
    buf = io.BytesIO()
    im.save(buf, format='PNG')
    b64 = base64.b64encode(buf.getvalue()).decode()
    return f'<img class="logo" src="data:image/png;base64,{b64}" alt="SmartEPT">'


CSS = f"""
@page {{ size: A4; margin: 18mm 15mm 20mm 15mm; }}
* {{ box-sizing: border-box; }}
body {{ font-family: "Segoe UI", Calibri, Arial, sans-serif; font-size: 10.4pt;
        line-height: 1.55; color: {INK}; margin: 0; }}
.logo {{ height: 34px; margin-bottom: 6px; }}
.masthead {{ border-bottom: 2px solid {TEAL}; padding-bottom: 8px; margin-bottom: 18px; }}
.masthead .t {{ font-size: 17pt; font-weight: 700; color: {DEEP}; }}
.masthead .s {{ font-size: 8.5pt; letter-spacing: .11em; text-transform: uppercase; color: {TEAL}; }}
h1 {{ font-size: 16pt; color: {DEEP}; margin: 22px 0 8px; page-break-after: avoid; }}
h2 {{ font-size: 13.5pt; color: {DEEP}; margin: 20px 0 8px; padding-left: 10px;
      position: relative; page-break-after: avoid; }}
h2::before {{ content: ""; position: absolute; left: 0; top: .18em; bottom: .18em;
              width: 4px; background: {TEAL}; border-radius: 2px; }}
h3 {{ font-size: 11.6pt; color: {INK}; margin: 16px 0 6px; page-break-after: avoid; }}
h4 {{ font-size: 10.6pt; color: {DEEP}; margin: 13px 0 5px; page-break-after: avoid; }}
p, li {{ orphans: 2; widows: 2; }}
table {{ border-collapse: collapse; width: 100%; margin: 10px 0 14px; font-size: 9.4pt;
         page-break-inside: avoid; }}
th {{ background: {INK}; color: #fff; text-align: left; padding: 6px 8px; font-weight: 600; }}
td {{ border-bottom: 1px solid {RULE}; padding: 5px 8px; vertical-align: top; }}
tr:nth-child(even) td {{ background: #FAFCFC; }}
code {{ background: {TINT}; padding: 1px 4px; border-radius: 3px;
        font-family: Consolas, "Courier New", monospace; font-size: 9.2pt; }}
pre {{ background: {TINT}; border-left: 3px solid {TEAL}; padding: 9px 11px;
       overflow-x: auto; page-break-inside: avoid; }}
pre code {{ background: none; padding: 0; }}
blockquote {{ border-left: 3px solid {TEAL}; background: {TINT}; margin: 10px 0;
              padding: 8px 12px; page-break-inside: avoid; }}
blockquote h2 {{ margin-top: 4px; font-size: 12pt; }}
blockquote h2::before {{ display: none; }}
hr {{ border: 0; border-top: 1px solid {RULE}; margin: 16px 0; }}
a {{ color: {DEEP}; }}
.pb {{ page-break-after: always; }}
"""

FOOTER = f"""
<div style="width:100%;font-size:7.5pt;color:#667;padding:0 15mm;
            font-family:'Segoe UI',Arial,sans-serif;">
  <span style="float:left">SmartEPT &mdash; Ametecs India</span>
  <span style="float:right">Page <span class="pageNumber"></span> of <span class="totalPages"></span></span>
</div>"""


def build(md_path: str, pdf_path: str, title: str, logo: str = '') -> None:
    src = Path(md_path).read_text(encoding='utf-8')

    # Page-break sentinels, before markdown sees them.
    src = src.replace('\\pagebreak', '\n\n<div class="pb"></div>\n\n')

    # A `**Label:**` metadata line needs two trailing spaces or consecutive lines
    # collapse into one paragraph.
    src = re.sub(r'^(\*\*[^*\n]+:\*\*[^\n]*?)[ \t]*$', r'\1  ', src, flags=re.M)

    # Every one of these documents opens with an H1 that repeats its own title, which the
    # masthead already prints two lines above it. Drop the duplicate rather than asking five
    # source files to change their first line.
    src = re.sub(r'\A\s*#\s+' + re.escape(title) + r'\s*\n', '', src, count=1)

    html = markdown.markdown(
        src, extensions=['tables', 'fenced_code', 'sane_lists', 'attr_list'])

    # A blank table header renders as a solid dark bar.
    html = re.sub(r'<thead>\s*<tr>(?:\s*<th[^>]*>\s*</th>)+\s*</tr>\s*</thead>', '', html)

    page = (f'<!doctype html><html><head><meta charset="utf-8">'
            f'<title>{title}</title><style>{CSS}</style></head><body>'
            f'<div class="masthead">{masthead(logo)}'
            f'<div class="t">{title}</div>'
            f'<div class="s">Employee Productivity Tracking</div></div>'
            f'{html}</body></html>')

    tmp = Path(pdf_path).with_suffix('.build.html')
    tmp.write_text(page, encoding='utf-8')

    from playwright.sync_api import sync_playwright
    with sync_playwright() as pw:
        browser = pw.chromium.launch()
        pg = browser.new_page()
        pg.goto('file://' + str(tmp.resolve()), wait_until='load')   # absolute, always
        pg.pdf(path=pdf_path, format='A4', print_background=True,
               display_header_footer=True,
               header_template='<div></div>', footer_template=FOOTER,
               margin={'top': '16mm', 'bottom': '18mm', 'left': '0mm', 'right': '0mm'})
        browser.close()
    tmp.unlink(missing_ok=True)
    print(f'  {Path(pdf_path).name}  ({os.path.getsize(pdf_path):,} bytes)')


if __name__ == '__main__':
    build(sys.argv[1], sys.argv[2], sys.argv[3],
          sys.argv[4] if len(sys.argv) > 4 else '')
