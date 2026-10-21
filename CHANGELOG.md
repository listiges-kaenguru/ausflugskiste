# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).
Solange die Version unter 1.0.0 liegt, können sich Datenmodell und API jederzeit ändern.

## [Unveröffentlicht]

### Hinzugefügt

- **Export:** Im Profil unter „Deine Daten“ lädt **Ziele exportieren** alle eigenen Ziele als JSON-Datei herunter (mit Notizen und Tags, Fotos nur als Angaben). Neuer Endpunkt `GET destinations/export`.
- Kommandozeile: `lock-user`, `unlock-user` und `delete-user` (samt Zielen und Fotos, mit Rückfrage). Den letzten aktiven Admin sperren oder löschen sie nicht.
- API: `PATCH destinations/<id>` setzt nur Favorit und/oder Besucht. Die Umschalter in der App nutzen ihn; bisher schickten sie alle Felder mit und konnten so eine gleichzeitige Änderung auf einem anderen Gerät überschreiben.

### Sicherheit

- `php server/cli.php set-password` beendet jetzt alle laufenden Sitzungen des Benutzers, wie der Passwortwechsel in der App. Bisher blieb z. B. nach einem kompromittierten Konto der Angreifer angemeldet.

### Behoben

- Kommandozeile: `create-user` und `set-password` prüfen Benutzername und Passwort wie die App (Passwörter über 72 Bytes wurden still gekürzt, ungültiges UTF-8 im Namen legte die Benutzerliste der App lahm, ein doppelter Name endete mit Stacktrace). Fehler gehen auf stderr mit Exit-Code 1, auch bei unbekanntem Benutzer.
- Im Profil stand unter „Zwei-Faktor-Anmeldung“ „nullnull“, solange sie nicht eingerichtet war, bei aktiver Zwei-Faktor-Anmeldung „null“.

### Intern

- Browsertest der Oberfläche (`tests/oberflaeche.cjs`, Playwright aus der Testumgebung) prüft das Profil.
- Tests des Exports in `tests/export.sh` (fremde und gelöschte Ziele, Dateiname bei Sonderzeichen im Benutzernamen).
- Tests der Kommandozeile in `tests/cli.sh` (auch `disable-totp`); API und Kommandozeile löschen Benutzer über dieselbe Funktion.
- PHPStan ohne Funde: Routen-Filter ohne `strlen`-Callback, `disable-totp` nennt den Benutzer aus der Datenbank.
- Der Test mit 100 000 Ebenen CBOR (Passkeys) sendet die Anfrage jetzt wirklich: Der Body war größer als ein Programmargument sein darf, ging leer raus und bestand so, ohne den Decoder zu erreichen.
- Hinweise für Mitwirkende (`CLAUDE.md`, CHANGELOG) allgemein formuliert, ohne lokale Pfade und eigene Werkzeuge; offene Release-Schritte für 0.6.0 in `IDEEN.md`.
- Testskripte nennen in Meldungen und Kommentaren keinen lokalen Pfad mehr, sondern verweisen auf einen Container mit den Testwerkzeugen und die README.

## [0.5.1] – 2026-10-18

Sicherheitskorrektur: Ein Passwortwechsel beendet die Sitzungen auf anderen Geräten.

### Sicherheit

- Ein Passwortwechsel meldet den Zugang auf allen anderen Geräten ab, ebenso das Zurücksetzen des Passworts durch einen Administrator. Bisher blieben andere Sitzungen angemeldet. Bestehende Datenbanken bekommen die nötige Spalte beim ersten Aufruf; laufende Sitzungen bleiben beim Update gültig.

### Intern

- Tests laufen in einem Container (`tests/run.sh`): Syntax, Einrichtung und Anmeldung mit frischer SQLite-DB.
- API-Tests liegen jetzt im Projekt (`tests/run.sh`, Bash ohne Framework) statt zentral in der Testumgebung; `tests/` ist nicht im Release-ZIP.
- Tests schärfer: Versions- und Ziele-Prüfung erkennen Fehlerantworten; die Versionsprüfung läuft nach der Anmeldung und ohne Netz (fester Cache statt GitHub-Abfrage).
- Negativtests in `tests/sicherheit.sh` (226 Prüfungen): Rechte, Sitzungen, CSRF, Login-Sperre, Zwei-Faktor, Passkeys (ES256/RS256), Injection, Eingabevalidierung, Fehlermeldungen; unabhängige TOTP-Berechnung und Passkey-Simulation in `tests/`.

## [0.5.0] – 2026-10-17

