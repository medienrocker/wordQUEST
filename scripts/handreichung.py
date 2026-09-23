"""
Baut aus einer Anleitung in docs/ eine gedruckte Handreichung im
bildungssprit-Design.

    python scripts/handreichung.py docs/anleitung-kinder.md docs/wordQUEST-Elternabend.pdf

Warum ein eigenes Skript und nicht die Vorlage aus dem pdf-creator-Skill:
Diese Handreichung braucht Bildschirmfotos, einen Farbverlaufskopf und eine
Fusszeile mit Lizenz. Die Vorlage kann keine Bilder. Der Parser hier ist
derselbe in den Grundzuegen, nur um Bilder, Absatzzusammenfuehrung und den
Seitenrahmen erweitert.

Voraussetzungen: reportlab, Pillow, Arial (Windows).
"""
import io
import os
import re
import sys

from reportlab.lib.colors import HexColor, Color
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    BaseDocTemplate, Flowable, Frame, Image, KeepTogether, PageTemplate,
    Paragraph, Spacer, Table, TableStyle,
)

# ─── Marke ───────────────────────────────────────────────────────────────
NAVY      = HexColor("#031627")
NAVY_TIEF = HexColor("#072e3d")
TEAL      = HexColor("#019798")
CYAN      = HexColor("#08e0cf")
GOLD      = HexColor("#f6c83b")
PINK      = HexColor("#ff4080")
CREME     = HexColor("#fcebb7")
GRAU_BG   = HexColor("#f9f9f9")
RAHMEN    = HexColor("#e2e8f0")
TEXT_LEIS = HexColor("#64748B")

SEITE_B, SEITE_H = A4
RAND = 18 * mm
SATZ_B = SEITE_B - 2 * RAND

LOGO_BS   = os.environ.get("WQ_LOGO_BS", "")
LOGO_APP  = "wordQUEST_icon.png"
FUSSTITEL = "wordQUEST, Anleitung für Kinder"

# Emoji entfernen. Arial hat keine, und Farbemoji kann reportlab ohnehin
# nicht. Der Pfeil (U+2192) und der Mittelpunkt bleiben, die kann Arial.
EMOJI = re.compile(
    "[\U0001F000-\U0001FAFF\u2600-\u27BF\uFE0F\u2B00-\u2BFF\u2139\u3030]"
)


def schriften():
    paare = [
        ("Marke", "arial.ttf"),
        ("Marke-Fett", "arialbd.ttf"),
        ("Marke-Kursiv", "ariali.ttf"),
    ]
    for name, datei in paare:
        pfad = os.path.join("C:/Windows/Fonts", datei)
        if not os.path.exists(pfad):
            raise SystemExit("Schrift fehlt: " + pfad)
        pdfmetrics.registerFont(TTFont(name, pfad))
    pdfmetrics.registerFontFamily(
        "Marke", normal="Marke", bold="Marke-Fett", italic="Marke-Kursiv",
        boldItalic="Marke-Fett")


