# Sicherheits- und Negativtests der Ausflugskiste. Wird von tests/run.sh eingebunden (Server, Helfer und
# Einrichtung als admin stehen dort bereit), nicht allein ausführen.
# Abschnitte: ohne Anmeldung, fremde Daten, Admin-Rechte, Sitzungen, CSRF, Login-Sperre, Injection,
# Eingabevalidierung, Fehlermeldungen.

# Kleinstes gültiges PNG (1×1); png <datei> [bytes] füllt mit Nullen auf eine feste Größe auf
PNG_B64='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
png() {
  printf '%s' "$PNG_B64" | base64 -d > "$1"
  [ -n "${2:-}" ] && truncate -s "$2" "$1"
}
# hochladen <akteur> <ziel-id> <datei> [dateiname] → Antwort (Multipart, ohne JSON-Header)
hochladen() {
  local r; r="$(curl -s --interface "${IP[$1]}" -b "$TMP/k_$1" -c "$TMP/k_$1" -H 'X-Requested-With: fetch' \
    -F "destinationId=$2" -F "file=@$3;type=image/png;filename=${4:-bild.png}" "$U/api.php?r=images")"
  printf '%s\n' "$r" >> "$LOG"; printf '%s' "$r"
}
# ohne_header <akteur> <curl-argumente …> → HTTP-Status einer Anfrage ohne den Pflicht-Header X-Requested-With
ohne_header() {
  local wer=$1; shift
  local r; r="$(curl -s --interface "${IP[$wer]}" -b "$TMP/k_$wer" -c "$TMP/k_$wer" -w '\n%{http_code}' "$@")"
  printf '%s\n' "$r" >> "$LOG"; printf '%s' "${r##*$'\n'}"
}
json() { jq -cn "$@"; }   # JSON sicher bauen: json --arg n "$wert" '{name: $n}'
A="$U/api.php?r="

# ---------------------------------------------------------------------------------------------------------
abschnitt "Ohne Anmeldung: geschützte Routen → 401"
for r in 'GET destinations' 'POST destinations' 'GET destinations/x' 'PUT destinations/x' 'DELETE destinations/x' \
  'GET tags' 'POST tags' 'GET users' 'POST users' 'GET version' 'GET passkeys' 'GET totp' 'POST change-password' \
  'GET images/x/file' 'DELETE images/x'; do
  set -- $r
  pruefe "ohne Login: $1 $2 → 401" "$(status gast -X "$1" "$A$2" $([ "$1" != GET ] && echo -d '{}'))" '. == 401'
done
# Bilder kommen als Multipart (wie aus der App); ein JSON-Body ist kein gültiger Upload und muss abgewiesen werden
png "$TMP/gast.png"
pruefe "ohne Login: Bild hochladen (Multipart) → 401" "$(hochladen gast x "$TMP/gast.png")" '.error.code == "UNAUTHORIZED"'
pruefe "ohne Login: Bild-Upload mit JSON-Body abgewiesen" "$(status gast -X POST "${A}images" -d '{}')" '. >= 400 and . < 500'
pruefe "dabei keine Datei angelegt" "$(find "$TMP/app/data/uploads" -type f ! -name '.htaccess' | wc -l)" '. == 0'
pruefe "ohne Login: me liefert keinen Benutzer" "$(als gast "${A}me")" '.success == true and .data.user == null'

# ---------------------------------------------------------------------------------------------------------
abschnitt "Benutzer anlegen, Pflicht zum Passwortwechsel"
als admin -X POST "${A}login" -d '{"username":"admin","password":"Test-Passwort-123"}' >/dev/null
ANNA_ID="$(als admin -X POST "${A}users" -d '{"username":"anna","password":"Start-Anna-1"}' | jq -r '.data.id')"
BEN_ID="$(als admin -X POST "${A}users" -d '{"username":"ben","password":"Start-Ben-1"}' | jq -r '.data.id')"
ADMIN_ID="$(als admin "${A}me" | jq -r '.data.user.id')"
pruefe "Admin legt Benutzer an" "$(json --arg a "$ANNA_ID" --arg b "$BEN_ID" '[$a, $b]')" 'all(length == 36)'
als anna -X POST "${A}login" -d '{"username":"anna","password":"Start-Anna-1"}' >/dev/null
pruefe "neuer Benutzer: vor dem Passwortwechsel gesperrt" "$(als anna "${A}destinations")" '.error.code == "PASSWORD_CHANGE_REQUIRED"'
pruefe "neuer Benutzer: Passwortwechsel" "$(als anna -X POST "${A}change-password" -d '{"newPassword":"Anna-Passwort-1"}')" '.success == true'
pruefe "neuer Benutzer: danach Zugriff" "$(status anna "${A}destinations")" '. == 200'
ANNA_PW='Anna-Passwort-1'
als ben -X POST "${A}login" -d '{"username":"ben","password":"Start-Ben-1"}' >/dev/null
als ben -X POST "${A}change-password" -d '{"newPassword":"Ben-Passwort-1"}' >/dev/null

