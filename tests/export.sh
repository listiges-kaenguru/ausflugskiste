# Tests des Exports (GET destinations/export). Wird von tests/run.sh nach tests/sicherheit.sh eingebunden
# (Server, Helfer und Benutzer wie anna stehen dort bereit), nicht allein ausführen.

# export_von <akteur> → speichert Kopfzeilen und Body unter $TMP/export_<akteur>.{kopf,json}, gibt den Status aus
export_von() {
  curl -s --interface "${IP[$1]}" -b "$TMP/k_$1" -c "$TMP/k_$1" -D "$TMP/export_$1.kopf" -o "$TMP/export_$1.json" \
    -w '%{http_code}' "${A}destinations/export"
  cat "$TMP/export_$1.json" >> "$LOG"; echo >> "$LOG"
}
kopf() { tr -d '\r' < "$TMP/export_$1.kopf" | grep -i "^$2:" | cut -d' ' -f2-; }

# ---------------------------------------------------------------------------------------------------------
abschnitt "Export der eigenen Ziele"
pruefe "Anna: Export → 200" "$(export_von anna)" '. == 200'
pruefe "als JSON ausgeliefert" "$(json --arg t "$(kopf anna content-type)" '$t')" 'startswith("application/json")'
DATEINAME="$(kopf anna content-disposition)"
EXPORTIERT="$(jq -r '.exportedAt[0:10]' "$TMP/export_anna.json")"
pruefe "als Datei zum Herunterladen, Name mit Benutzer und Datum des Exports" "$(json --arg d "$DATEINAME" --arg t "$EXPORTIERT" '[$d, $t]')" \
  '.[0] == "attachment; filename=\"ausflugskiste-anna-\(.[1]).json\""'
pruefe "Sicherheits-Header: nosniff, no-store" "$(json --arg a "$(kopf anna x-content-type-options)" --arg b "$(kopf anna cache-control)" '[$a, $b]')" \
  '.[0] == "nosniff" and (.[1] | startswith("no-store"))'
EXPORT="$(cat "$TMP/export_anna.json")"
pruefe "Kopfdaten: Format, Version, Benutzer, Zeitpunkt" "$EXPORT" \
  '.format == "ausflugskiste-export" and .formatVersion == 1 and .username == "anna" and (.appVersion | type == "string") and (.exportedAt | test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T"))'
pruefe "genau Annas nicht gelöschte Ziele" "$(json --argjson e "$EXPORT" --argjson l "$(als anna "${A}destinations")" '[($e.destinations | map(.id) | sort), ($l.data | map(.id) | sort)]')" \
  '.[0] == .[1] and (.[0] | length > 0)'
pruefe "gelöschtes Ziel nicht enthalten" "$EXPORT" '[.destinations[] | select(.name == "Patch-Prüfung")] | length == 0'
pruefe "mit privaten Notizen" "$EXPORT" '[.destinations[] | select(.name == "Annas Ziel")][0].privateNotes == "geheim-anna"'
pruefe "Fotos nur als Angaben, ohne Bilddaten und ohne URL" "$EXPORT" \
  '[.destinations[] | select(.name == "Annas Ziel")][0].images | length >= 1 and all(has("mimeType") and has("filesize") and (has("url") | not) and (has("data") | not))'
pruefe "alle Felder je Ziel" "$EXPORT" \
  '.destinations | all(has("name") and has("address") and has("description") and has("rating") and has("favorite") and has("visited") and has("privateNotes") and has("latitude") and has("longitude") and has("googleMapsLink") and has("createdAt") and has("updatedAt") and (.tags | type == "array"))'
pruefe "keine internen Felder (createdBy, deletedAt)" "$EXPORT" '.destinations | all((has("createdBy") | not) and (has("deletedAt") | not))'

# Tags als Namen: eigenes Ziel mit Tag anlegen
TAG_ID="$(als admin -X POST "${A}tags" -d '{"name":"Export-Tag"}' | jq -r '.data.id')"
als anna -X POST "${A}destinations" -d "$(json --arg t "$TAG_ID" '{name: "Mit Tag", tagIds: [$t]}')" >/dev/null
export_von anna >/dev/null
pruefe "Tags als Namen" "$(cat "$TMP/export_anna.json")" '[.destinations[] | select(.name == "Mit Tag")][0].tags == ["Export-Tag"]'