def stile():
    s = getSampleStyleSheet()
    s.add(ParagraphStyle(
        "Lauftext", fontName="Marke", fontSize=10, leading=14.5,
        textColor=NAVY, spaceAfter=3 * mm))
    s.add(ParagraphStyle(
        "H2", fontName="Marke-Fett", fontSize=14.5, leading=18,
        textColor=NAVY, spaceBefore=7 * mm, spaceAfter=2.5 * mm,
        keepWithNext=1))
    s.add(ParagraphStyle(
        "H3", fontName="Marke-Fett", fontSize=11.5, leading=15,
        textColor=TEAL, spaceBefore=4 * mm, spaceAfter=1.5 * mm,
        keepWithNext=1))
    s.add(ParagraphStyle(
        "Punkt", parent=s["Lauftext"], leftIndent=7 * mm, bulletIndent=2 * mm,
        spaceAfter=1.5 * mm))
    s.add(ParagraphStyle(
        "Nummer", parent=s["Lauftext"], leftIndent=7 * mm, bulletIndent=2 * mm,
        spaceAfter=1.5 * mm))
    s.add(ParagraphStyle(
        "Label", fontName="Marke-Fett", fontSize=10, leading=14,
        textColor=NAVY, spaceBefore=3 * mm, spaceAfter=0.8 * mm, keepWithNext=1))
    s.add(ParagraphStyle(
        "Zelle", fontName="Marke", fontSize=9, leading=12.5, textColor=NAVY))
    s.add(ParagraphStyle(
        "ZelleKopf", fontName="Marke-Fett", fontSize=9, leading=12.5,
        textColor=HexColor("#ffffff")))
    s.add(ParagraphStyle(
        "Bildunterschrift", fontName="Marke-Kursiv", fontSize=8.5, leading=11,
        textColor=TEXT_LEIS, alignment=TA_CENTER, spaceBefore=1.5 * mm))
    s.add(ParagraphStyle(
        "Kasten", fontName="Marke", fontSize=9.5, leading=13.5, textColor=NAVY,
        leftIndent=4 * mm, rightIndent=4 * mm, spaceAfter=2 * mm))
    s.add(ParagraphStyle(
        "KastenKopf", fontName="Marke-Fett", fontSize=10.5, leading=14,
        textColor=NAVY, leftIndent=4 * mm, rightIndent=4 * mm,
        spaceAfter=1.5 * mm))
    return s


# ─── Eigene Flowables ────────────────────────────────────────────────────
class Kopfband(Flowable):
    """Farbverlauf navy nach teal mit App-Zeichen, darunter die Akzentlinie."""

    def __init__(self, breite, hoehe=32 * mm):
        super().__init__()
        self.breite, self.hoehe = breite, hoehe

    def wrap(self, *_):
        return self.breite, self.hoehe + 3

    def draw(self):
        c = self.canv
        h = self.hoehe
        c.saveState()
        c.translate(0, 3)
        p = c.beginPath()
        p.roundRect(0, 0, self.breite, h, 4)
        c.clipPath(p, stroke=0)
        c.linearGradient(0, h, self.breite, 0, [NAVY, NAVY_TIEF, TEAL],
                         positions=[0.0, 0.6, 1.0], extend=True)
        c.restoreState()

        # Akzentlinie: cyan, gold, pink. Der Verlauf fuellt immer den
        # aktuellen Beschnitt, also muss vor jedem Verlauf ein clipPath stehen.
        c.saveState()
        p2 = c.beginPath()
        p2.rect(0, 0, self.breite, 3)
        c.clipPath(p2, stroke=0)
        c.linearGradient(0, 0, self.breite, 0, [CYAN, GOLD, PINK],
                         positions=[0.0, 0.5, 1.0], extend=True)
        c.restoreState()

        if os.path.exists(LOGO_APP):
            c.drawImage(ImageReader(LOGO_APP), 8 * mm, 3 + h / 2 - 9 * mm,
                        18 * mm, 18 * mm, mask="auto")
        c.setFillColor(HexColor("#ffffff"))
        c.setFont("Marke-Fett", 24)
        c.drawString(31 * mm, 3 + h / 2 + 1.5 * mm, "wordQUEST")
        c.setFont("Marke", 10.5)
        c.drawString(31 * mm, 3 + h / 2 - 6 * mm, "Vokabeln spielend lernen")
        c.setFont("Marke", 9)
        c.drawRightString(self.breite - 8 * mm, 3 + h / 2 - 2 * mm,
                          "wordquest.bildungssprit.de")


