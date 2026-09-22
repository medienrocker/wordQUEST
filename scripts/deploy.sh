#!/usr/bin/env bash
#
# wordQUEST Deploy auf nostalgic-vaughan.
#
# Der Docroot IST das Git-Checkout: kein Build, kein rsync, kein dist.
# Aufruf als root im Docroot:
#
#   bash scripts/deploy.sh
#
# Das Skript liegt im Repo und aktualisiert sich beim Pull selbst mit.
# Es bricht ab, statt etwas heimlich zu verwerfen.

set -euo pipefail

DOCROOT="/var/www/vhosts/bildungssprit.de/wordquest.bildungssprit.de/httpdocs"
OWNER="bs_vps-user:psaserv"
WELLKNOWN_OWNER="bs_vps-user:psacln"
BRANCH="main"

cd "$DOCROOT"

# safe.directory nur ergänzen, nie setzen. Liegen auf der Box schon Einträge
# anderer Projekte, scheitert ein einfaches --global sonst mit
# "cannot overwrite multiple values with a single value".
if ! git config --global --get-all safe.directory 2>/dev/null | grep -qxF "$DOCROOT"; then
  git config --global --add safe.directory "$DOCROOT"
fi

# Sauberer, vorspulbarer Stand. Ein Hotfix, der nur auf der Box existiert,
# darf nicht stillschweigend verloren gehen, deshalb hier kein reset --hard.
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "FEHLER: Working Tree ist dirty."
  echo "Erst ansehen: git status  /  git diff"
  exit 1
fi

git fetch origin

if ! git merge-base --is-ancestor HEAD "origin/$BRANCH"; then
  echo "FEHLER: Server-Stand ist von origin/$BRANCH divergiert."
  echo "Nicht automatisch auflösen, erst nachsehen."
  exit 1
fi

git pull --ff-only origin "$BRANCH"

# Plesk-Rechte wiederherstellen.
# NIEMALS git clean in diesem Verzeichnis: .well-known ist untracked und
# überlebt zwar Pull und reset, aber kein clean. Ohne .well-known brechen
# die Let's-Encrypt-Erneuerungen.
chown -R "$OWNER" "$DOCROOT"
if [ -d "$DOCROOT/.well-known" ]; then
  chown -R "$WELLKNOWN_OWNER" "$DOCROOT/.well-known"
fi

echo "--------------------------------------------"
git log --oneline -1
grep -m1 "CACHE_VERSION *=" sw.js || echo "HINWEIS: CACHE_VERSION in sw.js nicht gefunden"
echo "=== WORDQUEST DEPLOY OK ==="
echo "Auf dem Gerät einmal Strg+F5, sonst liefert der Service Worker"
echo "noch einen Reload lang die alte Fassung."
