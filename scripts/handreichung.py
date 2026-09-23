"""
Baut aus den Anleitungen in docs/ gedruckte Handreichungen im
bildungssprit-Design.

    python scripts/handreichung.py            # baut alle drei
    python scripts/handreichung.py kinder     # nur eine

Jede Seite bekommt oben ein Markenband und unten eine Markenfusszeile.
Gezeichnet werden beide in einer eigenen Canvas-Klasse, weil in der Fusszeile
"Seite 3 von 11" stehen soll und die Gesamtzahl erst feststeht, wenn alle
Seiten gesetzt sind.

Warum ein eigenes Skript und nicht die Vorlage aus dem pdf-creator-Skill:
Diese Handreichungen brauchen Bildschirmfotos, Farbverlaeufe und einen
Seitenrahmen. Die Vorlage kann keine Bilder.

Voraussetzungen: reportlab, Pillow, Arial (Windows).
Das bildungssprit-Zeichen wird einmal geladen und neben dem Skript
zwischengespeichert, damit ein Bau auch ohne Netz funktioniert.
"""
import io
import os
import re
import sys

from reportlab.lib.colors import HexColor
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas as pdfcanvas
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
WEISS     = HexColor("#ffffff")

SEITE_B, SEITE_H = A4
RAND   = 18 * mm
SATZ_B = SEITE_B - 2 * RAND

KOPF_OBEN  = SEITE_H - 10 * mm      # Oberkante des Kopfbands
KOPF_H     = 12 * mm
AKZENT_H   = 2.5
SATZ_OBEN  = KOPF_OBEN - KOPF_H - AKZENT_H - 7 * mm
FUSS_LINIE = 17 * mm
SATZ_UNTEN = 23 * mm

HIER        = os.path.dirname(os.path.abspath(__file__))
WURZEL      = os.path.dirname(HIER)
LOGO_BS     = os.path.join(HIER, ".marke-bildungssprit.png")
LOGO_BS_URL = "https://img.bildungssprit.de/dbimg/bildungssprit_logo.png"
LOGO_APP    = os.path.join(WURZEL, "wordQUEST_icon.png")

# Emoji entfernen. Arial hat keine, und Farbemoji kann reportlab ohnehin
# nicht. Der Pfeil (U+2192) und der Mittelpunkt bleiben, die kann Arial.
EMOJI = re.compile(
    "[\U0001F000-\U0001FAFF\u2600-\u27BF\uFE0F\u2B00-\u2BFF\u2139\u3030]"
)

ELTERN_KASTEN = [
    ("Für die Eltern", "KastenKopf"),
    ("wordQUEST ist ein kostenloses Vokabelspiel für den Browser. Es ist "
     "<b>keine Anmeldung nötig</b>, Ihr Kind gibt keinen Namen und keine "
     "Adresse ein. Der Lernstand bleibt auf dem Gerät und wird nicht an einen "
     "Server geschickt. Es gibt keine Werbung, keine Käufe und keine "
     "Rangliste, in der Kinder verglichen werden.", "Kasten"),
    ("Einmal geöffnet, läuft die App auch ohne Internet weiter. Sie braucht "
     "kein neues Gerät: Ein älteres Android-Telefon genügt.", "Kasten"),
    ("Die folgenden Seiten sind an Ihr Kind gerichtet und zum gemeinsamen "
     "Durchgehen gedacht.", "Kasten"),
]

AUSGABEN = {
    "kinder": {
        "md": "docs/anleitung-kinder.md",
        "pdf": "docs/wordQUEST-Elternabend.pdf",
        "titel": "Anleitung für Kinder",
        "unterzeile": "Zum gemeinsamen Durchgehen am Elternabend",
        "kasten": ELTERN_KASTEN,
    },
    "lehrkraefte": {
        "md": "docs/anleitung-lehrkraefte.md",
        "pdf": "docs/wordQUEST-Lehrkraefte.pdf",
        "titel": "Anleitung für Lehrkräfte",
        "unterzeile": "Klasse anlegen, Lernstand der Gruppe, eigene Wortlisten",
        "kasten": None,
    },
    "admin": {
        "md": "docs/anleitung-admin.md",
        "pdf": "docs/wordQUEST-Admincenter.pdf",
        "titel": "Anleitung für Admins",
        "unterzeile": "Einreichungen, Wortlisten, Bilder, Übersicht",
        "kasten": None,
    },
}


# ─── Vorbereitung ────────────────────────────────────────────────────────
def schriften():
    for name, datei in [("Marke", "arial.ttf"), ("Marke-Fett", "arialbd.ttf"),
                        ("Marke-Kursiv", "ariali.ttf")]:
        pfad = os.path.join("C:/Windows/Fonts", datei)
        if not os.path.exists(pfad):
            raise SystemExit("Schrift fehlt: " + pfad)
        pdfmetrics.registerFont(TTFont(name, pfad))
    pdfmetrics.registerFontFamily(
        "Marke", normal="Marke", bold="Marke-Fett", italic="Marke-Kursiv",
        boldItalic="Marke-Fett")