# ---------------------------------------------------------------------------------------------------------
abschnitt "Fremde Daten: Ben kommt nicht an Annas Ziele und Bilder"
DA="$(als anna -X POST "${A}destinations" -d '{"name":"Annas Ziel","privateNotes":"geheim-anna"}' | jq -r '.data.id')"
png "$TMP/bild.png"
IA="$(hochladen anna "$DA" "$TMP/bild.png" | jq -r '.data.id')"
pruefe "Anna: Ziel und Bild angelegt" "$(json --arg d "$DA" --arg i "$IA" '[$d, $i]')" 'all(length == 36)'
pruefe "Ben: fremdes Ziel lesen → 404" "$(status ben "${A}destinations/$DA")" '. == 404'
pruefe "Ben: fremdes Ziel ändern → 404" "$(status ben -X PUT "${A}destinations/$DA" -d '{"name":"übernommen"}')" '. == 404'
pruefe "Ben: fremdes Ziel löschen → 404" "$(status ben -X DELETE "${A}destinations/$DA")" '. == 404'
pruefe "Ben: fremdes Bild abrufen → 404" "$(status ben "${A}images/$IA/file")" '. == 404'
pruefe "Ben: fremdes Bild löschen → 404" "$(status ben -X DELETE "${A}images/$IA")" '. == 404'
pruefe "Ben: Bild an fremdes Ziel hängen → 404" "$(hochladen ben "$DA" "$TMP/bild.png")" '.error.code == "NOT_FOUND"'
pruefe "Ben: eigene Liste ohne Annas Ziel" "$(als ben "${A}destinations")" '.success == true and (.data | length == 0)'
pruefe "Ben: Suche findet Annas Notiz nicht" "$(als ben "${A}destinations&search=geheim-anna")" '.data | length == 0'
pruefe "Anna: Ziel unverändert, Bild noch da" "$(als anna "${A}destinations/$DA")" \
  '.data.name == "Annas Ziel" and .data.privateNotes == "geheim-anna" and (.data.images | length == 1)'
pruefe "Anna: eigenes Bild abrufbar" "$(status anna "${A}images/$IA/file")" '. == 200'
pruefe "Admin: sieht Annas Ziel nicht" "$(status admin "${A}destinations/$DA")" '. == 404'

# ---------------------------------------------------------------------------------------------------------
abschnitt "Admin-Rechte: normale Benutzer → 403"
TAG="$(als anna -X POST "${A}tags" -d '{"name":"Wald"}' | jq -r '.data.id')"
for r in "GET users" "POST users" "PUT users/$BEN_ID" "DELETE users/$BEN_ID" "POST users/$BEN_ID/password" \
  "GET users/$BEN_ID/passkeys" "DELETE users/$BEN_ID/totp" "PUT tags/$TAG" "DELETE tags/$TAG"; do
  set -- $r
  pruefe "Anna: $1 ${2%%/*} → 403" "$(als anna -X "$1" "$A$2" $([ "$1" != GET ] && echo -d '{"name":"x","username":"xyz","password":"Passwort-1","disabled":true}'))" \
    '.error.code == "FORBIDDEN"'
done
pruefe "Tag nach den Versuchen unverändert" "$(als anna "${A}tags" | jq --arg t "$TAG" '[.data[] | select(.id == $t and .name == "Wald")] | length')" '. == 1'
pruefe "Admin: eigenes Konto nicht sperrbar" "$(als admin -X PUT "${A}users/$ADMIN_ID" -d '{"disabled":true}')" '.error.code == "VALIDATION_ERROR"'

# ---------------------------------------------------------------------------------------------------------
abschnitt "Sitzungen"
pruefe "manipuliertes Sitzungscookie → 401" \
  "$(curl -s -o /dev/null -w '%{http_code}' -H 'X-Requested-With: fetch' -b 'session=manipuliert123' "${A}destinations")" '. == 401'
KOPF="$(curl -s -D - -o /dev/null --interface "${IP[fix]}" -H 'X-Requested-With: fetch' -H 'Content-Type: application/json' \
  -b 'session=fixiert1234567890' -X POST "${A}login" -d "$(json --arg p "$ANNA_PW" '{username: "anna", password: $p}')")"
pruefe "Session-Fixation: Anmeldung vergibt neue Sitzungs-ID" \
  "$(printf '%s' "$KOPF" | grep -i '^set-cookie: session=' | grep -vc 'fixiert1234567890')" '. >= 1'
pruefe "Cookie mit HttpOnly und SameSite" "$(printf '%s' "$KOPF" | grep -i '^set-cookie: session=' | grep -ic 'httponly.*samesite=lax\|samesite=lax.*httponly')" '. >= 1'
KOPF="$(curl -s -D - -o /dev/null -b "$TMP/k_anna" -H 'X-Requested-With: fetch' "${A}destinations")"
for h in 'x-content-type-options: nosniff' 'x-frame-options: deny' 'cache-control: no-store' "content-security-policy: default-src 'none'"; do
  pruefe "Sicherheits-Header: $h" "$(printf '%s' "$KOPF" | tr -d '\r' | grep -ic "^$h")" '. >= 1'
done

als anna2 -X POST "${A}login" -d "$(json --arg p "$ANNA_PW" '{username: "anna", password: $p}')" >/dev/null
cp "$TMP/k_anna2" "$TMP/k_alt"
als anna2 -X POST "${A}logout" >/dev/null
pruefe "nach dem Abmelden: altes Cookie → 401" \
  "$(curl -s -o /dev/null -w '%{http_code}' -H 'X-Requested-With: fetch' -b "$TMP/k_alt" "${A}destinations")" '. == 401'

