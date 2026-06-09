import sys, types
# Broken cryptography rust binding panics on import; stub it so fpdf's
# optional encryption import fails cleanly (ImportError) instead.
for name in ("cryptography", "endesive"):
    m = types.ModuleType(name)
    sys.modules[name] = m  # no __path__ -> submodule imports raise ModuleNotFoundError

from fpdf import FPDF

NAVY = (47, 72, 88)
GREEN_BG = (227, 239, 227)
GREEN_TX = (31, 93, 31)
WARN_BG = (255, 243, 214)
ALT_BG = (242, 245, 247)
LINE = (184, 196, 204)

rows = [
    ("1.1",  "West", "Schlafen 1",                 "2-tlg.: DK + festverglast",            "1835 x 1238", "1835 x 1498", False),
    ("1.3",  "West", "BAD Hauptfenster",            "2-tlg.: DK + DK",                      "3080 x 700",  "3080 x 960",  False),
    ("1.5a", "West", "Schlafen 2",                  "2-tlg.: DK + festverglast",            "1835 x 1238", "1835 x 1498", False),
    ("1.5b", "West", "Schlafen 3",                  "2-tlg.: DK + festverglast",            "1835 x 1238", "1835 x 1498", False),
    ("2.1",  "Sued", "Schlafen 3",                  "2-tlg.: DK + festverglast",            "3080 x 1238", "3080 x 1498", False),
    ("2.3",  "Sued", "Lounge mit Sitzbank",         "1-tlg.: komplett festverglast",        "3780 x 1700", "3780 x 1960", True),
    ("2.5",  "Sued", "Schlafen 5 (1 langes Elem.)", "4-tlg.: DK + fest + fest + DK",        "4000 x 1238", "4000 x 1498", False),
    ("3.1a", "Ost",  "Schlafen 5 - Balkontuer/HST", "Balkontuer 2-tlg.: DK + festvergl.",   "2600 x 2400", "2600 x 2660", False),
    ("3.1b", "Ost",  "Schlafen 4 - Balkontuer/HST", "Balkontuer 2-tlg.: DK + festvergl.",   "2600 x 2400", "2600 x 2660", False),
    ("TN1",  "Nord", "Flur - Balkontuer Flachdach", "Balkontuer 1-tlg.: DK (Aussent. RC2)", "1010 x 2100", "1010 x 2360", False),
]

# column widths (sum ~= 269mm usable on A4 landscape with 14mm margins)
cols = [16, 18, 56, 70, 52, 57]
heads = ["Pos.", "Fassade", "Raum / Nutzung", "Aufteilung / Oeffnungsart",
         "Reine Masse\nB x H [mm]", "+ Aufsatzkasten 260\nB x H [mm]"]

pdf = FPDF(orientation="L", unit="mm", format="A4")
pdf.set_margins(14, 14, 14)
pdf.set_auto_page_break(auto=True, margin=14)
pdf.add_page()

# Title
pdf.set_text_color(*NAVY)
pdf.set_font("Helvetica", "B", 16)
pdf.cell(0, 8, "Fensterliste OG - BV Pham", ln=1)
pdf.set_font("Helvetica", "", 9.5)
pdf.set_text_color(90, 107, 120)
pdf.cell(0, 5, "Reine Fenster-/Elementmasse und neue Hoehe mit Aufsatzkasten  -  "
               "System ROMA PURO 2.XR-RS (Aufsatz-Raffstore)  -  "
               "Kastenhoehe 260 mm  -  Stand 09.06.2026", ln=1)
pdf.ln(3)

# Header row
pdf.set_font("Helvetica", "B", 9)
pdf.set_fill_color(*NAVY)
pdf.set_text_color(255, 255, 255)
pdf.set_draw_color(*LINE)
pdf.set_line_width(0.2)
hh = 9
x0 = pdf.get_x(); y0 = pdf.get_y()
for w, h in zip(cols, heads):
    x = pdf.get_x(); y = pdf.get_y()
    pdf.multi_cell(w, 4.5, h, border=1, align="C", fill=True,
                   max_line_height=4.5, new_x="RIGHT", new_y="TOP")
    pdf.set_xy(x + w, y)
