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
- Testen in der gemeinsamen Testumgebung, nichts lokal installieren: `~/projects/testumgebung/pt test` (Syntax, Links und
  `tests/run.sh`). Die Tests liegen in `tests/` (Bash/PHP ohne Framework, laufen auf einer Kopie mit frischer
  SQLite-DB); neue Tests dort ergänzen. Im Browser: `~/projects/testumgebung/pt offen` (Testinstanz, http://localhost:8001) oder
  `~/projects/testumgebung/pt -p 8000 php -S 0.0.0.0:8000` für die eigene Arbeitskopie.

## Abhängige Projekte

- Bei sichtbaren Änderungen `BENUTZERHANDBUCH.md` anpassen (Knopf-Beschriftungen exakt wie in der UI). Neue oder
  geänderte Funktionen auch auf der Projektseite (`../ausflugskiste-web`) beschreiben.
- Nach jedem Release: `../ausflugskiste-web/scripts/build-download.sh` und `../ausflugskiste-demo/scripts/build.sh`
  ausführen (neue Dateien der App dort in `FILES` ergänzen), committen und an den Upload erinnern.