als anna2 -X POST "${A}login" -d "$(json --arg p "$ANNA_PW" '{username: "anna", password: $p}')" >/dev/null
pruefe "zweite Sitzung von Anna aktiv" "$(status anna2 "${A}destinations")" '. == 200'
als anna -X POST "${A}change-password" -d "$(json --arg p "$ANNA_PW" '{currentPassword: $p, newPassword: "Anna-Passwort-2"}')" >/dev/null
ANNA_PW='Anna-Passwort-2'
pruefe "Passwortwechsel: eigene Sitzung bleibt" "$(status anna "${A}destinations")" '. == 200'
pruefe "Passwortwechsel: andere Sitzung endet → 401" "$(status anna2 "${A}destinations")" '. == 401'

als admin -X PUT "${A}users/$BEN_ID" -d '{"disabled":true}' >/dev/null
pruefe "gesperrt: laufende Sitzung endet → 401" "$(status ben "${A}destinations")" '. == 401'
pruefe "gesperrt: Anmeldung abgewiesen" "$(als ben -X POST "${A}login" -d '{"username":"ben","password":"Ben-Passwort-1"}')" \
  '.error.code == "ACCOUNT_DISABLED"'

# ---------------------------------------------------------------------------------------------------------
abschnitt "CSRF: Schreibanfragen ohne Pflicht-Header → 403"
pruefe "POST ohne X-Requested-With → 403" \
  "$(ohne_header anna -H 'Content-Type: application/json' -X POST "${A}destinations" -d '{"name":"csrf"}')" '. == 403'
pruefe "POST mit falschem X-Requested-With → 403" \
  "$(ohne_header anna -H 'X-Requested-With: XMLHttpRequest' -H 'Content-Type: application/json' -X POST "${A}destinations" -d '{"name":"csrf"}')" '. == 403'
pruefe "Formular-POST (einfache Cross-Site-Anfrage) → 403" "$(ohne_header anna -X POST "${A}destinations" -d 'name=csrf')" '. == 403'
pruefe "DELETE ohne Header → 403" "$(ohne_header anna -X DELETE "${A}destinations/$DA")" '. == 403'
pruefe "nichts angelegt oder gelöscht" "$(als anna "${A}destinations")" '[.data[] | select(.name == "csrf")] | length == 0'
pruefe "Annas Ziel noch da" "$(status anna "${A}destinations/$DA")" '. == 200'

# ---------------------------------------------------------------------------------------------------------
abschnitt "Login-Sperre und Benutzer-Aufzählung"
for i in 1 2 3 4 5; do
  pruefe "Fehlversuch $i → 401" "$(status sperre -X POST "${A}login" -d '{"username":"anna","password":"falsch-falsch"}')" '. == 401'
done
pruefe "6. Versuch, auch mit richtigem Passwort → 429" \
  "$(status sperre -X POST "${A}login" -d "$(json --arg p "$ANNA_PW" '{username: "anna", password: $p}')")" '. == 429'
pruefe "andere Adresse ist nicht gesperrt" \
  "$(status sperre2 -X POST "${A}login" -d "$(json --arg p "$ANNA_PW" '{username: "anna", password: $p}')")" '. == 200'
UNBEKANNT="$(als sperre2 -X POST "${A}login" -d '{"username":"gibt-es-nicht","password":"falsch-falsch"}')"
FALSCH="$(als sperre2 -X POST "${A}login" -d '{"username":"anna","password":"falsch-falsch"}')"
pruefe "gleiche Antwort für unbekannten Namen und falsches Passwort" \
  "$(json --argjson a "$UNBEKANNT" --argjson b "$FALSCH" '[$a, $b]')" '.[0] == .[1] and .[0].error.code == "INVALID_CREDENTIALS"'

# ---------------------------------------------------------------------------------------------------------
abschnitt "Injection"
SQL="x'); DROP TABLE \"Destination\"; --"
pruefe "SQL im Namen wird wörtlich gespeichert" \
  "$(als anna -X POST "${A}destinations" -d "$(json --arg n "$SQL" '{name: $n}')" | jq --arg n "$SQL" '.data.name == $n')" '. == true'
pruefe "Tabelle danach intakt" "$(als anna "${A}destinations")" '.success == true and (.data | length >= 2)'
pruefe "Suche mit SQL-Fragment: keine fremden Daten" "$(als anna "${A}destinations&search=%27%20OR%201%3D1%20--")" '.success == true and (.data | length == 0)'
pruefe "Mindestbewertung mit SQL-Fragment → 400" "$(status anna "${A}destinations&minRating=1%20OR%201%3D1")" '. == 400'
pruefe "Favorit-Filter mit SQL-Fragment wird ignoriert" "$(als anna "${A}destinations&favorite=true%27%20OR%20%271%27%3D%271")" '.success == true'
pruefe "Tag-Filter mit SQL-Fragment" "$(als anna "${A}destinations&tag%5B%5D=%27%20OR%201%3D1--")" '.success == true and (.data | length == 0)'
pruefe "ID mit SQL-Fragment → 404" "$(status anna "${A}destinations/%27%20OR%20%271%27%3D%271")" '. == 404'
pruefe "Bild-ID mit Pfad → 404" "$(status anna "${A}images/..%2F..%2Fdata%2Fapp.db/file")" '. == 404'
pruefe "Bild-ID mit ../ → 404" "$(status anna "${A}images/../../api.php/file")" '. == 404'
pruefe "Upload mit Dateiname ../../evil.php: gespeichert als PNG" "$(hochladen anna "$DA" "$TMP/bild.png" '../../evil.php')" \
  '.success == true and .data.mimeType == "image/png"'