abschnitt "Export: fremde Daten, leere Liste, Fehlerfälle"
als admin -X POST "${A}users" -d '{"username":"xaver","password":"Start-Xaver-1"}' >/dev/null
als xaver -X POST "${A}login" -d '{"username":"xaver","password":"Start-Xaver-1"}' >/dev/null
als xaver -X POST "${A}change-password" -d '{"newPassword":"Xaver-Passwort-1"}' >/dev/null
pruefe "neuer Benutzer: Export → 200" "$(export_von xaver)" '. == 200'
pruefe "neuer Benutzer: leere Liste, nichts von Anna" "$(cat "$TMP/export_xaver.json")" '.username == "xaver" and .destinations == []'
pruefe "Admin: Export → 200" "$(export_von admin)" '. == 200'
pruefe "Admin: kein Ziel von Anna im Export" "$(json --argjson e "$(cat "$TMP/export_admin.json")" --argjson a "$(cat "$TMP/export_anna.json")" \
  '[$e.destinations[].id] - [$a.destinations[].id] == [$e.destinations[].id]')" '. == true'
pruefe "Admin: keine Notiz von Anna im Export" "$(grep -c 'geheim-anna' "$TMP/export_admin.json")" '. == 0'
pruefe "ohne Login → 401" "$(export_von gast)" '. == 401'
pruefe "ohne Login: keine Daten" "$(cat "$TMP/export_gast.json")" '.success == false and .error.code == "UNAUTHORIZED"'
pruefe "manipulierte Sitzung → 401" "$(curl -s -o /dev/null -w '%{http_code}' -b 'session=manipuliert123' "${A}destinations/export")" '. == 401'
pruefe "Passwortwechsel ausstehend → kein Export" "$(als admin -X POST "${A}users" -d '{"username":"yvonne","password":"Start-Yvonne-1"}' >/dev/null; \
  als yvonne -X POST "${A}login" -d '{"username":"yvonne","password":"Start-Yvonne-1"}' >/dev/null; export_von yvonne)" '. == 403'
for m in POST PUT PATCH DELETE; do
  pruefe "$m destinations/export → kein Export" "$(status anna -X "$m" "${A}destinations/export" -d '{}')" '. == 404 or . == 400'
done
pruefe "danach alle Ziele von Anna noch da" "$(json --argjson e "$(cat "$TMP/export_anna.json")" --argjson l "$(als anna "${A}destinations")" '[($e.destinations | length), ($l.data | length)]')" '.[0] == .[1]'

abschnitt "Export: Dateiname bei Sonderzeichen im Benutzernamen"
BOESE='a"b; filename=x.html'$'\r\n''X-Evil: 1 Jörg/../'
als admin -X POST "${A}users" -d "$(json --arg u "$BOESE" '{username: $u, password: "Start-Boese-1"}')" >/dev/null
als zoe -X POST "${A}login" -d "$(json --arg u "$BOESE" '{username: $u, password: "Start-Boese-1"}')" >/dev/null
als zoe -X POST "${A}change-password" -d '{"newPassword":"Boese-Passwort-1"}' >/dev/null
pruefe "Sonderzeichen: Export → 200" "$(export_von zoe)" '. == 200'
pruefe "Sonderzeichen: Dateiname nur aus sicheren Zeichen" "$(json --arg d "$(kopf zoe content-disposition)" '$d')" \
  'test("^attachment; filename=\"ausflugskiste-[A-Za-z0-9_-]+-[0-9]{4}-[0-9]{2}-[0-9]{2}\\.json\"$")'
pruefe "Sonderzeichen: kein eingeschleuster Header" "$(grep -ic '^x-evil' "$TMP/export_zoe.kopf")" '. == 0'
pruefe "Sonderzeichen: Benutzername im Inhalt unverändert" "$(jq --arg u "$BOESE" '.username == $u' "$TMP/export_zoe.json")" '. == true'
