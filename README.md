# Ausflugsziele

Progressive Web App zur Verwaltung persönlicher Ausflugsziele.

> [!WARNING]
> **Dieses Projekt befindet sich noch in der Entwicklung.**
> Funktionen, Datenmodell und API können sich jederzeit ohne Vorankündigung ändern.

Aktuelle Version: **0.1.0** – siehe [CHANGELOG](CHANGELOG.md).

Die App kommt ohne npm, Composer, Build-Schritt und externe Bibliotheken aus:
das Frontend ist reines HTML/CSS/JavaScript, das Backend reines PHP mit SQLite.

## Voraussetzungen
- Webserver mit PHP **8.1 oder neuer** (z. B. Apache, nginx + PHP-FPM, oder gewöhnliches Webhosting)
- PHP-Erweiterung `pdo_sqlite` (bei fast allen Hostern standardmäßig aktiv)
- Optional: PHP-Erweiterung `openssl` für die Anmeldung mit Passkeys (ebenfalls fast überall aktiv)
- Schreibrechte für PHP im Ordner `data/`

## Installation
1. Alle Dateien in ein Verzeichnis des Webservers kopieren (auch ein Unterordner funktioniert).
2. Sicherstellen, dass PHP in `data/` schreiben darf. Auf einem eigenen Linux-Server z. B.
   (Webserver-Benutzer: `apache` unter Fedora/RHEL, `www-data` unter Debian/Ubuntu):
   ```sh
   sudo chgrp -R apache data && chmod -R g+rwX data
   ```
   Bei Webhostern genügt meist, `data/` per FTP die Rechte `775` zu geben.
3. Die Seite im Browser öffnen. Beim ersten Aufruf wird die Datenbank angelegt und
   du richtest den ersten Zugang ein – er erhält Administratorrechte.
4. Weitere Benutzer legt ein Admin unter **Profil → Benutzer** an. Das Passwort erzeugt die App und zeigt es
   einmalig zum Weitergeben an. Dort lassen sich Benutzer auch sperren, entsperren, löschen und ihr Passwort
   neu erzeugen.

Für den Betrieb im Internet unbedingt HTTPS verwenden.

### Passkeys
Benutzer können unter **Profil → Anmeldung & Sicherheit** Passkeys anlegen und sich damit ohne Passwort
anmelden (Fingerabdruck, Gesichtserkennung, Geräte-PIN oder Passwortmanager). Das Passwort bleibt als
Alternative erhalten. Voraussetzungen:
- Die App wird über **HTTPS unter einem Domainnamen** aufgerufen – lokal genügt `http://localhost`,
  IP-Adressen wie `127.0.0.1` funktionieren nicht.
- Passkeys sind an den Hostnamen gebunden, unter dem sie angelegt wurden. Zieht die App auf eine andere
  Domain um, müssen sie neu angelegt werden; die Anmeldung per Passwort funktioniert weiterhin.
- Ohne die PHP-Erweiterung `openssl` blendet die App Passkeys aus.

Geht ein Gerät verloren, kann ein Admin unter **Profil → Benutzer** die Passkeys eines Benutzers einsehen und
einzeln entfernen – oder auf der Kommandozeile mit `list-passkeys` und `delete-passkeys` (siehe unten).

### Apache
Die mitgelieferten `.htaccess`-Dateien sperren `server/` und `data/` und setzen Sicherheits-Header.
Dafür muss `AllowOverride All` (mindestens `AuthConfig FileInfo Indexes Options`) erlaubt sein.

PHP-Einstellungen (Fehlerausgabe aus, Uploads bis 10 MB) stehen in `.user.ini` (PHP-FPM/CGI)
bzw. in `.htaccess` (mod_php). Lässt der Hoster das nicht zu, die Werte in dessen Verwaltungsoberfläche setzen.

### Fehlersuche
**„Die App konnte nicht geladen werden: Auf dem Server fehlt die PHP-Erweiterung pdo_sqlite“**
– PHP ist ohne SQLite-Unterstützung installiert. Prüfen mit `php -m | grep -i sqlite`, dann nachinstallieren,
z. B. `sudo dnf install php-pdo` (Fedora/RHEL) bzw. `sudo apt install php-sqlite3` (Debian/Ubuntu),
und den Webserver bzw. `php -S` neu starten. Bei Webhostern die Erweiterung in der Verwaltungsoberfläche aktivieren.

**„Die App konnte nicht geladen werden: Der Webserver darf nicht in den Ordner data/ schreiben“**
– Schritt 2 der Installation fehlt. Bei aktivem SELinux (Fedora, RHEL) zusätzlich:
```sh
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/pfad/zur/app/data(/.*)?"
sudo restorecon -R /pfad/zur/app
```

**„Interner Fehler“** – die genaue Ursache steht im Fehlerprotokoll des Webservers
(z. B. `/var/log/httpd/error_log` bzw. `/var/log/apache2/error.log`, Einträge beginnen mit `Ausflugsziele`).

### nginx
nginx liest keine `.htaccess`-Dateien. Die internen Ordner müssen selbst gesperrt werden:

```nginx
location ~ ^/(data|server)/ { deny all; }
location ~ /\.             { deny all; }
location ~ \.(db|md)$      { deny all; }
location = /api.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/api.php;
    fastcgi_pass unix:/run/php-fpm/www.sock;
}
```

### Datenverzeichnis außerhalb des Webroots (empfohlen, falls möglich)
Über die Umgebungsvariable `AUSFLUGSZIELE_DATA_DIR` kann ein anderer Speicherort gesetzt werden,
z. B. in Apache mit `SetEnv AUSFLUGSZIELE_DATA_DIR /var/lib/ausflugsziele`.