pruefe "keine Datei evil.php angelegt" "$(find "$TMP" -name 'evil.php' | wc -l)" '. == 0'
pruefe "im Upload-Ordner nur Bilddateien" "$(find "$TMP/app/data/uploads" -type f ! -name '*.png' ! -name '.htaccess' | wc -l)" '. == 0'
printf '<?php echo "boese"; ?>' > "$TMP/boese.png"
pruefe "PHP-Datei als Bild getarnt → 400" "$(hochladen anna "$DA" "$TMP/boese.png")" '.error.code == "VALIDATION_ERROR"'
HTML='<script>alert(1)</script><img src=x onerror=alert(2)>'
pruefe "HTML/Skript kommt unverändert als Daten zurück" \
  "$(als anna -X POST "${A}destinations" -d "$(json --arg h "$HTML" '{name: $h, description: $h}')" | jq --arg h "$HTML" '.data.name == $h and .data.description == $h')" '. == true'
for l in 'javascript:alert(1)' 'JAVASCRIPT:alert(1)' 'data:text/html,<script>alert(1)</script>' ' javascript:alert(1)'; do
  pruefe "Link »${l:0:20}« → 400" "$(status anna -X POST "${A}destinations" -d "$(json --arg l "$l" '{name: "Link", googleMapsLink: $l}')")" '. == 400'
done

# ---------------------------------------------------------------------------------------------------------
abschnitt "Eingabevalidierung"
ziel() { status anna -X POST "${A}destinations" -d "$1"; }
lang() { printf "%${2}s" '' | sed "s/ /$1/g"; }
pruefe "Name leer → 400" "$(ziel '{"name":""}')" '. == 400'
pruefe "Name nur Leerzeichen → 400" "$(ziel '{"name":"   "}')" '. == 400'
pruefe "Name fehlt → 400" "$(ziel '{}')" '. == 400'
pruefe "Name als Zahl → 400" "$(ziel '{"name":123}')" '. == 400'
pruefe "Name als Liste → 400" "$(ziel '{"name":["a"]}')" '. == 400'
pruefe "Name 200 Zeichen (Umlaute) → 201" "$(ziel "$(json --arg n "$(lang ä 200)" '{name: $n}')")" '. == 201'
pruefe "Name 201 Zeichen → 400" "$(ziel "$(json --arg n "$(lang ä 201)" '{name: $n}')")" '. == 400'
pruefe "Name 200 Emoji → 201" "$(ziel "$(json --arg n "$(lang 😀 200)" '{name: $n}')")" '. == 201'
pruefe "Beschreibung 5000 Zeichen → 201" "$(ziel "$(json --arg d "$(lang x 5000)" '{name: "B", description: $d}')")" '. == 201'
pruefe "Beschreibung 5001 Zeichen → 400" "$(ziel "$(json --arg d "$(lang x 5001)" '{name: "B", description: $d}')")" '. == 400'
printf '{"name":"B","description":"%s"}' "$(lang x 1048576)" > "$TMP/gross.json"
pruefe "Beschreibung 1 MB → 400 (kein Serverfehler)" "$(ziel @"$TMP/gross.json")" '. == 400'
for b in 1 5 '"3"'; do pruefe "Bewertung $b → 201" "$(ziel "{\"name\":\"R\",\"rating\":$b}")" '. == 201'; done
for b in 0 6 -1 3.5 '"abc"' '[3]'; do pruefe "Bewertung $b → 400" "$(ziel "{\"name\":\"R\",\"rating\":$b}")" '. == 400'; done
pruefe "Tags kein Array → 400" "$(ziel '{"name":"T","tagIds":"abc"}')" '. == 400'
pruefe "Tags mit Zahlen → 400" "$(ziel '{"name":"T","tagIds":[1,2]}')" '. == 400'
pruefe "unbekannte Tag-ID wird ignoriert" "$(als anna -X POST "${A}destinations" -d '{"name":"T","tagIds":["gibt-es-nicht"]}')" '.data.tags == []'
pruefe "kaputtes JSON → 400" "$(ziel '{"name":')" '. == 400'
pruefe "leerer Body → 400" "$(ziel '')" '. == 400'
pruefe "JSON-Text statt Objekt → 400" "$(ziel '"x"')" '. == 400'
UNI='Café 😀 שלום مرحبا ‮abc 你好'
pruefe "Unicode wird unverändert gespeichert" \
  "$(als anna -X POST "${A}destinations" -d "$(json --arg n "$UNI" '{name: $n}')" | jq --arg n "$UNI" '.data.name == $n')" '. == true'
NUL="$(als anna -X POST "${A}destinations" -d '{"name":"a\u0000b"}')"
pruefe "Nullbyte: korrekt gespeichert oder sauber abgewiesen" "$NUL" '(.success == true and .data.name == "a\u0000b") or .error.code == "VALIDATION_ERROR"'

