<?php
// Gemeinsame Grundlagen: Konfiguration, Datenbank, Session, Antworten.
// Benötigt nur PHP-Bordmittel (PDO SQLite, Sessions, JSON).

declare(strict_types=1);

const APP_VERSION = '0.5.0';
// Link zum Quelltext, den die App allen Nutzern anzeigt (AGPL-3.0, Abschnitt 13).
// Wer eine veränderte Fassung betreibt, muss hier auf deren Quelltext verweisen.
const SOURCE_URL = 'https://github.com/listiges-kaenguru/ausflugskiste';
const SESSION_LIFETIME = 60 * 60 * 24 * 7;
const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
const ALLOWED_IMAGE_TYPES = [
    IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
    IMAGETYPE_PNG => ['image/png', 'png'],
    IMAGETYPE_GIF => ['image/gif', 'gif'],
    IMAGETYPE_WEBP => ['image/webp', 'webp'],
];

function data_dir(): string
{
    static $dir = null;
    if ($dir === null) {
        // Name der Variablen aus der Zeit vor der Umbenennung, damit bestehende Installationen weiterlaufen
        $path = getenv('AUSFLUGSZIELE_DATA_DIR') ?: dirname(__DIR__) . '/data';
        foreach ([$path, "$path/uploads", "$path/sessions"] as $entry) {
            if (!is_dir($entry)) {
                @mkdir($entry, 0770, true);
            }
            if (!is_dir($entry) || !is_writable($entry)) {
                data_dir_error($entry);
            }
        }
        // SQLite braucht Schreibrechte auf Datei und Verzeichnis (für das Journal).
        if (file_exists("$path/app.db") && !is_writable("$path/app.db")) {
            data_dir_error("$path/app.db");
        }
        $dir = $path;
    }
    return $dir;
}

/** Meldet fehlende Schreibrechte verständlich, statt nur „Interner Fehler“ anzuzeigen. */
function data_dir_error(string $path): never
{
    $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
        ? (posix_getpwuid(posix_geteuid())['name'] ?? (string) posix_geteuid())
        : 'unbekannt';
    error_log("Ausflugskiste: Keine Schreibrechte für $path (PHP läuft als Benutzer $user)");
    $where = getenv('AUSFLUGSZIELE_DATA_DIR') ? 'das Datenverzeichnis (AUSFLUGSZIELE_DATA_DIR)' : 'den Ordner data/';
    throw new ApiException(500, 'SETUP_ERROR', "Der Webserver darf nicht in $where schreiben. Bitte Schreibrechte für den Webserver-Benutzer vergeben (siehe README, Abschnitt Installation).");
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        // Fehlt die Erweiterung, scheitert „new PDO“ sonst nur mit „Interner Fehler“.
        if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            error_log('Ausflugskiste: PHP-Erweiterung pdo_sqlite fehlt (php ' . PHP_VERSION . ', ' . (php_ini_loaded_file() ?: 'keine php.ini') . ')');
            throw new ApiException(500, 'SETUP_ERROR', 'Auf dem Server fehlt die PHP-Erweiterung pdo_sqlite. Bitte installieren bzw. aktivieren (z. B. Paket php-pdo oder php-sqlite3, siehe README, Abschnitt Fehlersuche).');
        }
        $pdo = new PDO('sqlite:' . data_dir() . '/app.db', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        migrate($pdo);
    }
    return $pdo;
}