### Hinzugefügt
- **Versionsprüfung:** Ganz unten im Profil steht jetzt, ob auf GitHub eine neuere Version erschienen ist
  (für Admins mit Link zum Release), sonst „Das ist die neueste Version.“ Die Abfrage stellt der Server
  (neuer Endpunkt `GET version`, über cURL oder `allow_url_fopen`), nicht der Browser. Das Ergebnis merkt er sich
  12 Stunden in `data/release.json`, nach einem Fehlschlag fragt er frühestens nach einer Stunde erneut.
  Version 0.5.0.

## [0.4.2] – 2026-10-16

### Hinzugefügt
- `BENUTZERHANDBUCH.md`: die Anleitung für Nutzer und Administratoren liegt jetzt im App-Repo und damit auch in
  der Release-ZIP. Bisher stand sie nur auf der Projektseite, die sie künftig aus dieser Datei baut.

### Geändert
- Code aufgeräumt, ohne Änderung an Oberfläche, Verhalten oder API: ungenutztes Icon und CSS-Regeln entfernt,
  doppelte Bausteine zusammengefasst (Kopieren-Button, Passkey-Listeneintrag, Adresszeile, Passwortprüfung,
  Rate-Limit für 2FA-Codes, Bildpfade, lesbare Zufallscodes) und die Prüfung des Datenverzeichnisses vereinfacht.
- Hinweise für Coding-Agents stehen jetzt in `CLAUDE.md` (statt `AGENTS.md`), `IDEEN.md` ist in ToDo und Ideen gegliedert.

## [0.4.1] – 2026-10-16

### Sicherheit
- Die Geheimnisse der Zwei-Faktor-Anmeldung werden jetzt verschlüsselt gespeichert (AES-256-GCM, wie in
  AuslagenFlow). Der Schlüssel liegt in der neuen Datei `data/secret.key` statt in der Datenbank und wird beim
  ersten Bedarf erzeugt; die Benutzer-ID ist mitgebunden, ein Geheimnis lässt sich nicht in ein anderes Konto
  kopieren. Bestehende Geheimnisse aus 0.4.0 werden beim ersten Aufruf automatisch verschlüsselt, eingerichtete
  Authenticator-Apps funktionieren weiter. Die Zwei-Faktor-Anmeldung braucht dafür die PHP-Erweiterung
  `openssl` (wie Passkeys); `me` liefert dazu `totp`. Passt der Schlüssel nicht mehr, meldet die Anmeldung das
  verständlich (`TOTP_KEY_ERROR`) statt „Code falsch“.

## [0.4.0] – 2026-10-16

### Hinzugefügt
- **Zwei-Faktor-Anmeldung (TOTP):** Unter Profil → Anmeldung & Sicherheit lässt sich eine Authenticator-App
  einrichten (QR-Code, alternativ Link oder Schlüssel zum Abtippen). Danach fragt die Anmeldung mit Passwort
  zusätzlich nach dem sechsstelligen Code; die Anmeldung mit Passkey bleibt ohne Code. Zehn
  Wiederherstellungscodes (einmalig angezeigt, zum Kopieren oder Herunterladen) helfen, wenn das Handy fehlt;
  neue lassen sich jederzeit erzeugen. Abschalten und neue Codes verlangen einen gültigen Code. Jeder Code gilt
  nur einmal, höchstens 5 Fehlversuche in 5 Minuten je Benutzer. Der QR-Code entsteht im Browser ohne
  externe Bibliothek.
- Admins können die Zwei-Faktor-Anmeldung eines Benutzers unter Profil → Benutzer abschalten (z. B. nach
  Handyverlust); die Benutzerliste zeigt „2FA aktiv“. Neuer CLI-Befehl `disable-totp <name>`, `list-users`
  zeigt „2FA“.
- Neue Endpunkte `login/totp`, `totp` (GET/POST/DELETE), `totp/setup`, `totp/recovery-codes` und
  `DELETE users/<id>/totp`; `me` und `users` liefern `totpEnabled`. Die Datenbank erhält dafür drei neue
  Spalten in `User` (werden beim ersten Aufruf automatisch ergänzt).

### Behoben
- Nach einem Update zeigte der Browser oft noch die alte Version, bis die Seite neu geladen wurde. Die
  `.htaccess` setzt jetzt `Cache-Control: no-cache` für statische Dateien (HTML, JS, CSS, Icons, Manifest); der
  Browser prüft sie bei jedem Aufruf kurz beim Server. API-Antworten (`no-store`) und Fotos behalten ihre Werte.

### Geändert
- Release-Archive enthalten nur noch, was für den Betrieb gebraucht wird, dazu README und LICENSE
  (`export-ignore` in `.gitattributes`).