benutzer() { status admin -X POST "${A}users" -d "$(json --arg u "$1" --arg p "$2" '{username: $u, password: $p}')"; }
pruefe "Benutzername 2 Zeichen → 400" "$(benutzer ab Passwort-1)" '. == 400'
pruefe "Benutzername 3 Zeichen → 201" "$(benutzer abc Passwort-1)" '. == 201'
pruefe "Benutzername 64 Zeichen → 201" "$(benutzer "$(lang u 64)" Passwort-1)" '. == 201'
pruefe "Benutzername 65 Zeichen → 400" "$(benutzer "$(lang v 65)" Passwort-1)" '. == 400'
pruefe "Benutzername mit Umlaut → 201" "$(benutzer 'Jörg' Passwort-1)" '. == 201'
pruefe "Benutzername doppelt → 409" "$(benutzer 'Jörg' Passwort-1)" '. == 409'
pruefe "Passwort 5 Bytes → 400" "$(benutzer kurz1 abcde)" '. == 400'
pruefe "Passwort 6 Bytes → 201" "$(benutzer kurz2 abcdef)" '. == 201'
pruefe "Passwort 72 Bytes → 201" "$(benutzer lang1 "$(lang p 72)")" '. == 201'
pruefe "Passwort 73 Bytes → 400" "$(benutzer lang2 "$(lang p 73)")" '. == 400'

png "$TMP/genau.png" 10485760
png "$TMP/zu-gross.png" 10485761
: > "$TMP/leer.png"
pruefe "Bild genau 10 MB → angenommen" "$(hochladen anna "$DA" "$TMP/genau.png")" '.success == true'
pruefe "Bild 10 MB + 1 Byte → 400" "$(hochladen anna "$DA" "$TMP/zu-gross.png")" '.error.code == "VALIDATION_ERROR"'
pruefe "leere Datei → 400" "$(hochladen anna "$DA" "$TMP/leer.png")" '.error.code == "VALIDATION_ERROR"'
pruefe "Upload ohne Ziel → 404" "$(hochladen anna '' "$TMP/bild.png")" '.error.code == "NOT_FOUND"'

# ---------------------------------------------------------------------------------------------------------
abschnitt "Zwei-Faktor-Anmeldung (TOTP)"
totp() { php "$ROOT/tests/totp-code.php" "$@"; }
mit_code() { json --arg c "$1" '{code: $c}'; }
TINA_ID="$(als admin -X POST "${A}users" -d '{"username":"tina","password":"Start-Tina-1"}' | jq -r '.data.id')"
als tina -X POST "${A}login" -d '{"username":"tina","password":"Start-Tina-1"}' >/dev/null
als tina -X POST "${A}change-password" -d '{"newPassword":"Tina-Passwort-1"}' >/dev/null
TINA='{"username":"tina","password":"Tina-Passwort-1"}'
GEHEIM="$(als tina -X POST "${A}totp/setup" -d '{}' | jq -r '.data.secret')"
pruefe "Einrichtung liefert ein Base32-Geheimnis" "$(json --arg g "$GEHEIM" '$g')" 'test("^[A-Z2-7]{16,}$")'
pruefe "Bestätigung mit falschem Code → 400" "$(als tina -X POST "${A}totp" -d "$(mit_code 000000)")" '.error.code == "INVALID_CODE"'
pruefe "Bestätigung mit Buchstaben → 400" "$(als tina -X POST "${A}totp" -d "$(mit_code abcdef)")" '.error.code == "INVALID_CODE"'
CODES="$(als tina -X POST "${A}totp" -d "$(mit_code "$(totp "$GEHEIM" -1)")")"
pruefe "Bestätigung mit richtigem Code: 10 Wiederherstellungscodes" "$CODES" '.data.recoveryCodes | length == 10'
WC1="$(echo "$CODES" | jq -r '.data.recoveryCodes[0]')"; WC2="$(echo "$CODES" | jq -r '.data.recoveryCodes[1]')"
pruefe "Geheimnis nicht im Klartext in der Datenbank" "$(grep -c "$GEHEIM" "$TMP/app/data/app.db")" '. == 0'
pruefe "Wiederherstellungscode nicht im Klartext in der Datenbank" "$(grep -c "$WC1" "$TMP/app/data/app.db")" '. == 0'
pruefe "zweite Einrichtung → 409" "$(als tina -X POST "${A}totp/setup" -d '{}')" '.error.code == "CONFLICT"'

pruefe "Passwort allein meldet nicht an" "$(als tina2 -X POST "${A}login" -d "$TINA")" '.data.totpRequired == true'
pruefe "vor dem Code kein Zugriff → 401" "$(status tina2 "${A}destinations")" '. == 401'
pruefe "falscher Code → 401" "$(als tina2 -X POST "${A}login/totp" -d "$(mit_code 000000)")" '.error.code == "INVALID_CODE"'
C0="$(totp "$GEHEIM" 0)"
pruefe "richtiger Code meldet an" "$(als tina2 -X POST "${A}login/totp" -d "$(mit_code "$C0")")" '.success == true and .data.user.username == "tina"'
pruefe "danach Zugriff" "$(status tina2 "${A}destinations")" '. == 200'
als tina3 -X POST "${A}login" -d "$TINA" >/dev/null
pruefe "derselbe Code ein zweites Mal → 401 (Replay)" "$(als tina3 -X POST "${A}login/totp" -d "$(mit_code "$C0")")" '.error.code == "INVALID_CODE"'
pruefe "Wiederherstellungscode meldet an, 9 übrig" "$(als tina3 -X POST "${A}login/totp" -d "$(mit_code "$WC1")")" '.success == true and .data.recoveryCodesLeft == 9'
als tina4 -X POST "${A}login" -d "$TINA" >/dev/null
pruefe "Wiederherstellungscode nur einmal gültig → 401" "$(als tina4 -X POST "${A}login/totp" -d "$(mit_code "$WC1")")" '.error.code == "INVALID_CODE"'
pruefe "Code ohne vorherige Passwortprüfung → 401" "$(als gast -X POST "${A}login/totp" -d "$(mit_code "$C0")")" '.error.code == "TOTP_EXPIRED"'
pruefe "Abschalten ohne Code → 400" "$(als tina -X DELETE "${A}totp" -d '{}')" '.error.code == "INVALID_CODE"'
pruefe "Abschalten mit falschem Code → 400" "$(als tina -X DELETE "${A}totp" -d "$(mit_code 000000)")" '.error.code == "INVALID_CODE"'
pruefe "Status: weiter aktiv" "$(als tina "${A}totp")" '.data.enabled == true'

