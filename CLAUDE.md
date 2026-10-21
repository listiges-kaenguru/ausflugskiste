# Ausflugskiste – Hinweise für Claude

PWA zum Sammeln und Bewerten von Ausflugszielen (PHP + SQLite, Vanilla JS). Projektseite und Anleitung im
Projekt `../ausflugskiste-web` (<https://ausflugskiste.schmaralde.de/>), Demo im Projekt `../ausflugskiste-demo`.

## Orientierung

- `README.md` – Installation, Betrieb, API, Projektstruktur
- `BENUTZERHANDBUCH.md` – Bedienung für Nutzer und Admins, du-Form, ohne Technikjargon (Quelle der Anleitung auf
  der Projektseite, daher nicht verschieben; Kapitelnummern und -titel bestimmen dort die Seiten)
- `CHANGELOG.md` – Änderungen je Version; jede Änderung unter `## [Unveröffentlicht]` eintragen
- `IDEEN.md` – ToDo und Ideen; **zuerst lesen**, Erledigtes ins CHANGELOG
- `CONTRIBUTING.md` – Richtlinien, Prüfungen vor dem Pull Request, Release-Ablauf

## Regeln

Die App läuft bewusst **ohne Abhängigkeiten und ohne Build-Schritt** auf einem einfachen Webserver mit PHP:

- Kein npm, Composer, Framework oder Bundler einführen. Nur PHP-Bordmittel (PHP 8.1+, PDO SQLite) und Browser-APIs.
- Frontend: `index.html`, `assets/app.js` (Vanilla JS, Hash-Routing), `assets/app.css`. Benutzereingaben nie per
  `innerHTML` einfügen – immer `h()` verwenden.
- Backend: `api.php` (Router + Endpunkte), `server/` (Bootstrap, Validierung, CLI). Das DB-Schema muss mit
  bestehenden Datenbanken kompatibel bleiben (Änderungen nur additiv in `migrate()`).
- Oberfläche, Kommentare und Doku auf Deutsch; Bezeichner im Code auf Englisch.
- Neue Entwicklungsdateien in `.gitattributes` als `export-ignore` eintragen (Release-ZIP nur mit Benötigtem).
- Testen in einem Container, nichts global installieren: `tests/run.sh` (Bash/PHP ohne Framework, braucht PHP 8.1+
  mit SQLite, `curl`, `jq`; läuft auf einer Kopie mit frischer SQLite-DB), dazu Syntax- und Linkprüfung. Neue Tests
  in `tests/` ergänzen. `tests/sicherheit.sh` enthält die Negativtests (Rechte, Sitzungen, CSRF, Login-Sperre,
  Zwei-Faktor, Passkeys, Injection, Eingabevalidierung, Fehlermeldungen); `tests/totp-code.php` und
  `tests/passkey-client.php` sind bewusst unabhängig vom App-Code. Jede neue Sicherheitsprüfung muss bei einer
  passenden Sabotage der App nachweislich fehlschlagen. Coverage: `PHP_SERVER` auf PHP mit Xdebug setzen. Im
  Browser: `OFFEN=1 tests/run.sh` lässt die Testinstanz unter http://localhost:8001 offen, `php -S 0.0.0.0:8000`
  startet die eigene Arbeitskopie.
- Login: Passkeys und Zwei-Faktor per TOTP gehören dazu; neue Anmeldewege bekommen beides.

## Abhängige Projekte

- Bei sichtbaren Änderungen `BENUTZERHANDBUCH.md` anpassen (Knopf-Beschriftungen exakt wie in der UI). Neue oder
  geänderte Funktionen auch auf der Projektseite (`../ausflugskiste-web`) beschreiben.
- Nach jedem Release: `../ausflugskiste-web/scripts/build-download.sh` und `../ausflugskiste-demo/scripts/build.sh`
  ausführen (neue Dateien der App dort in `FILES` ergänzen), committen und beide hochladen. Die Projektseite
  erst mit dem Release hochladen, damit sie nichts beschreibt, was die veröffentlichte Version nicht kann.