## [0.3.0] – 2026-10-15

### Geändert
- **Neuer Name: Ausflugskiste** – „Deine Schatzkiste für freie Tage.“ Titel, Kopfzeile (Wortmarke mit
  „kiste“ in Holzfarbe), Manifest (`name` „Ausflugskiste“, `short_name` „Ausflüge“), Fußzeile, Anzeigename neuer
  Passkeys und Log-Einträge tragen den neuen Namen. Das Repository heißt jetzt `listiges-kaenguru/ausflugskiste`
  (`SOURCE_URL`); GitHub leitet alte Links weiter.
- **Neues Icon:** Truhe mit Kompass als Schloss, weiterhin auf dunklem Grund (`icon.svg`, `icon-192.png`,
  `icon-512.png`, `favicon.ico`).
- **Farben:** heller Hintergrund und Flächen in warmem Creme statt kühlem Grau; Grün und Sterne unverändert.
- **Texte:** Leere Liste „Deine Kiste ist noch leer“, „Wohin heute?“ beginnt mit „Ein Griff in die Kiste“.
- **Bewusst unverändert:** Umgebungsvariable `AUSFLUGSZIELE_DATA_DIR`, Datenbank, Sitzungs-Cookie. Bestehende
  Installationen laufen ohne Anpassung weiter; vorhandene Passkeys behalten ihren alten Anzeigenamen.

## [0.2.0] – 2026-10-14

### Hinzugefügt
- **Passkeys verwalten für Admins:** Unter Profil → Benutzer zeigt ein Fingerabdruck-Button die Passkeys eines
  Benutzers; einzelne lassen sich entfernen, z. B. nach einem verlorenen Gerät. Neue Endpunkte
  `GET users/<id>/passkeys` und `DELETE users/<id>/passkeys/<passkeyId>`, neue CLI-Befehle
  `list-passkeys <name>` und `delete-passkeys <name> [<passkey-id>]`.
- **Passwortwechsel erzwingen:** Nach einem vom Admin vergebenen Passwort (neuer Benutzer oder „Neues Passwort“)
  muss der Benutzer bei der nächsten Anmeldung – auch per Passkey – ein eigenes Passwort festlegen; bis dahin
  lehnt die API alle anderen Anfragen mit `PASSWORD_CHANGE_REQUIRED` ab. Das aktuelle Passwort wird dabei nicht
  abgefragt, das neue muss sich vom bisherigen unterscheiden. Die Benutzerliste und `server/cli.php list-users`
  zeigen ausstehende Wechsel. Neue Spalte `User.mustChangePassword` (wird automatisch ergänzt); `me`, `login`,
  `passkey-login` und `users` liefern das Feld `mustChangePassword`.
- **Bilder verkleinern:** Fotos werden vor dem Upload im Browser per Canvas auf höchstens 2560 Pixel
  Kantenlänge verkleinert (JPEG mit Qualität 0,85, PNG bleibt PNG, GIFs bleiben unverändert). Das spart Speicher
  und Ladezeit; größere Handyfotos passen so unter die Upload-Grenze. Bereits gespeicherte Fotos bleiben unverändert.

### Geändert
- **Lizenz:** Die App steht jetzt unter der GNU Affero General Public License v3.0 oder später (bisher GPL-3.0).
- Anmeldeseite und Profil verlinken auf den Quelltext (`SOURCE_URL` in `server/bootstrap.php`);
  `me` liefert zusätzlich `sourceUrl`.

## [0.1.0] – 2026-10-08

### Hinzugefügt
- **Passkeys:** Unter Profil → Anmeldung & Sicherheit Passkeys anlegen, umbenennen und entfernen;
  Anmeldung per Passkey-Button oder direkt über das Autofill des Benutzernamens. Nach einer Anmeldung
  mit Passwort schlägt die Übersicht einmalig vor, einen Passkey einzurichten. Bekannte Passwortmanager
  (Google, iCloud, Windows Hello, 1Password, Bitwarden, …) werden am Namen des Passkeys erkannt.
  Umsetzung ohne Bibliothek in `server/webauthn.php` (ES256 und RS256, benötigt die PHP-Erweiterung `openssl`).
- Neue API-Endpunkte `passkeys`, `passkeys/options`, `passkeys/<id>`, `passkey-login/options`, `passkey-login`;
  `me` liefert zusätzlich `passkeys` (ob der Server Passkeys unterstützt).