als tina5 -X POST "${A}login" -d "$TINA" >/dev/null
pruefe "neuer Code im nächsten Zeitschritt meldet an" "$(status tina5 -X POST "${A}login/totp" -d "$(mit_code "$(totp "$GEHEIM" 1)")")" '. == 200'
als tina6 -X POST "${A}login" -d "$TINA" >/dev/null
for i in 1 2 3 4 5; do
  pruefe "Durchprobieren: falscher Code $i → 401" "$(status tina6 -X POST "${A}login/totp" -d "$(mit_code "00000$i")")" '. == 401'
done
pruefe "6. Versuch, auch mit gültigem Wiederherstellungscode → 429" "$(status tina6 -X POST "${A}login/totp" -d "$(mit_code "$WC2")")" '. == 429'
pruefe "gesperrter Versuch verbraucht den Code nicht" "$(als tina "${A}totp")" '.data.recoveryCodesLeft == 9'
pruefe "Admin setzt Zwei-Faktor zurück" "$(status admin -X DELETE "${A}users/$TINA_ID/totp")" '. == 200'
pruefe "danach Anmeldung nur mit Passwort" "$(als tina7 -X POST "${A}login" -d "$TINA")" '.data.user.username == "tina"'
# Eigene Nutzerin: Tinas Code-Versuche sind nach dem Durchprobieren noch 5 Minuten gesperrt
als admin -X POST "${A}users" -d '{"username":"rita","password":"Start-Rita-1"}' >/dev/null
als rita -X POST "${A}login" -d '{"username":"rita","password":"Start-Rita-1"}' >/dev/null
als rita -X POST "${A}change-password" -d '{"newPassword":"Rita-Passwort-1"}' >/dev/null
GEHEIM="$(als rita -X POST "${A}totp/setup" -d '{}' | jq -r '.data.secret')"
ALT="$(als rita -X POST "${A}totp" -d "$(mit_code "$(totp "$GEHEIM" 0)")" | jq -r '.data.recoveryCodes[0]')"
NEU="$(als rita -X POST "${A}totp/recovery-codes" -d "$(mit_code "$(totp "$GEHEIM" 1)")")"
pruefe "neue Wiederherstellungscodes mit gültigem Code" "$NEU" '.data.recoveryCodes | length == 10'
pruefe "neue Codes ohne gültigen Code → 400" "$(als rita -X POST "${A}totp/recovery-codes" -d "$(mit_code 000000)")" '.error.code == "INVALID_CODE"'
pruefe "alter Wiederherstellungscode danach ungültig" "$(als rita -X DELETE "${A}totp" -d "$(mit_code "$ALT")")" '.error.code == "INVALID_CODE"'
pruefe "Abschalten mit neuem Wiederherstellungscode" "$(als rita -X DELETE "${A}totp" -d "$(mit_code "$(echo "$NEU" | jq -r '.data.recoveryCodes[0]')")")" '.success == true'
pruefe "Status: abgeschaltet" "$(als rita "${A}totp")" '.data.enabled == false'

# ---------------------------------------------------------------------------------------------------------
abschnitt "Passkeys (WebAuthn)"
pk() { php "$ROOT/tests/passkey-client.php" "$@"; }
RP=127.0.0.1
reg_challenge() { als "$1" -X POST "${A}passkeys/options" -d '{}' | jq -r '.data.challenge'; }
login_challenge() { als "$1" -X POST "${A}passkey-login/options" -d '{}' | jq -r '.data.challenge'; }
PIA_ID="$(als admin -X POST "${A}users" -d '{"username":"pia","password":"Start-Pia-1"}' | jq -r '.data.id')"
als pia -X POST "${A}login" -d '{"username":"pia","password":"Start-Pia-1"}' >/dev/null
als pia -X POST "${A}change-password" -d '{"newPassword":"Pia-Passwort-1"}' >/dev/null
pk neu "$TMP/pia.key"; pk neu "$TMP/fremd.key"; pk neu "$TMP/rsa.key" rsa
pruefe "Registrierung ohne Login → 401" "$(status gast -X POST "${A}passkeys/options" -d '{}')" '. == 401'
CH="$(reg_challenge pia)"
REG="$(als pia -X POST "${A}passkeys" -d "$(pk registrieren "$TMP/pia.key" "$CH" "$U" "$RP")")"
pruefe "Registrierung eines Passkeys" "$REG" '.success == true and .data.name == "Passkey"'
PK_ID="$(echo "$REG" | jq -r '.data.id')"
CH="$(reg_challenge pia)"
pruefe "Registrierung eines RSA-Passkeys (RS256)" "$(als pia -X POST "${A}passkeys" -d "$(pk registrieren "$TMP/rsa.key" "$CH" "$U" "$RP")")" '.success == true'
pruefe "verbrauchte Challenge → 400" "$(als pia -X POST "${A}passkeys" -d "$(pk registrieren "$TMP/fremd.key" "$CH" "$U" "$RP")")" '.error.code == "CHALLENGE_EXPIRED"'
CH="$(reg_challenge pia)"
pruefe "derselbe Passkey zweimal → 409" "$(als pia -X POST "${A}passkeys" -d "$(pk registrieren "$TMP/pia.key" "$CH" "$U" "$RP")")" '.error.code == "CONFLICT"'
for m in ohne-uv fremde-rp falscher-typ; do
  CH="$(reg_challenge pia)"
  pruefe "Registrierung $m → 400" "$(status pia -X POST "${A}passkeys" -d "$(pk registrieren "$TMP/fremd.key" "$CH" "$U" "$RP" "$m")")" '. == 400'