def markenzeichen():
    """Holt das bildungssprit-Zeichen einmal und legt es neben das Skript."""
    if os.path.exists(LOGO_BS):
        return
    try:
        from urllib.request import urlopen
        from PIL import Image as PILImage
        with urlopen(LOGO_BS_URL, timeout=15) as antwort:
            roh = antwort.read()
        bild = PILImage.open(io.BytesIO(roh)).convert("RGBA")
        bild.thumbnail((256, 256))
        bild.save(LOGO_BS)
    except Exception as fehler:
        print("Hinweis: Markenzeichen nicht geladen (" + str(fehler) + ").")


def stile():
    s = getSampleStyleSheet()
    s.add(ParagraphStyle(
        "Lauftext", fontName="Marke", fontSize=10, leading=14.5,
        textColor=NAVY, spaceAfter=3 * mm))
    s.add(ParagraphStyle(
        "H2", fontName="Marke-Fett", fontSize=14.5, leading=18,
        textColor=NAVY, spaceBefore=7 * mm, spaceAfter=2.5 * mm, keepWithNext=1))
    s.add(ParagraphStyle(
        "H3", fontName="Marke-Fett", fontSize=11.5, leading=15,
        textColor=TEAL, spaceBefore=4 * mm, spaceAfter=1.5 * mm, keepWithNext=1))
    s.add(ParagraphStyle(
        "Label", fontName="Marke-Fett", fontSize=10, leading=14,
        textColor=NAVY, spaceBefore=3 * mm, spaceAfter=0.8 * mm, keepWithNext=1))
    s.add(ParagraphStyle(
        "Punkt", parent=s["Lauftext"], leftIndent=7 * mm, bulletIndent=2 * mm,
        spaceAfter=1.5 * mm))
    s.add(ParagraphStyle(
        "Nummer", parent=s["Lauftext"], leftIndent=7 * mm, bulletIndent=2 * mm,
        spaceAfter=1.5 * mm))
    s.add(ParagraphStyle(
        "Zelle", fontName="Marke", fontSize=9, leading=12.5, textColor=NAVY))
    s.add(ParagraphStyle(
        "ZelleKopf", fontName="Marke-Fett", fontSize=9, leading=12.5,
        textColor=WEISS))
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
    s.add(ParagraphStyle(
        "Haupttitel", fontName="Marke-Fett", fontSize=22, leading=27,
        textColor=NAVY, spaceAfter=1.5 * mm))
    s.add(ParagraphStyle(
        "Unterzeile", fontName="Marke", fontSize=11, leading=15,
        textColor=TEAL, spaceAfter=5 * mm))
    return s


def akzentstreifen(c, x, y, breite, hoehe):
    """Cyan, Gold, Pink. Der Verlauf fuellt immer den aktuellen Beschnitt,
    also muss vor jedem Verlauf ein clipPath stehen."""
    c.saveState()
    pfad = c.beginPath()
    pfad.rect(x, y, breite, hoehe)
    c.clipPath(pfad, stroke=0)
    c.linearGradient(x, y, x + breite, y, [CYAN, GOLD, PINK],
                     positions=[0.0, 0.5, 1.0], extend=True)
    c.restoreState()