class Hinweiskasten(Flowable):
    """Cremefarbener Kasten mit goldener Kante links."""

    def __init__(self, breite, absaetze, stile_, rand=GOLD):
        super().__init__()
        self.breite = breite
        self.absaetze = absaetze
        self.stile = stile_
        self.rand = rand
        self._hoehe = 0

    def wrap(self, verfuegbar, _):
        self.breite = verfuegbar
        h = 4 * mm
        for text, stil in self.absaetze:
            p = Paragraph(text, self.stile[stil])
            _, ph = p.wrap(self.breite - 8 * mm, 10000)
            h += ph + self.stile[stil].spaceAfter
        self._hoehe = h + 2 * mm
        return self.breite, self._hoehe

    def draw(self):
        c = self.canv
        c.saveState()
        c.setFillColor(CREME)
        c.setStrokeColor(CREME)
        c.roundRect(0, 0, self.breite, self._hoehe, 4, stroke=0, fill=1)
        c.setFillColor(self.rand)
        c.rect(0, 2, 3, self._hoehe - 4, stroke=0, fill=1)
        c.restoreState()
        y = self._hoehe - 4 * mm
        for text, stil in self.absaetze:
            p = Paragraph(text, self.stile[stil])
            _, ph = p.wrap(self.breite - 8 * mm, 10000)
            y -= ph
            p.drawOn(c, 5 * mm, y)
            y -= self.stile[stil].spaceAfter


class Fussband(Flowable):
    """Lizenzband nach dem Muster der Marke."""

    def __init__(self, breite, titel, hoehe=14 * mm):
        super().__init__()
        self.breite, self.titel, self.hoehe = breite, titel, hoehe

    def wrap(self, verfuegbar, _):
        self.breite = verfuegbar
        return self.breite, self.hoehe

    def draw(self):
        c = self.canv
        h = self.hoehe
        c.saveState()
        c.setFillColor(GRAU_BG)
        c.setStrokeColor(PINK)
        c.setLineWidth(0.7)
        c.roundRect(0, 0, self.breite, h, 4, stroke=1, fill=1)
        if LOGO_BS and os.path.exists(LOGO_BS):
            c.drawImage(ImageReader(LOGO_BS), self.breite - 13 * mm,
                        h / 2 - 4.5 * mm, 9 * mm, 9 * mm, mask="auto")
        c.setFillColor(NAVY)
        c.setFont("Marke-Fett", 8.5)
        c.drawCentredString(self.breite / 2, h / 2 - 1 * mm, self.titel)
        c.setFillColor(TEAL)
        c.setFont("Marke-Fett", 8)
        c.drawRightString(self.breite - 16 * mm, h / 2 - 1 * mm,
                          "CC-BY-SA bildungssprit")
        c.restoreState()


