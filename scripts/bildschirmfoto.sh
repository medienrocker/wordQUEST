#!/bin/bash
# Nimmt ein Bild der App fuer die Anleitungen auf.
#
#   bash scripts/bildschirmfoto.sh <zustand> [breite] [hoehe]
#   bash scripts/bildschirmfoto.sh alle
#
# Erwartet einen laufenden lokalen Server auf Port 8199 und Chrome.
# Die Erklaerung dazu steht in docs/BILDSCHIRMFOTOS.md.
set -u

CHROME="${WQ_CHROME:-C:/Program Files/Google/Chrome/Application/chrome.exe}"
BASIS="${WQ_BASIS:-http://127.0.0.1:8199}"
WURZEL="$(cd "$(dirname "$0")/.." && pwd)"
ZIELORDNER="$WURZEL/docs/bilder"

ZUSTAENDE="start menue listen quiz falsch ueben adaptiv klasse punktekarte punkte lernstand vokabeln tafel sprache"

if [ ! -x "$CHROME" ] && [ ! -f "$CHROME" ]; then
  echo "Chrome nicht gefunden: $CHROME" >&2
  echo "Pfad ueber WQ_CHROME setzen." >&2
  exit 1
fi

if ! curl -sf -o /dev/null "$BASIS/index.html"; then
  echo "Kein Server unter $BASIS. Siehe docs/BILDSCHIRMFOTOS.md." >&2
  exit 1
fi

mkdir -p "$ZIELORDNER"

# Ein frisches Profil je Aufnahme. Ein wiederverwendetes blockiert den zweiten
# Start, dann entsteht gar kein Bild und der Aufruf laeuft in den Zeitablauf.
aufnehmen() {
  zustand="$1"; breite="${2:-390}"; hoehe="${3:-844}"
  ziel="$ZIELORDNER/app-$zustand.png"
  profil="$(mktemp -d)"
  timeout 120 "$CHROME" --headless=new --disable-gpu --no-first-run \
    --no-default-browser-check --user-data-dir="$profil" \
    --virtual-time-budget=9000 --hide-scrollbars \
    --window-size="$breite,$hoehe" --screenshot="$ziel" \
    "$BASIS/scripts/bildschirmfoto.html?s=$zustand&w=$breite&h=$hoehe" \
    > /dev/null 2>&1
  rm -rf "$profil"
  if [ -f "$ziel" ]; then
    echo "  app-$zustand.png"
  else
    echo "  app-$zustand.png FEHLGESCHLAGEN" >&2
  fi
}

if [ "${1:-}" = "alle" ]; then
  for z in $ZUSTAENDE; do
    if [ "$z" = "menue" ]; then aufnehmen "$z" 760 900; else aufnehmen "$z"; fi
  done
else
  aufnehmen "${1:?Zustand fehlt. Moeglich: alle $ZUSTAENDE}" "${2:-390}" "${3:-844}"
fi
