#!/usr/bin/env bash
# API-Tests der Ausflugskiste (Bash, ohne Framework und ohne Abhängigkeiten).
# Laufen in der Testumgebung (~/projects/testumgebung):
#   pt test                 mit Syntax- und Linkprüfung
#   pt bash tests/run.sh    nur diese Tests
#   pt offen                danach bleibt der Server unter http://localhost:8001 offen
# Brauchen php (mit pdo_sqlite), curl und jq. Arbeiten auf einer Kopie des Arbeitsstands (ohne ignorierte
# Dateien wie data/app.db) in einem temporären Ordner mit frischer Datenbank; im Repo entsteht nichts.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${TEST_PORT:-8001}"
U="http://localhost:$PORT"
for t in php curl jq git; do
  command -v "$t" >/dev/null || { echo "$t fehlt – in der Testumgebung ausführen: ~/projects/testumgebung/pt test" >&2; exit 2; }
done

TMP="$(mktemp -d)"
SRV=
aufraeumen() { [ -n "$SRV" ] && kill "$SRV" 2>/dev/null; rm -rf "$TMP"; }
trap aufraeumen EXIT

ANZAHL=0
FEHLER=0
# pruefe <name> <antwort> <jq-ausdruck>
pruefe() {
  ANZAHL=$((ANZAHL + 1))
  if echo "$2" | jq -e "$3" >/dev/null 2>&1; then echo "ok   $1"
  else echo "FAIL $1: ${2:0:300}"; FEHLER=$((FEHLER + 1)); fi
}
api() { curl -s -b "$TMP/cookies" -c "$TMP/cookies" -H 'X-Requested-With: fetch' -H 'Content-Type: application/json' "$@"; }

# Kopie des Arbeitsstands, Server starten
mkdir -p "$TMP/app"
(cd "$ROOT" && git ls-files -co --exclude-standard -z | tar --null -T - -c) | tar -x -C "$TMP/app"
php -S "0.0.0.0:$PORT" -t "$TMP/app" >"$TMP/server.log" 2>&1 &
SRV=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "$U/" && break; sleep 0.2; done

pruefe "Startseite" "$(curl -s "$U/" | grep -c '<html')" '. > 0'
pruefe "version" "$(api "$U/api.php?r=version")" 'type == "object"'
pruefe "Einrichtung" "$(api -X POST "$U/api.php?r=setup" -d '{"username":"admin","password":"Test-Passwort-123"}')" 'type == "object" and (.error == null)'
api -X POST "$U/api.php?r=logout" >/dev/null
pruefe "Anmeldung" "$(api -X POST "$U/api.php?r=login" -d '{"username":"admin","password":"Test-Passwort-123"}')" '.error == null'
pruefe "Ziele lesen" "$(api "$U/api.php?r=destinations")" 'type == "object" or type == "array"'

echo "$ANZAHL Prüfungen, $FEHLER fehlgeschlagen"
if [ "$FEHLER" -gt 0 ]; then echo "Serverprotokoll:"; tail -20 "$TMP/server.log"; fi

if [ -n "${OFFEN:-}" ]; then
  echo "Läuft: $U/ (Anmeldung admin / Test-Passwort-123) – Strg+C beendet"
  wait "$SRV"
fi
[ "$FEHLER" -eq 0 ]