done
CH="$(reg_challenge pia)"
pruefe "Registrierung von fremder Adresse → 400" "$(status pia -X POST "${A}passkeys" -d "$(pk registrieren "$TMP/fremd.key" "$CH" "http://boese.example" "$RP")")" '. == 400'
CH="$(reg_challenge pia)"
CD="$(json --arg c "$CH" --arg o "$U" '{type: "webauthn.create", challenge: $c, origin: $o}' | base64 -w0 | tr '+/' '-_' | tr -d '=')"
pruefe "kaputtes CBOR → 400" "$(status pia -X POST "${A}passkeys" -d "$(json --arg c "$CD" '{clientDataJSON: $c, attestationObject: "q83vASNFZ4mrze8BI0Vn"}')")" '. == 400'
CH="$(reg_challenge pia)"
CD="$(json --arg c "$CH" --arg o "$U" '{type: "webauthn.create", challenge: $c, origin: $o}' | base64 -w0 | tr '+/' '-_' | tr -d '=')"
# 100 000 verschachtelte Arrays (0x81) mit 0 als innerstem Wert – Bytes direkt kodieren, Nullbytes überleben keine Variable
TIEF="$({ printf '\x81%.0s' $(seq 1 100000); printf '\x00'; } | base64 -w0 | tr '+/' '-_' | tr -d '=')"
pruefe "100 000 Ebenen CBOR: kein Absturz, 400" "$(status pia -X POST "${A}passkeys" -d "$(json --arg c "$CD" --arg a "$TIEF" '{clientDataJSON: $c, attestationObject: $a}')")" '. == 400'
CH="$(reg_challenge pia)"
pruefe "sonst gültiger Passkey mit 20 Ebenen CBOR → 400" "$(status pia -X POST "${A}passkeys" -d "$(pk registrieren "$TMP/fremd.key" "$CH" "$U" "$RP" tief)")" '. == 400'
pruefe "kein Base64url → 400" "$(status pia -X POST "${A}passkeys" -d '{"clientDataJSON":"!!!","attestationObject":"!!!"}')" '. == 400'

anmelden() { als "$1" -X POST "${A}passkey-login" -d "$2"; }
CH="$(login_challenge pk)"
KOERPER="$(pk anmelden "$TMP/pia.key" "$CH" "$U" "$RP" 1)"
pruefe "Anmeldung mit Passkey" "$(anmelden pk "$KOERPER")" '.data.user.username == "pia"'
pruefe "danach Zugriff" "$(status pk "${A}destinations")" '. == 200'
pruefe "dieselbe Anmeldung noch einmal (Replay) → 400" "$(anmelden pk "$KOERPER")" '.error.code == "CHALLENGE_EXPIRED"'
CH="$(login_challenge pk)"
pruefe "Zähler nicht gestiegen (kopierter Schlüssel) → 401" "$(anmelden pk "$(pk anmelden "$TMP/pia.key" "$CH" "$U" "$RP" 1)")" '.error.code == "INVALID_CREDENTIALS"'
CH="$(login_challenge pk)"
pruefe "Zähler gestiegen → angemeldet" "$(anmelden pk "$(pk anmelden "$TMP/pia.key" "$CH" "$U" "$RP" 5)")" '.success == true'
CH="$(login_challenge pk)"
pruefe "Anmeldung mit RSA-Passkey" "$(anmelden pk "$(pk anmelden "$TMP/rsa.key" "$CH" "$U" "$RP" 1)")" '.data.user.username == "pia"'
CH="$(login_challenge pk2)"
pruefe "falsche Signatur → 401" "$(anmelden pk2 "$(pk anmelden "$TMP/pia.key" "$CH" "$U" "$RP" 9 falsche-signatur)")" '.error.code == "INVALID_CREDENTIALS"'
pruefe "nach falscher Signatur kein Zugriff" "$(status pk2 "${A}destinations")" '. == 401'
for m in ohne-uv fremde-rp falscher-typ; do
  CH="$(login_challenge pk2)"
  pruefe "Anmeldung $m → 400" "$(status pk2 -X POST "${A}passkey-login" -d "$(pk anmelden "$TMP/pia.key" "$CH" "$U" "$RP" 9 "$m")")" '. == 400'