- Neue Tabelle `Passkey` (wird bei bestehenden Datenbanken automatisch ergänzt).
- Übersicht: Favorit und Besucht direkt auf der Karte umschalten, Titelbild (erstes Foto) und Anzahl der Fotos,
  Sortierung (neueste, zuletzt geändert, Name, Bewertung).
- Detailseite: Fotogalerie mit Vollbildansicht (Blättern per Pfeiltasten oder Wischen), Fotos per Drag & Drop
  hinzufügen, Link „Auf Karte zeigen“ zur Adresse (OpenStreetMap).
- Hell- und Dunkelmodus nach Systemeinstellung.
- **Benutzerverwaltung:** Admins können Benutzer sperren und entsperren (gesperrte Benutzer werden sofort
  abgemeldet und können sich weder per Passwort noch per Passkey anmelden), löschen (samt Zielen, Fotos und
  Passkeys; zur Sicherheit muss der Benutzername eingetippt werden) und ein neues Passwort erzeugen.
  Das eigene Konto ist davon ausgenommen. Neue Endpunkte `PUT`/`DELETE users/<id>` und `POST users/<id>/password`;
  neue Spalte `User.disabledAt` (wird automatisch ergänzt). `server/cli.php list-users` zeigt gesperrte Benutzer.
- „Wohin heute?“: Ein Würfel in der Übersicht schlägt ein zufälliges, noch nicht besuchtes Ziel aus der
  aktuellen Auswahl vor.

### Geändert
- Oberfläche überarbeitet: Navigation oben (Desktop) bzw. unten mit hervorgehobenem „Neu“-Button (Mobil),
  Karten mit Titelbild, gegliederte Formulare mit Sterne-Auswahl und Schaltern, festgehaltene Speichern-Leiste.
- Suche und Übersicht zusammengelegt: Suchfeld, Schnellfilter (Alle, Favoriten, Noch offen, Besucht) mit
  Anzahl sowie ausklappbare Filter für Bewertung und Tags; `#/search` führt zur Übersicht.
- Bestätigungen, Eingaben und Meldungen erscheinen als Dialoge und Hinweise in der App statt als
  Browser-Fenster (`confirm`, `prompt`, `alert`).
- Einrichtung und Passwortänderung fragen das neue Passwort zur Sicherheit doppelt ab.
- Nach dem Anlegen eines Ziels geht es direkt zur Detailseite, um Fotos hinzuzufügen; die Übersicht merkt sich
  beim Zurückkehren Filter und Scrollposition.
- Beim Anlegen eines Benutzers erzeugt die App das Passwort selbst und zeigt es einmalig mit Kopier-Button an.
  Die API akzeptiert weiterhin ein selbst gewähltes `password`.

### Behoben
- Fehlt PHP die Erweiterung `pdo_sqlite`, zeigt die App (und `server/cli.php`) eine verständliche Meldung
  statt „Interner Fehler“. README um einen Abschnitt zur Fehlersuche ergänzt.

## [0.0.3] – 2026-10-05

### Hinzugefügt
- Eigenes App-Icon (Kompass im Stil der App) mit der Quelldatei `icon.svg`, die modernen Browsern
  auch direkt als Favicon dient.

### Behoben
- Die App-Icons `icon-192.png` und `icon-512.png` waren leere Dateien; Startbildschirm- und Tab-Icon fehlten.
- `favicon.ico` enthielt noch das Vercel-Logo aus der Next.js-Vorlage.

## [0.0.2] – 2026-10-05

### Hinzugefügt
- Suche: Filter nach Mindestbewertung (API-Parameter `minRating`).
- Suche: Mehrere Tags gleichzeitig auswählbar; angezeigt werden Ziele, die alle ausgewählten Tags haben
  (API-Parameter `tag[]`, mehrfach angebbar; `tag=<name>` funktioniert weiterhin).
- Hinweistext unter dem Link-Feld mit Beispielen.

### Geändert
- Das Feld „Google Maps Link“ heißt jetzt „Link“ und ist für beliebige Webadressen gedacht
  (z. B. OpenStreetMap, Komoot oder die Webseite des Ziels). Der Button auf der Detailseite heißt
  „Link öffnen“ und zeigt die Ziel-Domain. Feldname in API und Datenbank bleibt `googleMapsLink`.
- Die Suchfilter stehen ab mittlerer Bildschirmbreite nebeneinander.

### Behoben
- Fehlen dem Webserver Schreibrechte für `data/`, zeigt die App jetzt eine verständliche Meldung statt
  „Interner Fehler“. README um Befehle für Rechte und SELinux sowie einen Abschnitt zur Fehlersuche ergänzt.

## [0.0.1] – 2026-10-05