# ─── Seitenrahmen ────────────────────────────────────────────────────────
class MarkenCanvas(pdfcanvas.Canvas):
    """Zeichnet Kopf- und Fusszeile auf jede Seite.

    Die Seiten werden zwischengehalten, weil in der Fusszeile die
    Gesamtseitenzahl stehen soll. Die kennt man erst am Ende.
    """

    titel = ""

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self._seiten = []

    def showPage(self):
        self._seiten.append(dict(self.__dict__))
        self._startPage()

    def save(self):
        gesamt = len(self._seiten)
        for zustand in self._seiten:
            self.__dict__.update(zustand)
            self._kopf()
            self._fuss(gesamt)
            super().showPage()
        super().save()

    def _kopf(self):
        c = self
        y = KOPF_OBEN - KOPF_H
        c.saveState()
        pfad = c.beginPath()
        pfad.roundRect(RAND, y, SATZ_B, KOPF_H, 4)
        c.clipPath(pfad, stroke=0)
        c.linearGradient(RAND, KOPF_OBEN, RAND + SATZ_B, y,
                         [NAVY, NAVY_TIEF, TEAL], positions=[0.0, 0.6, 1.0],
                         extend=True)
        c.restoreState()
        akzentstreifen(c, RAND, y - AKZENT_H, SATZ_B, AKZENT_H)

        if os.path.exists(LOGO_APP):
            c.drawImage(ImageReader(LOGO_APP), RAND + 3.5 * mm, y + 2 * mm,
                        8 * mm, 8 * mm, mask="auto")
        c.setFillColor(WEISS)
        c.setFont("Marke-Fett", 11)
        c.drawString(RAND + 14 * mm, y + 4.4 * mm, "wordQUEST")
        versatz = c.stringWidth("wordQUEST", "Marke-Fett", 11) + 4 * mm
        c.setFont("Marke", 9)
        c.drawString(RAND + 14 * mm + versatz, y + 4.4 * mm, self.titel)
        c.setFont("Marke", 8.5)
        c.drawRightString(RAND + SATZ_B - 4 * mm, y + 4.4 * mm,
                          "wordquest.bildungssprit.de")

    def _fuss(self, gesamt):
        c = self
        akzentstreifen(c, RAND, FUSS_LINIE, SATZ_B, 1.6)
        logo = os.path.exists(LOGO_BS)
        if logo:
            c.drawImage(ImageReader(LOGO_BS), RAND, FUSS_LINIE - 9.5 * mm,
                        7 * mm, 7 * mm, mask="auto")
        links = RAND + (9.5 * mm if logo else 0)
        c.setFillColor(NAVY)
        c.setFont("Marke-Fett", 8)
        c.drawString(links, FUSS_LINIE - 7.2 * mm, "bildungssprit.de")
        c.setFillColor(TEXT_LEIS)
        c.setFont("Marke", 8)
        c.drawString(links + 24 * mm, FUSS_LINIE - 7.2 * mm,
                     "CC-BY-SA | Falk Szyba @medienrocker")
        c.drawRightString(RAND + SATZ_B, FUSS_LINIE - 7.2 * mm,
                          "Seite %d von %d" % (self.getPageNumber(), gesamt))


# ─── Bausteine ───────────────────────────────────────────────────────────
class Hinweiskasten(Flowable):
    """Cremefarbener Kasten mit farbiger Kante links."""

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


# ─── Markdown ────────────────────────────────────────────────────────────
def schuetzen(text):
    return text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def inline(text):
    text = schuetzen(EMOJI.sub("", text)).strip()
    text = re.sub(r"\*\*(.+?)\*\*", r"<b>\1</b>", text)
    text = re.sub(r"`([^`]+)`", r'<font face="Courier" size="9">\1</font>', text)
    return re.sub(r"  +", " ", text)


def bild_flowable(pfad, alt, s):
    from PIL import Image as PILImage
    with PILImage.open(pfad) as im:
        bb, bh = im.size
    if bh > bb:                               # Telefonaufnahme
        ziel_h = 96 * mm
        ziel_b = ziel_h * bb / bh
    else:
        ziel_b = min(SATZ_B, 150 * mm)
        ziel_h = ziel_b * bh / bb
        if ziel_h > 112 * mm:
            ziel_h = 112 * mm
            ziel_b = ziel_h * bb / bh
    bild = Image(pfad, width=ziel_b, height=ziel_h)
    bild.hAlign = "CENTER"
    teile = [Spacer(1, 2 * mm), bild]
    if alt.strip():
        teile.append(Paragraph(schuetzen(EMOJI.sub("", alt)), s["Bildunterschrift"]))
    teile.append(Spacer(1, 3 * mm))
    return KeepTogether(teile)


def tabelle(rohzeilen, s):
    daten = []
    for nr, zeile in enumerate(rohzeilen):
        stil = "ZelleKopf" if nr == 0 else "Zelle"
        daten.append([Paragraph(inline(c), s[stil]) for c in zeile])
    spalten = len(daten[0])
    breiten = ([SATZ_B * 0.32, SATZ_B * 0.68] if spalten == 2
               else [SATZ_B / spalten] * spalten)
    t = Table(daten, colWidths=breiten, repeatRows=1)
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), NAVY),
        ("LINEBELOW", (0, 0), (-1, 0), 1.5, CYAN),
        ("GRID", (0, 0), (-1, -1), 0.4, RAHMEN),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
        ("LEFTPADDING", (0, 0), (-1, -1), 5),
        ("RIGHTPADDING", (0, 0), (-1, -1), 5),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [WEISS, GRAU_BG]),
    ]))
    t.hAlign = "LEFT"
    return KeepTogether([Spacer(1, 1.5 * mm), t, Spacer(1, 3 * mm)])


