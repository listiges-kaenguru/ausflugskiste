#!/usr/bin/env bash
# API-Tests der Ausflugskiste (Bash, ohne Framework und ohne Abhängigkeiten).
# Am besten in einem Container mit den Testwerkzeugen ausführen:
#   tests/run.sh                         diese Tests
#   OFFEN=1 tests/run.sh                 danach bleibt der Server unter http://localhost:8001 offen
#   PHP_SERVER=<PHP mit Xdebug> tests/run.sh   mit Zeilen-Coverage der PHP-Dateien
# Brauchen php (mit pdo_sqlite), curl und jq. Arbeiten auf einer Kopie des Arbeitsstands (ohne ignorierte
# Dateien wie data/app.db) in einem temporären Ordner mit frischer Datenbank; im Repo entsteht nichts.
# PHP läuft mit eingeschalteter Fehleranzeige (ungünstigster Fall auf Webspaces): Warnungen oder Pfade in
# Antworten fallen so in der Leck-Prüfung am Ende auf.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${TEST_PORT:-8001}"
U="http://127.0.0.1:$PORT"
PHP_SERVER="${PHP_SERVER:-php}"   # für Coverage: PHP mit Xdebug
for t in php curl jq git; do
  command -v "$t" >/dev/null || { echo "$t fehlt – in einem Container mit den Testwerkzeugen ausführen (siehe README)" >&2; exit 2; }
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
abschnitt() { printf '\n-- %s\n' "$1"; }

# Akteure: je eigener Cookie-Speicher und eigene Absenderadresse, weil die Login-Sperre je IP zählt
declare -A IP=([test]=127.0.0.1 [gast]=127.0.0.2 [admin]=127.0.0.3 [anna]=127.0.0.4 [ben]=127.0.0.5
  [anna2]=127.0.0.6 [sperre]=127.0.0.7 [sperre2]=127.0.0.8 [fix]=127.0.0.9 [csrf]=127.0.0.10
  [tina]=127.0.0.11 [tina2]=127.0.0.12 [tina3]=127.0.0.13 [tina4]=127.0.0.14 [tina5]=127.0.0.15 [tina6]=127.0.0.16
  [tina7]=127.0.0.17 [pia]=127.0.0.18 [pk]=127.0.0.19 [pk2]=127.0.0.20 [pk3]=127.0.0.21 [pk4]=127.0.0.22 [weg]=127.0.0.23 [rita]=127.0.0.24 [rolf]=127.0.0.25 [rolf2]=127.0.0.26 [upd]=127.0.0.27 [upd2]=127.0.0.28
  [cli]=127.0.0.29 [cli2]=127.0.0.30 [otto]=127.0.0.31
  [emil]=127.0.0.32 [emil2]=127.0.0.33 [fritz]=127.0.0.34
  [xaver]=127.0.0.35 [yvonne]=127.0.0.36 [zoe]=127.0.0.37)
LOG="$TMP/antworten.log"

# als <akteur> <curl-argumente …>  → Antwort (JSON-Anfrage mit Pflicht-Header); jede Antwort landet im Protokoll
als() {
  local wer=$1; shift
  local r; r="$(curl -s --interface "${IP[$wer]}" -b "$TMP/k_$wer" -c "$TMP/k_$wer" \
    -H 'X-Requested-With: fetch' -H 'Content-Type: application/json' "$@")"
  printf '%s\n' "$r" >> "$LOG"; printf '%s' "$r"
}
# status <akteur> <curl-argumente …>  → nur der HTTP-Status (Antwort trotzdem ins Protokoll)
status() {
  local wer=$1; shift
  local r; r="$(curl -s --interface "${IP[$wer]}" -b "$TMP/k_$wer" -c "$TMP/k_$wer" \
    -H 'X-Requested-With: fetch' -H 'Content-Type: application/json' -w '\n%{http_code}' "$@" | tr -d '\0')"
  printf '%s\n' "$r" >> "$LOG"; printf '%s' "${r##*$'\n'}"
}
api() { als test "$@"; }

# Kopie des Arbeitsstands, Server starten
mkdir -p "$TMP/app"
(cd "$ROOT" && git ls-files -co --exclude-standard -z | tar --null -T - -c) | tar -x -C "$TMP/app"
# Versionsprüfung ohne Netz: frischer Cache mit fester Version, die App fragt GitHub dann nicht
mkdir -p "$TMP/app/data"
printf '{"version":"9.9.9","checkedAt":%s,"triedAt":%s}' "$(date +%s)" "$(date +%s)" > "$TMP/app/data/release.json"
# shellcheck disable=SC2086
$PHP_SERVER -d display_errors=1 -d display_startup_errors=1 -d error_reporting=-1 \
  -d upload_max_filesize=12M -d post_max_size=16M -S "0.0.0.0:$PORT" -t "$TMP/app" >"$TMP/server.log" 2>&1 &
SRV=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "$U/" && break; sleep 0.2; done

abschnitt "Grundfunktionen"
pruefe "Startseite" "$(curl -s "$U/" | grep -c '<html')" '. > 0'
pruefe "Einrichtung" "$(api -X POST "$U/api.php?r=setup" -d '{"username":"admin","password":"Test-Passwort-123"}')" 'type == "object" and (.error == null)'
api -X POST "$U/api.php?r=logout" >/dev/null
pruefe "Anmeldung" "$(api -X POST "$U/api.php?r=login" -d '{"username":"admin","password":"Test-Passwort-123"}')" '.error == null'
pruefe "Version (aus dem Cache, ohne Netz)" "$(api "$U/api.php?r=version")" \
  '.success == true and (.data.installed | type == "string" and length > 0) and .data.latest == "9.9.9"'
