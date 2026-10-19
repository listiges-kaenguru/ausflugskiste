# Tests des Wartungsskripts server/cli.php. Wird von tests/run.sh nach tests/sicherheit.sh eingebunden (Server,
# Helfer, Akteure und hochladen stehen dort bereit), nicht allein ausführen.
# Das Skript arbeitet auf derselben Datenbank wie der Testserver ($TMP/app/data); die Prüfungen zum letzten
# Admin laufen auf einem eigenen, frischen Datenordner.

# cli [--eingabe <text>] <argumente …> → JSON {code, out, err} (Exit-Code, Standardausgabe, Fehlerausgabe)
cli() {
  local eingabe=
  if [ "${1:-}" = --eingabe ]; then eingabe=$2; shift 2; fi
  # shellcheck disable=SC2086
  printf '%s' "$eingabe" | $PHP_SERVER "$TMP/app/server/cli.php" "$@" >"$TMP/cli.out" 2>"$TMP/cli.err"
  local code=$?
  jq -n --argjson code "$code" --rawfile out "$TMP/cli.out" --rawfile err "$TMP/cli.err" '{code: $code, out: $out, err: $err}'
}
anmelden() { als "$1" -X POST "${A}login" -d "$(json --arg u "$2" --arg p "$3" '{username: $u, password: $p}')"; }

# ---------------------------------------------------------------------------------------------------------
abschnitt "CLI: Benutzer sperren und entsperren"
pruefe "Hilfe nennt die neuen Befehle" "$(cli)" \
  '.code == 1 and (.out | test("lock-user") and test("unlock-user") and test("delete-user"))'
cli create-user carla Carla-Passwort-1 >/dev/null
pruefe "carla meldet sich an" "$(anmelden cli carla Carla-Passwort-1)" '.success == true'
pruefe "lock-user carla" "$(cli lock-user carla)" '.code == 0 and (.out | test("carla gesperrt"))'
pruefe "gesperrt: laufende Sitzung endet → 401" "$(status cli "${A}destinations")" '. == 401'
pruefe "gesperrt: Anmeldung abgewiesen" "$(anmelden cli2 carla Carla-Passwort-1)" '.success != true'
pruefe "list-users zeigt carla gesperrt" "$(cli list-users)" '.out | test("carla\tUSER\t[^\n]*\tgesperrt")'
pruefe "lock-user carla erneut: schon gesperrt, kein Fehler" "$(cli lock-user carla)" '.code == 0 and (.out | test("bereits gesperrt"))'
pruefe "unlock-user carla" "$(cli unlock-user carla)" '.code == 0 and (.out | test("carla entsperrt"))'
pruefe "entsperrt: Anmeldung klappt wieder" "$(anmelden cli carla Carla-Passwort-1)" '.success == true'
pruefe "unlock-user carla erneut: nicht gesperrt, kein Fehler" "$(cli unlock-user carla)" '.code == 0 and (.out | test("nicht gesperrt"))'
for befehl in lock-user unlock-user delete-user; do
  pruefe "$befehl ohne Benutzername → Fehler" "$(cli "$befehl")" '.code == 1 and (.err | length > 0)'
  pruefe "$befehl unbekannter Benutzer → Fehler" "$(cli "$befehl" niemand)" '.code == 1 and (.err | test("nicht gefunden"))'
done
GESPERRT="$(cli list-users | jq '[.out | scan("\tgesperrt")] | length')"
pruefe "lock-user mit SQL-Fragment → nicht gefunden" "$(cli lock-user "carla' OR '1'='1")" '.code == 1 and (.err | test("nicht gefunden"))'
pruefe "dabei niemand zusätzlich gesperrt" "$(cli list-users)" "[.out | scan(\"\\tgesperrt\")] | length == $GESPERRT"