// Entspricht dem bisherigen Prisma-Schema, damit bestehende Datenbanken weiter funktionieren.
// Neue Tabellen (z. B. Passkey) und Spalten kommen nur additiv hinzu.
function migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS "User" (
            "id" TEXT NOT NULL PRIMARY KEY,
            "username" TEXT NOT NULL,
            "passwordHash" TEXT NOT NULL,
            "role" TEXT NOT NULL DEFAULT 'USER',
            "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "updatedAt" DATETIME NOT NULL
        );
        CREATE TABLE IF NOT EXISTS "Destination" (
            "id" TEXT NOT NULL PRIMARY KEY,
            "name" TEXT NOT NULL,
            "address" TEXT,
            "latitude" REAL,
            "longitude" REAL,
            "googleMapsLink" TEXT,
            "description" TEXT,
            "rating" INTEGER,
            "favorite" BOOLEAN NOT NULL DEFAULT false,
            "visited" BOOLEAN NOT NULL DEFAULT false,
            "privateNotes" TEXT,
            "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "updatedAt" DATETIME NOT NULL,
            "deletedAt" DATETIME,
            "createdBy" TEXT NOT NULL,
            CONSTRAINT "Destination_createdBy_fkey" FOREIGN KEY ("createdBy") REFERENCES "User" ("id") ON DELETE CASCADE ON UPDATE CASCADE
        );
        CREATE TABLE IF NOT EXISTS "Tag" (
            "id" TEXT NOT NULL PRIMARY KEY,
            "name" TEXT NOT NULL,
            "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "updatedAt" DATETIME NOT NULL
        );
        CREATE TABLE IF NOT EXISTS "DestinationTag" (
            "destinationId" TEXT NOT NULL,
            "tagId" TEXT NOT NULL,
            PRIMARY KEY ("destinationId", "tagId"),
            CONSTRAINT "DestinationTag_destinationId_fkey" FOREIGN KEY ("destinationId") REFERENCES "Destination" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT "DestinationTag_tagId_fkey" FOREIGN KEY ("tagId") REFERENCES "Tag" ("id") ON DELETE CASCADE ON UPDATE CASCADE
        );
        CREATE TABLE IF NOT EXISTS "DestinationImage" (
            "id" TEXT NOT NULL PRIMARY KEY,
            "destinationId" TEXT NOT NULL,
            "filename" TEXT NOT NULL,
            "mimeType" TEXT NOT NULL,
            "filesize" INTEGER NOT NULL,
            "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT "DestinationImage_destinationId_fkey" FOREIGN KEY ("destinationId") REFERENCES "Destination" ("id") ON DELETE CASCADE ON UPDATE CASCADE
        );
        CREATE TABLE IF NOT EXISTS "LoginAttempt" (
            "key" TEXT NOT NULL PRIMARY KEY,
            "count" INTEGER NOT NULL,
            "resetAt" INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS "Passkey" (
            "id" TEXT NOT NULL PRIMARY KEY,
            "userId" TEXT NOT NULL,
            "credentialId" TEXT NOT NULL,
            "publicKey" TEXT NOT NULL,
            "algorithm" INTEGER NOT NULL,
            "signCount" INTEGER NOT NULL DEFAULT 0,
            "name" TEXT NOT NULL,
            "transports" TEXT,
            "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "lastUsedAt" DATETIME,
            CONSTRAINT "Passkey_userId_fkey" FOREIGN KEY ("userId") REFERENCES "User" ("id") ON DELETE CASCADE ON UPDATE CASCADE
        );
        CREATE UNIQUE INDEX IF NOT EXISTS "User_username_key" ON "User"("username");
        CREATE INDEX IF NOT EXISTS "Destination_createdBy_idx" ON "Destination"("createdBy");
        CREATE INDEX IF NOT EXISTS "Destination_createdAt_idx" ON "Destination"("createdAt");
        CREATE UNIQUE INDEX IF NOT EXISTS "Tag_name_key" ON "Tag"("name");
        CREATE INDEX IF NOT EXISTS "DestinationTag_tagId_idx" ON "DestinationTag"("tagId");
        CREATE INDEX IF NOT EXISTS "DestinationImage_destinationId_idx" ON "DestinationImage"("destinationId");
        CREATE UNIQUE INDEX IF NOT EXISTS "Passkey_credentialId_key" ON "Passkey"("credentialId");
        CREATE INDEX IF NOT EXISTS "Passkey_userId_idx" ON "Passkey"("userId");
        SQL);

    // Neue Spalten nur ergänzen, wenn sie in einer bestehenden Datenbank noch fehlen.
    $userColumns = array_column($pdo->query('PRAGMA table_info("User")')->fetchAll(), 'name');
    if (!in_array('disabledAt', $userColumns, true)) {
        $pdo->exec('ALTER TABLE "User" ADD COLUMN "disabledAt" DATETIME');
    }
    if (!in_array('mustChangePassword', $userColumns, true)) {
        $pdo->exec('ALTER TABLE "User" ADD COLUMN "mustChangePassword" BOOLEAN NOT NULL DEFAULT false');
    }
    // Zwei-Faktor-Anmeldung: Geheimnis (Base32), zuletzt verbrauchtes Zeitfenster, Hashes der Wiederherstellungscodes (JSON).
    foreach (['totpSecret' => 'TEXT', 'totpLastStep' => 'INTEGER', 'totpRecoveryCodes' => 'TEXT'] as $column => $type) {
        if (!in_array($column, $userColumns, true)) {
            $pdo->exec("ALTER TABLE \"User\" ADD COLUMN \"$column\" $type");
        }
    }
    // Version 0.4.0 speicherte die 2FA-Geheimnisse im Klartext: jetzt verschlüsseln.
    if (encryption_available()) {
        $plain = $pdo->query("SELECT id, totpSecret FROM \"User\" WHERE totpSecret IS NOT NULL AND totpSecret NOT LIKE 'v1:%'")->fetchAll();
        $update = $pdo->prepare('UPDATE "User" SET totpSecret = ? WHERE id = ?');
        foreach ($plain as $row) {
            $update->execute([encrypt_secret($row['totpSecret'], $row['id']), $row['id']]);
        }
    }
}