pdf.set_xy(x0, y0 + hh)

# Body
pdf.set_font("Helvetica", "", 8.5)
for i, (pos, fas, raum, art, mass, neu, warn) in enumerate(rows):
    rh = 7
    x = pdf.get_x(); y = pdf.get_y()
    base_fill = WARN_BG if warn else (ALT_BG if i % 2 else (255, 255, 255))
    vals = [pos, fas, raum, art, mass, neu]
    aligns = ["C", "C", "L", "L", "C", "C"]
    for j, (w, v, al) in enumerate(zip(cols, vals, aligns)):
        cx = pdf.get_x(); cy = pdf.get_y()
        if j == 5:
            pdf.set_fill_color(*GREEN_BG); pdf.set_text_color(*GREEN_TX)
            pdf.set_font("Helvetica", "B", 8.5)
        else:
            pdf.set_fill_color(*base_fill); pdf.set_text_color(28, 43, 54)
            pdf.set_font("Helvetica", "B" if j == 0 else "", 8.5)
        pad = 1.5 if al == "L" else 0
        pdf.rect(cx, cy, w, rh, style="DF")
        pdf.set_xy(cx + pad, cy)
        pdf.cell(w - pad, rh, v, align=al)
        pdf.set_xy(cx + w, cy)
    pdf.set_xy(x, y + rh)

# Notes
pdf.ln(4)
pdf.set_text_color(*NAVY)
pdf.set_font("Helvetica", "B", 11)
pdf.cell(0, 6, "Anmerkungen", ln=1)
pdf.set_font("Helvetica", "", 9.5)
pdf.set_text_color(28, 43, 54)
notes = [
    "Reine Fenstermasse: Spalte 'Reine Masse' enthaelt nur das Fenster-/Element selbst. "
    "Noch NICHT enthalten: Rahmenverbreiterung links/rechts je 30 mm, unten 60 mm (bei Tueren/Schwelle) "
    "sowie die Anschlaege nach Vorgabe Rick.",
    "Aufsatzkasten: rechte Spalte = reine Hoehe + 260 mm Kastenhoehe (ROMA PURO 2.XR-RS). "
    "Die Breite aendert sich durch den Kasten nicht.",
    "Pos. 2.3 Lounge (gelb): Mit 260er-Kasten wird der Sturzbereich sehr knapp. "
    "Position geht so an Rick (Megerle) - Feedback abwarten, bevor fixiert wird.",
]
for n in notes:
    bx = pdf.get_x()
    pdf.set_font("Helvetica", "B", 9.5)
    pdf.cell(4, 5, "-")
    pdf.set_font("Helvetica", "", 9.5)
    pdf.multi_cell(0, 5, n, new_x="LMARGIN", new_y="NEXT")
    pdf.ln(0.5)

pdf.ln(2)
pdf.set_draw_color(*LINE); pdf.set_line_width(0.2)
y = pdf.get_y(); pdf.line(14, y, pdf.w - 14, y)
pdf.ln(1)
pdf.set_font("Helvetica", "", 8)
pdf.set_text_color(122, 136, 147)
pdf.multi_cell(0, 4,
    "BV Pham  -  OG  -  Bezug: OK Rohbeton OG = 0,00 / FFB +280 mm  -  "
    "Ausfuehrung RC2N, ROMA PURO 2.XR-RS, Behangfarbe Lichtgrau, Systemfarbe weiss  -  "
    "Elektro-Motor (Verkabelung bauseits).")

out = "/home/user/newss/Fensterliste_OG_Pham.pdf"
pdf.output(out)
print("OK", out)