# ---------------------------------------------------------------------------------------------------------
abschnitt "CLI: Benutzer löschen mit Rückfrage"
DC="$(als cli -X POST "${A}destinations" -d '{"name":"Carlas Ziel"}' | jq -r '.data.id')"
als cli -X POST "${A}destinations" -d '{"name":"Carlas zweites Ziel"}' >/dev/null
DATEI_C="$(hochladen cli "$DC" "$TMP/bild.png" | jq -r '.data.id')"
pruefe "Bild von carla liegt auf dem Server" "$(find "$TMP/app/data/uploads" -name "$DATEI_C.*" | wc -l)" '. == 1'
pruefe "delete-user ohne Eingabe → Abbruch" "$(cli delete-user carla)" '.code == 1 and (.out | test("2 Ziele")) and (.out | test("Abgebrochen"))'
pruefe "delete-user mit „nein“ → Abbruch" "$(cli --eingabe $'nein\n' delete-user carla)" '.code == 1 and (.out | test("Abgebrochen"))'
pruefe "delete-user mit „jaa“ → Abbruch" "$(cli --eingabe $'jaa\n' delete-user carla)" '.code == 1'
pruefe "nach Abbruch: carla und ihre Ziele noch da" "$(als cli "${A}destinations")" '.success == true and (.data | length == 2)'
pruefe "delete-user mit „ja“ → gelöscht" "$(cli --eingabe $'ja\n' delete-user carla)" '.code == 0 and (.out | test("carla gelöscht"))'
pruefe "ihre Sitzung endet → 401" "$(status cli "${A}destinations")" '. == 401'
pruefe "ihre Bilder sind vom Server entfernt" "$(find "$TMP/app/data/uploads" -name "$DATEI_C.*" | wc -l)" '. == 0'
pruefe "list-users ohne carla" "$(cli list-users)" '.out | test("carla") | not'

# ---------------------------------------------------------------------------------------------------------
abschnitt "CLI: letzter aktiver Admin bleibt"
export AUSFLUGSZIELE_DATA_DIR="$TMP/cli-daten"
mkdir -p "$AUSFLUGSZIELE_DATA_DIR"
cli create-user chefin Chefin-Passwort-1 ADMIN >/dev/null
cli create-user nutzer Nutzer-Passwort-1 >/dev/null
pruefe "einziger Admin: sperren abgewiesen" "$(cli lock-user chefin)" '.code == 1 and (.err | test("letzte"))'
pruefe "einziger Admin: löschen abgewiesen (vor der Rückfrage)" "$(cli --eingabe $'ja\n' delete-user chefin)" '.code == 1 and (.err | test("letzte")) and (.out | test("löschen\\?") | not)'
pruefe "normaler Benutzer daneben: sperren erlaubt" "$(cli lock-user nutzer)" '.code == 0'
cli create-user chef2 Chef2-Passwort-1 ADMIN >/dev/null
pruefe "zweiter Admin da: ersten sperren erlaubt" "$(cli lock-user chefin)" '.code == 0'
pruefe "gesperrter Admin zählt nicht: zweiten sperren abgewiesen" "$(cli lock-user chef2)" '.code == 1 and (.err | test("letzte"))'
pruefe "gesperrter Admin zählt nicht: zweiten löschen abgewiesen" "$(cli --eingabe $'ja\n' delete-user chef2)" '.code == 1'
pruefe "gesperrten Admin löschen erlaubt" "$(cli --eingabe $'ja\n' delete-user chefin)" '.code == 0'
pruefe "chef2 aktiv, chefin gelöscht" "$(cli list-users)" '(.out | test("chef2\tADMIN\t[^\t\n]*\n")) and (.out | test("chefin") | not)'
unset AUSFLUGSZIELE_DATA_DIR

# ---------------------------------------------------------------------------------------------------------
abschnitt "CLI: Zwei-Faktor-Anmeldung abschalten"
cli create-user dora Dora-Passwort-1 >/dev/null
anmelden cli dora Dora-Passwort-1 >/dev/null
GEHEIM_D="$(als cli -X POST "${A}totp/setup" -d '{}' | jq -r '.data.secret')"
als cli -X POST "${A}totp" -d "$(json --arg c "$(totp "$GEHEIM_D")" '{code: $c}')" >/dev/null
MIT_2FA=dora
pruefe "dora hat 2FA" "$(cli list-users)" '.out | test("dora\tUSER\t[^\n]*\t2FA")'
pruefe "disable-totp $MIT_2FA" "$(cli disable-totp "$MIT_2FA")" '.code == 0 and (.out | test("für .+ abgeschaltet"))'
pruefe "danach ohne 2FA" "$(cli list-users | jq --arg n "$MIT_2FA" '.out | split("\n") | map(select(startswith($n + "\t") and test("\t2FA"))) | length')" '. == 0'
pruefe "disable-totp unbekannter Benutzer → Fehler" "$(cli disable-totp niemand)" '.code == 1 and (.err | test("nicht gefunden"))'
pruefe "disable-totp ohne Benutzername → Fehler" "$(cli disable-totp)" '.code == 1 and (.err | test("fehlt"))'