// ---------------------------------------------------------------- Verschlüsselung der 2FA-Geheimnisse
// AES-256-GCM mit einem eigenen Schlüssel in data/secret.key, nicht in der Datenbank: Wer nur app.db in die Hände
// bekommt (Backup, Kopie, SQL-Lücke), kann damit keine Einmalcodes erzeugen. Die Benutzer-ID ist als Zusatzdaten
// gebunden, ein Geheimnis lässt sich also nicht in ein anderes Konto kopieren.

const SECRET_PREFIX = 'v1:';

function encryption_available(): bool
{
    return function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
}

function secret_key_path(): string
{
    return data_dir() . '/secret.key';
}

/** Liest den Schlüssel; beim ersten Bedarf wird er erzeugt (nur Lese- und Schreibrecht für PHP). */
function secret_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $path = secret_key_path();
    if (!is_file($path)) {
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            fwrite($handle, base64_encode(random_bytes(32)) . "\n");
            fclose($handle);
            @chmod($path, 0600);
        }
    }
    $decoded = base64_decode(trim((string) @file_get_contents($path)), true);
    if ($decoded === false || strlen($decoded) !== 32) {
        error_log("Ausflugskiste: Schlüsseldatei $path fehlt oder ist ungültig");
        throw new ApiException(500, 'SETUP_ERROR', 'Die Schlüsseldatei data/secret.key ist nicht lesbar oder beschädigt (siehe README, Abschnitt Zwei-Faktor-Anmeldung).');
    }
    return $key = $decoded;
}

function encrypt_secret(string $plain, string $userId): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag, $userId);
    if ($cipher === false) {
        throw new RuntimeException('Verschlüsselung fehlgeschlagen');
    }
    return SECRET_PREFIX . base64_encode($iv . $tag . $cipher);
}

/** Liefert null, wenn sich das Geheimnis nicht entschlüsseln lässt (z. B. Schlüsseldatei ersetzt). */
function decrypt_secret(string $stored, string $userId): ?string
{
    // Geheimnisse aus Version 0.4.0 stehen noch im Klartext (Base32), bis migrate() sie verschlüsselt.
    if (!str_starts_with($stored, SECRET_PREFIX)) {
        return $stored;
    }
    if (!encryption_available()) {
        return null;
    }
    $raw = base64_decode(substr($stored, strlen(SECRET_PREFIX)), true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $userId);
    return $plain === false ? null : $plain;
}

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return format_uuid($bytes);
}

/** 16 Bytes in der üblichen Schreibweise 8-4-4-4-12. */
function format_uuid(string $bytes): string
{
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.vP');
}

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