done
CH="$(login_challenge pk2)"
pruefe "Anmeldung von fremder Adresse → 400" "$(status pk2 -X POST "${A}passkey-login" -d "$(pk anmelden "$TMP/pia.key" "$CH" "http://boese.example" "$RP" 9)")" '. == 400'
CH="$(reg_challenge pia)"
pruefe "Challenge der Registrierung taugt nicht zur Anmeldung → 400" "$(anmelden pia "$(pk anmelden "$TMP/pia.key" "$CH" "$U" "$RP" 9)")" '.error.code == "CHALLENGE_EXPIRED"'
CH="$(login_challenge pk3)"
pruefe "unbekannter Passkey → 401" "$(anmelden pk3 "$(pk anmelden "$TMP/fremd.key" "$CH" "$U" "$RP" 1)")" '.error.code == "UNKNOWN_PASSKEY"'
CH="$(login_challenge pk3)"
pruefe "Benutzerkennung eines anderen → 401" "$(anmelden pk3 "$(pk anmelden "$TMP/pia.key" "$CH" "$U" "$RP" 10 '' "$ANNA_ID")")" '.error.code == "INVALID_CREDENTIALS"'
pruefe "Anna: fremden Passkey umbenennen → 404" "$(status anna -X PUT "${A}passkeys/$PK_ID" -d '{"name":"meins"}')" '. == 404'
pruefe "Anna: fremden Passkey löschen → 404" "$(status anna -X DELETE "${A}passkeys/$PK_ID")" '. == 404'
pruefe "Pias Passkeys unverändert" "$(als pia "${A}passkeys" | jq '[.data[] | select(.name == "Passkey")] | length')" '. == 2'
als admin -X PUT "${A}users/$PIA_ID" -d '{"disabled":true}' >/dev/null
CH="$(login_challenge pk3)"
pruefe "gesperrter Benutzer: Passkey-Anmeldung → 403" "$(anmelden pk3 "$(pk anmelden "$TMP/pia.key" "$CH" "$U" "$RP" 20)")" '.error.code == "ACCOUNT_DISABLED"'
for i in $(seq 1 10); do
  CH="$(login_challenge pk4)"; anmelden pk4 "$(pk anmelden "$TMP/fremd.key" "$CH" "$U" "$RP" 1)" >/dev/null
done
CH="$(login_challenge pk4)"
pruefe "Passkey-Anmeldung: 11. Versuch je Adresse → 429" "$(status pk4 -X POST "${A}passkey-login" -d "$(pk anmelden "$TMP/fremd.key" "$CH" "$U" "$RP" 1)")" '. == 429'

# ---------------------------------------------------------------------------------------------------------
abschnitt "Benutzer löschen"
als admin -X POST "${A}users" -d '{"username":"weg","password":"Start-Weg-1"}' >/dev/null
WEG_ID="$(als admin "${A}users" | jq -r '.data[] | select(.username == "weg") | .id')"
als weg -X POST "${A}login" -d '{"username":"weg","password":"Start-Weg-1"}' >/dev/null
als weg -X POST "${A}change-password" -d '{"newPassword":"Weg-Passwort-1"}' >/dev/null
DW="$(als weg -X POST "${A}destinations" -d '{"name":"Wegs Ziel"}' | jq -r '.data.id')"
DATEI="$(hochladen weg "$DW" "$TMP/bild.png" | jq -r '.data.id')"
pruefe "Bild des Benutzers liegt auf dem Server" "$(find "$TMP/app/data/uploads" -name "$DATEI.*" | wc -l)" '. == 1'
pruefe "Admin löscht Benutzer" "$(status admin -X DELETE "${A}users/$WEG_ID")" '. == 200'
pruefe "seine Bilder sind vom Server entfernt" "$(find "$TMP/app/data/uploads" -name "$DATEI.*" | wc -l)" '. == 0'
pruefe "seine Sitzung endet → 401" "$(status weg "${A}destinations")" '. == 401'
pruefe "Wartungsskript über das Web → 404 ohne Ausgabe" "$(curl -s -w '%{http_code}' "$U/server/cli.php?list-users")" '. == 404'

# ---------------------------------------------------------------------------------------------------------
abschnitt "Fehlermeldungen ohne interne Details"
pruefe "unbekannte Route → 404 als JSON" "$(als anna "${A}gibt-es-nicht")" '.error.code == "NOT_FOUND"'
pruefe "falsche Methode → 404 als JSON" "$(als anna -X PATCH "${A}destinations")" '.error.code == "NOT_FOUND"'
pruefe "überlange ID → 404" "$(status anna "${A}destinations/$(lang x 10000)")" '. == 404'
# JSON maskiert / als \/ – vor der Suche zurückwandeln, sonst bleiben Pfade in JSON-Antworten unentdeckt
LECK_MUSTER='Stack trace|#[0-9]+ /|/tmp/|\.php on line|\.php:[0-9]|\.php\([0-9]|Fatal error|Warning:|Notice:|Deprecated:|Uncaught|PDOException|SQLSTATE|passwordHash|totpSecret|/home/|/projects/'
sed 's#\\/#/#g' "$LOG" > "$TMP/antworten-klar.log"
LECKS="$(grep -E -c "$LECK_MUSTER" "$TMP/antworten-klar.log")"
pruefe "keine Stacktraces, Pfade, SQL oder Geheimnisse in $(wc -l < "$LOG") Antworten" "$LECKS" '. == 0'
[ "$LECKS" = 0 ] || grep -E -m 5 "$LECK_MUSTER" "$TMP/antworten-klar.log" | cut -c1-200
pruefe "keine PHP-Warnungen im Serverprotokoll" "$(grep -E -c 'PHP (Warning|Notice|Deprecated|Fatal)' "$TMP/server.log")" '. == 0'