def ueberschrift_an_bild(story):
    """Ueberschrift und folgendes Bild in einen Block.

    keepWithNext allein reicht nicht: Steht hinter der Ueberschrift ein
    KeepTogether, laesst reportlab die Ueberschrift trotzdem allein am
    Seitenfuss stehen.
    """
    neu, i = [], 0
    while i < len(story):
        eintrag = story[i]
        folgt = story[i + 1] if i + 1 < len(story) else None
        ist_kopf = (isinstance(eintrag, Paragraph)
                    and getattr(eintrag.style, "name", "") in ("H2", "H3", "Label"))
        if ist_kopf and isinstance(folgt, KeepTogether):
            neu.append(KeepTogether([eintrag] + list(folgt._content)))
            i += 2
            continue
        neu.append(eintrag)
        i += 1
    return neu


LISTENMARKE = re.compile(r"^\s*(?:[-*]\s|\d+\.\s)")


def listenzeilen_zusammenziehen(zeilen):
    """Eingerueckte Folgezeile gehoert zum Listenpunkt darueber.

    Ohne das wurde aus einem umgebrochenen Aufzaehlungspunkt ein zweiter,
    nicht eingerueckter Absatz, und der Text sprang im Satz nach links.
    """
    raus = []
    for roh in zeilen:
        fortsetzung = (
            raus
            and roh[:1] in (" ", "\t")
            and roh.strip()
            and not roh.lstrip().startswith(("-", "*", "|", ">", "#", "!"))
            and not LISTENMARKE.match(roh)
            and LISTENMARKE.match(raus[-1])
        )
        if fortsetzung:
            raus[-1] = raus[-1].rstrip() + " " + roh.strip()
        else:
            raus.append(roh)
    return raus


def lesen(md_pfad, s, bild_basis):
    with io.open(md_pfad, encoding="utf-8") as f:
        zeilen = listenzeilen_zusammenziehen(f.read().split("\n"))

    story, absatz = [], []
    i = 0

    def absatz_schliessen():
        if absatz:
            story.append(Paragraph(inline(" ".join(absatz)), s["Lauftext"]))
            absatz.clear()

    while i < len(zeilen):
        z = zeilen[i].strip()

        if not z or z == "---":
            absatz_schliessen()
            i += 1
            continue

        if z.startswith("# "):               # Titel steht im Kopfblock
            absatz_schliessen()
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
            else:
                print("  fehlendes Bild:", pfad)
            i += 1
            continue

        if z.startswith("> "):
            absatz_schliessen()
            block = []
            while i < len(zeilen) and zeilen[i].strip().startswith(">"):
                block.append(zeilen[i].strip().lstrip(">").strip())
                i += 1
            story.append(Hinweiskasten(SATZ_B,
                                       [(inline(" ".join(block)), "Kasten")],
                                       s, rand=PINK))
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


# ─── Bau ─────────────────────────────────────────────────────────────────
def bauen(rezept):
    s = stile()
    md_pfad = os.path.join(WURZEL, rezept["md"])
    pdf_pfad = os.path.join(WURZEL, rezept["pdf"])

    story = [
        Paragraph(rezept["titel"], s["Haupttitel"]),
        Paragraph(rezept["unterzeile"], s["Unterzeile"]),
    ]
    if rezept["kasten"]:
        story.append(Hinweiskasten(SATZ_B, rezept["kasten"], s, rand=PINK))
        story.append(Spacer(1, 5 * mm))
    story += lesen(md_pfad, s, os.path.dirname(md_pfad))

    # Der Titel gehoert in die Kopfzeile jeder Seite, die Canvas-Klasse kennt
    # aber nur Klassenattribute. Also je Ausgabe eine eigene kleine Klasse.
    klasse = type("MarkenCanvasAusgabe", (MarkenCanvas,),
                  {"titel": rezept["titel"]})

    doc = BaseDocTemplate(
        pdf_pfad, pagesize=A4,
        leftMargin=RAND, rightMargin=RAND,
        topMargin=SEITE_H - SATZ_OBEN, bottomMargin=SATZ_UNTEN,
        title="wordQUEST, " + rezept["titel"],
        author="bildungssprit.de, Falk Szyba",
        subject=rezept["unterzeile"])
    rahmen = Frame(RAND, SATZ_UNTEN, SATZ_B, SATZ_OBEN - SATZ_UNTEN, id="satz",
                   leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
    doc.addPageTemplates([PageTemplate(id="standard", frames=[rahmen])])
    doc.build(story, canvasmaker=klasse)
    print("  %-34s %4d KB" % (rezept["pdf"], os.path.getsize(pdf_pfad) // 1024))


if __name__ == "__main__":
    schriften()
    markenzeichen()
    gewuenscht = sys.argv[1:] or list(AUSGABEN)
    for name in gewuenscht:
        if name not in AUSGABEN:
            raise SystemExit("Unbekannt: " + name + ". Moeglich: "
                             + ", ".join(AUSGABEN))
        print(name)
        bauen(AUSGABEN[name])