# ─── Markdown ────────────────────────────────────────────────────────────
def schuetzen(text):
    return (text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;"))


def inline(text):
    text = schuetzen(EMOJI.sub("", text)).strip()
    text = re.sub(r"\*\*(.+?)\*\*", r"<b>\1</b>", text)
    text = re.sub(r"`([^`]+)`", r'<font face="Courier" size="9">\1</font>', text)
    return re.sub(r"  +", " ", text)


def bild_flowable(pfad, alt, s):
    from PIL import Image as PILImage
    with PILImage.open(pfad) as im:
        bb, bh = im.size
    hoch = bh > bb
    if hoch:
        ziel_h = 98 * mm
        ziel_b = ziel_h * bb / bh
    else:
        ziel_b = min(SATZ_B, 150 * mm)
        ziel_h = ziel_b * bh / bb
        if ziel_h > 118 * mm:
            ziel_h = 118 * mm
            ziel_b = ziel_h * bb / bh
    bild = Image(pfad, width=ziel_b, height=ziel_h)
    bild.hAlign = "CENTER"
    teile = [Spacer(1, 2 * mm), bild]
    if alt.strip():
        teile.append(Paragraph(schuetzen(EMOJI.sub("", alt)), s["Bildunterschrift"]))
    teile.append(Spacer(1, 3 * mm))
    return KeepTogether(teile)


def lesen(md_pfad, s, bild_basis):
    with io.open(md_pfad, encoding="utf-8") as f:
        zeilen = f.read().split("\n")

    story, absatz = [], []
    i = 0

    def absatz_schliessen():
        if absatz:
            story.append(Paragraph(inline(" ".join(absatz)), s["Lauftext"]))
            absatz.clear()

    while i < len(zeilen):
        roh = zeilen[i]
        z = roh.strip()

        if not z:
            absatz_schliessen()
            i += 1
            continue

        if z == "---":
            absatz_schliessen()
            i += 1
            continue

        if z.startswith("# "):
            absatz_schliessen()          # Titel steht im Kopfband
            i += 1
            continue

        if z.startswith("### "):
            absatz_schliessen()
            story.append(Paragraph(inline(z[4:]), s["H3"]))
            i += 1
            continue

        if z.startswith("## "):
            absatz_schliessen()
            story.append(Paragraph(inline(z[3:]), s["H2"]))
            i += 1
            continue

        # Eine Zeile, die komplett fett ist, ist eine Zwischenbeschriftung und
        # keine Fortsetzung des Absatzes. Sonst klebt sie am Folgesatz.
        m = re.match(r"^\*\*(.+)\*\*$", z)
        if m and not absatz:
            story.append(Paragraph(inline(m.group(1)), s["Label"]))
            i += 1
            continue

        m = re.match(r"^!\[(.*?)\]\((.+?)\)$", z)
        if m:
            absatz_schliessen()
            pfad = os.path.join(bild_basis, m.group(2))
            if os.path.exists(pfad):
                story.append(bild_flowable(pfad, m.group(1), s))
            i += 1
            continue

        if z.startswith("> "):
            absatz_schliessen()
            block = []
            while i < len(zeilen) and zeilen[i].strip().startswith(">"):
                block.append(zeilen[i].strip().lstrip(">").strip())
                i += 1
            story.append(Hinweiskasten(SATZ_B, [(inline(" ".join(block)), "Kasten")], s))
            story.append(Spacer(1, 3 * mm))
            continue

        if z.startswith("|"):
            absatz_schliessen()
            rohzeilen = []
            while i < len(zeilen) and zeilen[i].strip().startswith("|"):
                r = zeilen[i].strip()
                if not re.match(r"^\|[\s\-:|]+\|$", r):
                    rohzeilen.append([c.strip() for c in r.split("|")[1:-1]])
                i += 1
            if rohzeilen:
                story.append(tabelle(rohzeilen, s))
            continue

        m = re.match(r"^(\d+)\.\s+(.*)", z)
        if m:
            absatz_schliessen()
            story.append(Paragraph(inline(m.group(2)), s["Nummer"],
                                   bulletText=m.group(1) + "."))
            i += 1
            continue

        if z.startswith("- "):
            absatz_schliessen()
            story.append(Paragraph(inline(z[2:]), s["Punkt"], bulletText="\u2022"))
            i += 1
            continue

        absatz.append(z)
        i += 1

    absatz_schliessen()
    return ueberschrift_an_bild(story)


def ueberschrift_an_bild(story):
    """Ueberschrift und folgendes Bild in einen Block.

    keepWithNext allein reicht nicht: Steht hinter der Ueberschrift ein
    KeepTogether, laesst reportlab die Ueberschrift trotzdem allein am
    Seitenfuss stehen. Also werden beide zu einem einzigen Block.
    """
    neu_story, i = [], 0
    while i < len(story):
        eintrag = story[i]
        folgt = story[i + 1] if i + 1 < len(story) else None
        ist_kopf = (isinstance(eintrag, Paragraph)
                    and getattr(eintrag.style, "name", "") in ("H2", "H3", "Label"))
        if ist_kopf and isinstance(folgt, KeepTogether):
            neu_story.append(KeepTogether([eintrag] + list(folgt._content)))
            i += 2
            continue
        neu_story.append(eintrag)
        i += 1
    return neu_story


def tabelle(rohzeilen, s):
    daten = []
    for nr, zeile in enumerate(rohzeilen):
        stil = "ZelleKopf" if nr == 0 else "Zelle"
        daten.append([Paragraph(inline(c), s[stil]) for c in zeile])
    spalten = len(daten[0])
    if spalten == 2:
        breiten = [SATZ_B * 0.32, SATZ_B * 0.68]
    else:
        breiten = [SATZ_B / spalten] * spalten
    t = Table(daten, colWidths=breiten, repeatRows=1)
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), NAVY),
        ("GRID", (0, 0), (-1, -1), 0.4, RAHMEN),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
        ("LEFTPADDING", (0, 0), (-1, -1), 5),
        ("RIGHTPADDING", (0, 0), (-1, -1), 5),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [HexColor("#ffffff"), GRAU_BG]),
    ]))
    t.hAlign = "LEFT"
    return KeepTogether([Spacer(1, 1.5 * mm), t, Spacer(1, 3 * mm)])