pruefe "Ziele lesen" "$(api "$U/api.php?r=destinations")" '.success == true and (.data | type == "array")'

abschnitt "Favorit/Besucht per PATCH"
VOLL='{"name":"Patch-Ziel","address":"Weg 1","description":"Text","rating":4,"privateNotes":"notiz","favorite":false,"visited":false}'
DP="$(api -X POST "$U/api.php?r=destinations" -d "$VOLL" | jq -r '.data.id')"
VORHER="$(api "$U/api.php?r=destinations/$DP")"
pruefe "PATCH favorite → vollständiges Ziel zurück" "$(api -X PATCH "$U/api.php?r=destinations/$DP" -d '{"favorite":true}')" \
  '.success == true and .data.favorite == true and .data.visited == false and .data.name == "Patch-Ziel"'
pruefe "PATCH visited allein lässt favorite stehen" "$(api -X PATCH "$U/api.php?r=destinations/$DP" -d '{"visited":true}')" \
  '.data.favorite == true and .data.visited == true'
pruefe "PATCH beide zugleich" "$(api -X PATCH "$U/api.php?r=destinations/$DP" -d '{"favorite":false,"visited":false}')" \
  '.data.favorite == false and .data.visited == false'
pruefe "PATCH ändert keine anderen Felder" "$(jq -cn --argjson a "$VORHER" --argjson b "$(api "$U/api.php?r=destinations/$DP")" \
  '[$a.data, $b.data] | map(del(.favorite, .visited, .updatedAt))')" '.[0] == .[1]'

abschnitt "Oberfläche im Browser (Playwright)"
SITZUNG="$(awk '$6 == "session" { print $7 }' "$TMP/k_test")"
OBERFLAECHE="$(node "$ROOT/tests/oberflaeche.cjs" "$U" "$SITZUNG" 2>&1)"
pruefe "Profil lädt ohne Skriptfehler" "$OBERFLAECHE" '.profileLoaded == true and .errors == []'
pruefe "Profil ohne „null“/„undefined“ im Text (Zwei-Faktor aus)" "$OBERFLAECHE" '.profileNull == 0'
pruefe "Profil: Knopf „Einrichten“ für Zwei-Faktor" "$OBERFLAECHE" '.totpSetupButton == 1'
pruefe "Profil: „Ziele exportieren“ lädt die eigenen Ziele als Datei" "$OBERFLAECHE" \
  '.exportDownload.format == "ausflugskiste-export" and (.exportDownload.filename | test("^ausflugskiste-admin-[0-9-]{10}\\.json$")) and (.exportDownload.names | index("Patch-Ziel") != null)'
pruefe "Umschalter Favorit sendet PATCH nur mit favorite" "$OBERFLAECHE" '.toggleRequest.method == "PATCH" and .toggleRequest.body == {"favorite": true}'
# Profil mit aktiver Zwei-Faktor-Anmeldung: eigener Benutzer, damit admin für die weiteren Tests ohne Code bleibt
api -X POST "$U/api.php?r=users" -d '{"username":"otto","password":"Start-Otto-1"}' >/dev/null
als otto -X POST "$U/api.php?r=login" -d '{"username":"otto","password":"Start-Otto-1"}' >/dev/null
als otto -X POST "$U/api.php?r=change-password" -d '{"newPassword":"Otto-Passwort-1"}' >/dev/null
GEHEIM_O="$(als otto -X POST "$U/api.php?r=totp/setup" -d '{}' | jq -r '.data.secret')"
als otto -X POST "$U/api.php?r=totp" -d "{\"code\":\"$(php "$ROOT/tests/totp-code.php" "$GEHEIM_O")\"}" >/dev/null
OBERFLAECHE_2FA="$(node "$ROOT/tests/oberflaeche.cjs" "$U" "$(awk '$6 == "session" { print $7 }' "$TMP/k_otto")" nur-profil 2>&1)"
pruefe "Profil mit aktiver Zwei-Faktor-Anmeldung lädt" "$OBERFLAECHE_2FA" '.profileLoaded == true and .totpActive == 1 and .errors == []'
pruefe "Profil ohne „null“/„undefined“ im Text (Zwei-Faktor aktiv)" "$OBERFLAECHE_2FA" '.profileNull == 0'
pruefe "Umschalter: Favorit danach gespeichert" "$(api "$U/api.php?r=destinations/$DP")" '.data.favorite == true and .data.name == "Patch-Ziel"'

# shellcheck source=tests/sicherheit.sh
. "$ROOT/tests/sicherheit.sh"
# shellcheck source=tests/cli.sh
. "$ROOT/tests/cli.sh"
# shellcheck source=tests/export.sh
. "$ROOT/tests/export.sh"

echo
echo "$ANZAHL Prüfungen, $FEHLER fehlgeschlagen"
if [ "$FEHLER" -gt 0 ]; then echo "Serverprotokoll:"; tail -20 "$TMP/server.log"; fi

if [ -n "${OFFEN:-}" ]; then
  echo "Läuft: http://localhost:$PORT/ (Anmeldung admin / Test-Passwort-123) – Strg+C beendet"
  wait "$SRV"
fi
[ "$FEHLER" -eq 0 ]
