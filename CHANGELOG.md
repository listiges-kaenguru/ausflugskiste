# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).
Solange die Version unter 1.0.0 liegt, können sich Datenmodell und API jederzeit ändern.

## [Unveröffentlicht]

### Hinzugefügt
- **Passkeys verwalten für Admins:** Unter Profil → Benutzer zeigt ein Fingerabdruck-Button die Passkeys eines
  Benutzers; einzelne lassen sich entfernen, z. B. nach einem verlorenen Gerät. Neue Endpunkte
  `GET users/<id>/passkeys` und `DELETE users/<id>/passkeys/<passkeyId>`, neue CLI-Befehle
  `list-passkeys <name>` und `delete-passkeys <name> [<passkey-id>]`.

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

[Unveröffentlicht]: https://github.com/listiges-kaenguru/ausflugsziele/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/listiges-kaenguru/ausflugsziele/compare/v0.0.3...v0.1.0
[0.0.3]: https://github.com/listiges-kaenguru/ausflugsziele/compare/v0.0.2...v0.0.3
[0.0.2]: https://github.com/listiges-kaenguru/ausflugsziele/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/listiges-kaenguru/ausflugsziele/releases/tag/v0.0.1
