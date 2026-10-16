# Mitwirken

Danke, dass du zur Ausflugskiste beitragen möchtest! Diese Anleitung erklärt, wie du die App lokal startest
und worauf bei Änderungen zu achten ist.

## Grundsatz: keine Abhängigkeiten

Die App soll auf jedem einfachen Webserver mit PHP laufen – ohne Installation, ohne Build-Schritt.
Deshalb gilt:

- **Keine** Paketmanager (npm, Composer, …), Frameworks, Bundler oder Transpiler.
- **Keine** extern eingebundenen Skripte, Stylesheets oder Schriften (CDNs), die Content-Security-Policy lässt sie ohnehin nicht zu.
- Backend: nur PHP-Bordmittel ab **PHP 8.1** und die Erweiterung `pdo_sqlite`.
  Optionale Erweiterungen (z. B. `mbstring`, `openssl` für Passkeys) nur mit Fallback verwenden.
- Frontend: nur Standard-Browser-APIs, keine Inline-Skripte oder `style`-Attribute im HTML.

## Lokale Entwicklung

Voraussetzung ist PHP 8.1 oder neuer.

```sh
git clone https://github.com/listiges-kaenguru/ausflugskiste.git
cd ausflugskiste
php server/cli.php seed-demo   # optional: legt admin und demo an und zeigt die Passwörter an
php -S localhost:8000
```

Danach http://localhost:8000 öffnen. Ohne Demo-Daten führt die App durch die Einrichtung des ersten Admins.

Der eingebaute PHP-Server beachtet keine `.htaccess`-Dateien. Änderungen an `.htaccess`
bitte zusätzlich mit Apache prüfen, z. B.:

```sh
docker run --rm -p 8080:80 -v "$PWD":/var/www/html php:8.1-apache
```

(Dort muss `data/` für den Benutzer `www-data` beschreibbar sein.)

## Projektstruktur

| Pfad | Inhalt |
| --- | --- |
| `index.html`, `assets/` | Frontend |
| `api.php` | Router und alle API-Endpunkte |
| `server/bootstrap.php` | Konfiguration, Datenbank, Schema, Session, Antwort-Helfer |
| `server/validation.php` | Eingabeprüfung |
| `server/webauthn.php` | Passkeys: WebAuthn-Prüfung, CBOR- und COSE-Auswertung (benötigt `openssl`) |
| `server/cli.php` | Wartung über die Kommandozeile |
| `data/` | Laufzeitdaten, nicht versioniert (außer `.htaccess`) |

## Richtlinien

### Allgemein
- Code, Kommentare, Oberfläche und Fehlermeldungen sind auf **Deutsch**; Bezeichner im Code auf Englisch.
- Einrückung: 4 Leerzeichen in PHP, 2 Leerzeichen in JS/CSS/HTML (siehe `.editorconfig`).
- Kleine, fokussierte Änderungen sind leichter zu prüfen als große.

### Backend (PHP)
- Jede Datei beginnt mit `declare(strict_types=1);`.
- SQL ausschließlich mit Prepared Statements und Platzhaltern.
- Endpunkte prüfen Anmeldung (`require_user()` / `require_admin()`) und Eigentümerschaft (`own_destination()`, `own_image()`).
- Fehler mit `fail(status, code, message)` melden; Antworten haben immer die Form
  `{ "success": true, "data": … }` bzw. `{ "success": false, "error": { "code", "message" } }`.
- **Datenbankschema:** Bestehende Installationen müssen weiterlaufen. Schemaänderungen nur additiv in
  `migrate()` (z. B. `CREATE TABLE IF NOT EXISTS`, neue Spalten mit Prüfung, ob sie schon existieren).

### Frontend (JavaScript)
- DOM-Elemente mit `h()` erzeugen. Benutzereingaben **nie** über `innerHTML` einfügen.
- Links aus Benutzereingaben nur nach Prüfung mit `isSafeUrl()` als `href` verwenden.
- API-Aufrufe über `api()`, Formulare über `bindForm()`.

## Vor dem Pull Request

1. PHP-Syntax prüfen: `for f in api.php server/*.php; do php -l "$f"; done`
2. Die betroffenen Abläufe im Browser durchspielen, mindestens:
   Einrichtung bzw. Login, Ziel anlegen/bearbeiten/löschen, Suche, Bild hochladen, Profil, Abmelden.
   Bei Änderungen an der Anmeldung zusätzlich: Passkey anlegen, damit anmelden und wieder entfernen.
   Ohne passendes Gerät hilft der virtuelle Authenticator der Chrome-Entwicklertools
   (*Weitere Tools → WebAuthn*).
3. Bei API-Änderungen die Tabelle im [README](README.md#api) anpassen.
4. Bei sichtbaren Änderungen das [Benutzerhandbuch](BENUTZERHANDBUCH.md) anpassen (Knopf-Beschriftungen exakt
   wie in der Oberfläche), bei Installation und Betrieb die README.
5. Einen Eintrag unter `## [Unveröffentlicht]` im [CHANGELOG](CHANGELOG.md) ergänzen
   (Abschnitt anlegen, falls er fehlt).

## Commits und Branches

- Für jede Änderung einen eigenen Branch von `main` anlegen.
- Commit-Nachrichten auf Deutsch, erste Zeile im Imperativ und kurz (≤ 72 Zeichen),
  z. B. `Bildergalerie auf der Detailseite vergrößern`.

## App-Icons

Quelle aller Icons ist `icon.svg`. Nach Änderungen daran `icon-192.png` und `icon-512.png` neu erzeugen,
z. B. mit einem Chromium-basierten Browser (im selben Ordner wie `icon.svg`):

```sh
for n in 192 512; do
  printf '<style>html,body{margin:0}img{display:block;width:%spx}</style><img src="icon.svg">' $n > /tmp/icon.html
  cp icon.svg /tmp/ && chromium --headless --default-background-color=00000000 --hide-scrollbars \
    --force-device-scale-factor=1 --window-size=$n,$n --screenshot=icon-$n.png /tmp/icon.html
done
```

`favicon.ico` enthält dieselbe Grafik in 16, 32 und 48 Pixeln (PNG-Einträge).

## Neue Version veröffentlichen

Versionen folgen [Semantic Versioning](https://semver.org/lang/de/) und werden als Git-Tag `vX.Y.Z` markiert.

1. `APP_VERSION` in `server/bootstrap.php` und die Versionsangabe im README erhöhen.
2. Im CHANGELOG `## [Unveröffentlicht]` in `## [X.Y.Z] – JJJJ-MM-TT` umbenennen und den Link am Ende ergänzen.
3. Committen, auf `main` mergen und taggen:
   ```sh
   git tag -a vX.Y.Z -m "Version X.Y.Z"
   git push origin main vX.Y.Z
   ```

## Sicherheitslücken

Sicherheitsprobleme bitte **nicht** als öffentliches Issue melden, sondern vertraulich über
[GitHub Security Advisories](https://github.com/listiges-kaenguru/ausflugskiste/security/advisories/new).

## Lizenz

Mit deinem Beitrag erklärst du dich einverstanden, dass er unter der
[GNU Affero General Public License v3.0 oder später](LICENSE) veröffentlicht wird.