/** Zufällige, gut ablesbare Vierergruppen wie „k7mp-x9qr“ (ohne verwechselbare Zeichen wie l/1, o/0). */
function readable_code(int $groups): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $chunks = [];
    for ($group = 0; $group < $groups; $group++) {
        $chunk = '';
        for ($i = 0; $i < 4; $i++) {
            $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $chunks[] = $chunk;
    }
    return implode('-', $chunks);
}

/** Zufälliges Passwort wie „k7mp-x9qr-2bth-wn4e“. */
function generate_password(): string
{
    return readable_code(4);
}

function verify_password(string $password, string $hash): bool
{
    // Hashes aus der alten Node-Version beginnen mit $2b$ – identischer Algorithmus wie PHPs $2y$.
    if (str_starts_with($hash, '$2b$')) {
        $hash = '$2y$' . substr($hash, 4);
    }
    return password_verify($password, $hash);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? null) == 443;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Eigenes Verzeichnis, damit fremde Garbage-Collection auf Shared-Hosting Sessions nicht vorzeitig löscht.
    session_save_path(data_dir() . '/sessions');
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    ini_set('session.use_strict_mode', '1');
    session_name('session');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; frame-ancestors 'none'");
    header('Cache-Control: no-store');
}

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}

function fail(int $status, string $code, string $message, array $details = []): never
{
    throw new ApiException($status, $code, $message, $details);
}

function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function respond_error(ApiException $error): never
{
    http_response_code($error->status);
    header('Content-Type: application/json; charset=utf-8');
    $body = ['code' => $error->errorCode, 'message' => $error->getMessage()];
    if ($error->details) {
        $body['details'] = $error->details;
    }
    echo json_encode(['success' => false, 'error' => $body], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------- Versionsprüfung

// Der Server fragt GitHub (nicht der Browser): keine Verbindung der Nutzer zu Dritten, und dank
// Zwischenspeicher in data/release.json reicht eine Anfrage je Installation und Halbtag.
const RELEASE_API = 'https://api.github.com/repos/listiges-kaenguru/ausflugskiste/releases/latest';
const RELEASE_MAX_AGE = 12 * 3600;
const RELEASE_RETRY_AFTER = 3600; // nach einem Fehlschlag erst nach einer Stunde erneut fragen

/** Versionsnummer des neuesten Releases von GitHub oder null (kein Netz, Limit, gesperrt …) */
function fetch_latest_release(): ?string
{
    $headers = ['Accept: application/vnd.github+json', 'User-Agent: Ausflugskiste'];
    $body = false;
    $status = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init(RELEASE_API);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    } elseif (ini_get('allow_url_fopen')) {
        $context = stream_context_create(['http' => ['header' => $headers, 'timeout' => 5, 'ignore_errors' => true]]);
        $body = @file_get_contents(RELEASE_API, false, $context);
        $status = preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m) ? (int) $m[1] : 0;
    }
    if (!is_string($body) || $status !== 200) {
        return null;
    }
    $tag = json_decode($body, true)['tag_name'] ?? null;
    return is_string($tag) && preg_match('/^v?(\d+\.\d+\.\d+)$/', $tag, $m) ? $m[1] : null;
}

/** Neuestes Release aus dem Zwischenspeicher bzw. frisch von GitHub: ['version' => ?string, 'checkedAt' => ?int] */
function latest_release(): array
{
    $file = data_dir() . '/release.json';
    $cached = is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
    $version = is_string($cached['version'] ?? null) ? $cached['version'] : null;
    $checkedAt = is_int($cached['checkedAt'] ?? null) ? $cached['checkedAt'] : null;
    $triedAt = is_int($cached['triedAt'] ?? null) ? $cached['triedAt'] : 0;

    $now = time();
    $fresh = $checkedAt !== null && $now - $checkedAt < RELEASE_MAX_AGE;
    if ($fresh || $now - $triedAt < RELEASE_RETRY_AFTER) {
        return ['version' => $version, 'checkedAt' => $checkedAt];
    }

    $latest = fetch_latest_release();
    if ($latest !== null) {
        $version = $latest;
        $checkedAt = $now;
    }
    @file_put_contents($file, json_encode(['version' => $version, 'checkedAt' => $checkedAt, 'triedAt' => $now]), LOCK_EX);
    return ['version' => $version, 'checkedAt' => $checkedAt];
}
