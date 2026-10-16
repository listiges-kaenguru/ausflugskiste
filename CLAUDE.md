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
- Lokal testen: `php -S localhost:8000`, PHP-Syntax mit `php -l`.

## Abhängige Projekte

- Bei sichtbaren Änderungen `BENUTZERHANDBUCH.md` anpassen (Knopf-Beschriftungen exakt wie in der UI). Neue oder
  geänderte Funktionen auch auf der Projektseite (`../ausflugskiste-web`) beschreiben.
- Nach jedem Release: `../ausflugskiste-web/scripts/build-download.sh` und `../ausflugskiste-demo/scripts/build.sh`
  ausführen (neue Dateien der App dort in `FILES` ergänzen), committen und an den Upload erinnern.