# ─── Seitenrahmen ────────────────────────────────────────────────────────
def seite(c, doc):
    c.saveState()
    c.setStrokeColor(RAHMEN)
    c.setLineWidth(0.5)
    c.line(RAND, 13 * mm, SEITE_B - RAND, 13 * mm)
    c.setFont("Marke", 8)
    c.setFillColor(TEXT_LEIS)
    c.drawString(RAND, 9 * mm, FUSSTITEL)
    c.drawRightString(SEITE_B - RAND, 9 * mm, "Seite %d" % doc.page)
    c.restoreState()


def bauen(md_pfad, pdf_pfad, eltern_kasten=True):
    schriften()
    s = stile()

    story = [Kopfband(SATZ_B), Spacer(1, 6 * mm)]

    if eltern_kasten:
        story.append(Hinweiskasten(SATZ_B, [
            ("Für die Eltern", "KastenKopf"),
            ("wordQUEST ist ein kostenloses Vokabelspiel für den Browser. "
             "Es ist <b>keine Anmeldung nötig</b>, Ihr Kind gibt keinen Namen "
             "und keine Adresse ein. Der Lernstand bleibt auf dem Gerät und "
             "wird nicht an einen Server geschickt. Es gibt keine Werbung, "
             "keine Käufe und keine Rangliste, in der Kinder verglichen "
             "werden.", "Kasten"),
            ("Einmal geöffnet, läuft die App auch ohne Internet weiter. "
             "Sie braucht kein neues Gerät: Ein älteres Android-Telefon "
             "genügt.", "Kasten"),
            ("Die folgenden Seiten sind an Ihr Kind gerichtet und zum "
             "gemeinsamen Durchgehen gedacht.", "Kasten"),
        ], s, rand=PINK))
        story.append(Spacer(1, 5 * mm))

    story += lesen(md_pfad, s, os.path.dirname(md_pfad))
    story.append(Spacer(1, 6 * mm))
    story.append(Fussband(SATZ_B, "wordQUEST, Elternabend"))

    doc = BaseDocTemplate(
        pdf_pfad, pagesize=A4,
        leftMargin=RAND, rightMargin=RAND, topMargin=RAND, bottomMargin=20 * mm,
        title="wordQUEST, Anleitung für Kinder",
        author="bildungssprit.de, Falk Szyba",
        subject="Handreichung für den Elternabend")
    rahmen = Frame(RAND, 20 * mm, SATZ_B, SEITE_H - RAND - 20 * mm, id="satz",
                   leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
    doc.addPageTemplates([PageTemplate(id="standard", frames=[rahmen],
                                       onPage=seite)])
    doc.build(story)
    print("PDF erstellt:", pdf_pfad, os.path.getsize(pdf_pfad) // 1024, "KB")


if __name__ == "__main__":
    quelle = sys.argv[1] if len(sys.argv) > 1 else "docs/anleitung-kinder.md"
    ziel = sys.argv[2] if len(sys.argv) > 2 else "docs/wordQUEST-Elternabend.pdf"
    bauen(quelle, ziel)