Erste versionierte Veröffentlichung. Die App läuft jetzt ohne npm, Framework und Build-Schritt auf jedem Webserver mit PHP 8.1+.

### Geändert
- Frontend von Next.js/React auf reines HTML, CSS und JavaScript umgestellt (Single-Page-App mit Hash-Routing).
- Backend von Next.js-API-Routes auf `api.php` mit PDO SQLite umgestellt; Endpunkte liegen jetzt unter `api.php?r=<pfad>`.
- Anmeldung über PHP-Sessions statt JWT-Cookie.
- Daten (Datenbank, Sessions, Bilder) liegen gesammelt in `data/`; der Ort ist über `AUSFLUGSZIELE_DATA_DIR` einstellbar.
- Destinations-Antworten liefern Tags als `[{ id, name }]` und Bilder mit `url` statt verschachtelter Prisma-Strukturen.
- Tags umbenennen und löschen ist nur noch für Administratoren erlaubt.
- Die Suche findet Umlaute unabhängig von Groß-/Kleinschreibung.

### Hinzugefügt
- Ersteinrichtung im Browser: Gibt es noch keine Benutzer, wird der erste Admin-Zugang angelegt.
- Oberfläche für Suche und Filter (Text, Favorit, Besucht, Tag).
- Bilder auf der Detailseite anzeigen, hochladen und löschen.
- Ziele löschen, Tags direkt im Formular anlegen, Tag-Verwaltung im Profil.
- Passwort ändern und Abmelden im Profil.
- Benutzerverwaltung für Administratoren.
- Kommandozeilen-Skript `server/cli.php` (Benutzer anlegen, Passwort zurücksetzen, Demo-Daten).
- `.htaccess`-Dateien mit Zugriffsschutz und Sicherheits-Headern für Apache.
- `.user.ini` bzw. `.htaccess`-PHP-Einstellungen: Fehler werden protokolliert statt ausgegeben, Uploads bis 10 MB.
- Verständliche Fehlermeldung, wenn ein Upload die `post_max_size` des Servers überschreitet.
- `CONTRIBUTING.md`, `CHANGELOG.md`, `.editorconfig` und `.gitattributes`.

### Entfernt
- Next.js, React, Prisma, Tailwind und alle weiteren npm-Abhängigkeiten.
- Docker-Setup und PostgreSQL-Konfiguration.
- Feste Standard-Zugangsdaten aus dem Seed; `seed-demo` erzeugt zufällige Passwörter.

### Sicherheit
- Bilder werden nicht mehr öffentlich unter `public/uploads` abgelegt, sondern nur an den Eigentümer des Ziels ausgeliefert.
- Bilder können nur noch zu eigenen Zielen hochgeladen werden.
- Uploads werden auf echte Bilddateien (JPEG, PNG, GIF, WebP, max. 10 MB) geprüft und unter zufälligem Namen gespeichert.
- Google-Maps-Links müssen mit `http://` oder `https://` beginnen (verhindert `javascript:`-Links).
- Schreibende API-Anfragen erfordern den Header `X-Requested-With: fetch` (CSRF-Schutz).
- Das Login-Rate-Limit nutzt die echte Client-Adresse statt des fälschbaren `X-Forwarded-For`-Headers und gilt auch bei mehreren PHP-Prozessen.

### Umstieg von der früheren Next.js-Fassung
- Eine bestehende SQLite-Datenbank (`dev.db`) kann als `data/app.db` weiterverwendet werden; Passwörter bleiben gültig.
- Bisher hochgeladene Bilder aus `public/uploads/` nach `data/uploads/` verschieben.
- PostgreSQL-Datenbanken werden nicht mehr unterstützt.

## Vor 0.0.1

Ursprüngliche, nicht versionierte Fassung als Next.js-PWA mit Prisma, Login, Ausflugszielen, Tags und
Bild-Upload-API (Commit [`96d0982`](https://github.com/listiges-kaenguru/ausflugsziele/commit/96d0982)).

[0.4.2]: https://github.com/listiges-kaenguru/ausflugskiste/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/listiges-kaenguru/ausflugskiste/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/listiges-kaenguru/ausflugskiste/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/listiges-kaenguru/ausflugskiste/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/listiges-kaenguru/ausflugsziele/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/listiges-kaenguru/ausflugsziele/compare/v0.0.3...v0.1.0
[0.0.3]: https://github.com/listiges-kaenguru/ausflugsziele/compare/v0.0.2...v0.0.3
[0.0.2]: https://github.com/listiges-kaenguru/ausflugsziele/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/listiges-kaenguru/ausflugsziele/releases/tag/v0.0.1