## Lokale Entwicklung
PHP bringt einen eingebauten Entwicklungsserver mit:

```sh
php -S localhost:8000
```

Danach http://localhost:8000 öffnen. Achtung: Der eingebaute Server beachtet keine `.htaccess`-Dateien –
nur für die Entwicklung verwenden.

Optional Demo-Daten anlegen (Benutzer `admin` und `demo` mit zufälligen Passwörtern, die einmalig angezeigt werden):

```sh
php server/cli.php seed-demo
```

## Wartung über die Kommandozeile
```sh
php server/cli.php list-users
php server/cli.php create-user <name> <passwort> [ADMIN|USER]
php server/cli.php set-password <name> <passwort>
php server/cli.php list-passkeys <name>
php server/cli.php delete-passkeys <name> [<passkey-id>]   # ohne ID: alle Passkeys des Benutzers
```

## Backup & Restore
Alle Daten liegen in `data/`:
- `data/app.db` – SQLite-Datenbank
- `data/uploads/` – hochgeladene Bilder

Für ein Backup genügt es, diesen Ordner zu kopieren (am besten, während niemand die App benutzt).
Zum Wiederherstellen den Ordner zurückkopieren.

### Umstieg von der früheren Next.js-Fassung
Eine SQLite-Datenbank der früheren Node.js-Version (`dev.db`) kann direkt als `data/app.db`
weiterverwendet werden; bestehende Passwörter bleiben gültig. Bereits hochgeladene Bilder aus
`public/uploads/` nach `data/uploads/` verschieben. Details im [CHANGELOG](CHANGELOG.md).

## API
Alle Endpunkte liegen unter `api.php?r=<pfad>` und antworten mit
`{ "success": true, "data": … }` bzw. `{ "success": false, "error": { "code", "message" } }`.
Schreibende Anfragen benötigen den Header `X-Requested-With: fetch`.
Binärdaten der Passkey-Endpunkte (Challenge, Schlüssel-IDs, Antworten des Browsers) sind Base64URL-kodiert.

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| GET | `me` | Angemeldeter Benutzer und ob die Ersteinrichtung aussteht |
| POST | `setup` | Ersten Admin anlegen (nur solange es keine Benutzer gibt) |
| POST | `login`, `logout`, `change-password` | Anmeldung und Passwort |
| POST | `passkey-login/options`, `passkey-login` | Anmeldung per Passkey: Challenge abrufen / Antwort des Browsers prüfen |
| GET/POST | `passkeys` | Eigene Passkeys auflisten / neuen Passkey speichern |
| POST | `passkeys/options` | Optionen zum Anlegen eines Passkeys |
| PUT/DELETE | `passkeys/<id>` | Passkey umbenennen / entfernen |
| GET/POST | `destinations` | Ziele auflisten (Filter: `search`, `favorite`, `visited`, `minRating` (1–5), `tag[]` – mehrfach angebbar, Treffer haben alle Tags) / anlegen |
| GET/PUT/DELETE | `destinations/<id>` | Ziel lesen / ändern / löschen |
| GET/POST | `tags` | Tags auflisten / anlegen |
| PUT/DELETE | `tags/<id>` | Tag umbenennen / löschen (nur Admin) |
| POST | `images` | Bild hochladen (`multipart/form-data`: `destinationId`, `file`) |
| GET | `images/<id>/file` | Bild abrufen |
| DELETE | `images/<id>` | Bild löschen |
| GET/POST | `users` | Benutzer auflisten (mit `disabled`, `destinationCount`, `passkeyCount`) / anlegen – ohne `password` erzeugt der Server eines und liefert es einmalig zurück (nur Admin) |
| PUT/DELETE | `users/<id>` | Benutzer sperren bzw. entsperren (`{ "disabled": true }`) / samt Zielen, Fotos und Passkeys löschen (nur Admin, nicht das eigene Konto) |
| POST | `users/<id>/password` | Neues Passwort erzeugen und einmalig zurückgeben (nur Admin, nicht das eigene Konto) |

## Projektstruktur
- `index.html`, `assets/` – Frontend (Single-Page-App mit Hash-Routing)
- `api.php` – JSON-API
- `server/` – PHP-Hilfsfunktionen, Validierung, Passkeys (WebAuthn) und Kommandozeilen-Skript
- `data/` – Datenbank, Sessions und Uploads (wird automatisch angelegt, nicht öffentlich)

## Mitwirken
Hinweise zur Entwicklung und zu Beiträgen stehen in [CONTRIBUTING.md](CONTRIBUTING.md),
Änderungen zwischen den Versionen im [CHANGELOG](CHANGELOG.md).

## Lizenz
Copyright (C) 2026 listiges-kaenguru

Dieses Programm ist freie Software: Sie können es unter den Bedingungen der
GNU Affero General Public License, wie von der Free Software Foundation veröffentlicht,
weitergeben und/oder modifizieren, entweder gemäß Version 3 der Lizenz oder
(nach Ihrer Wahl) jeder späteren Version.

Dieses Programm wird in der Hoffnung bereitgestellt, dass es nützlich ist,
jedoch OHNE JEDE GEWÄHRLEISTUNG. Details finden Sie in der Datei [LICENSE](LICENSE).

Die App verlinkt auf der Anmeldeseite und im Profil auf ihren Quelltext. Wer eine veränderte Fassung
für andere über das Netzwerk bereitstellt, muss deren Quelltext ebenfalls anbieten (Abschnitt 13 der AGPL)
und dazu `SOURCE_URL` in `server/bootstrap.php` anpassen.

Icons: [Lucide](https://lucide.dev) (ISC-Lizenz).
