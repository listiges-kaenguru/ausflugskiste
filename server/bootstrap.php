<?php
// Gemeinsame Grundlagen: Konfiguration, Datenbank, Session, Antworten.
// Benötigt nur PHP-Bordmittel (PDO SQLite, Sessions, JSON).

declare(strict_types=1);

const APP_VERSION = '0.1.0';
// Link zum Quelltext, den die App allen Nutzern anzeigt (AGPL-3.0, Abschnitt 13).
// Wer eine veränderte Fassung betreibt, muss hier auf deren Quelltext verweisen.
const SOURCE_URL = 'https://github.com/listiges-kaenguru/ausflugsziele';
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
        $path = getenv('AUSFLUGSZIELE_DATA_DIR') ?: dirname(__DIR__) . '/data';
        // SQLite braucht Schreibrechte auf Datei und Verzeichnis (für das Journal).
        foreach ([$path, "$path/uploads", "$path/sessions", "$path/app.db"] as $entry) {
            $isFile = str_ends_with($entry, '.db');
            if (!$isFile && !is_dir($entry)) {
                @mkdir($entry, 0770, true);
            }
            if (($isFile && file_exists($entry) && !is_writable($entry)) || (!$isFile && (!is_dir($entry) || !is_writable($entry)))) {
                data_dir_error($entry);
            }
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
    error_log("Ausflugsziele: Keine Schreibrechte für $path (PHP läuft als Benutzer $user)");
    $where = getenv('AUSFLUGSZIELE_DATA_DIR') ? 'das Datenverzeichnis (AUSFLUGSZIELE_DATA_DIR)' : 'den Ordner data/';
    throw new ApiException(500, 'SETUP_ERROR', "Der Webserver darf nicht in $where schreiben. Bitte Schreibrechte für den Webserver-Benutzer vergeben (siehe README, Abschnitt Installation).");
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        // Fehlt die Erweiterung, scheitert „new PDO“ sonst nur mit „Interner Fehler“.
        if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            error_log('Ausflugsziele: PHP-Erweiterung pdo_sqlite fehlt (php ' . PHP_VERSION . ', ' . (php_ini_loaded_file() ?: 'keine php.ini') . ')');
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
// Neue Tabellen (z. B. Passkey) kommen nur additiv hinzu.
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
}

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
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

/** Zufälliges, gut ablesbares Passwort wie „k7mp-x9qr-2bth-wn4e“ (ohne verwechselbare Zeichen wie l/1, o/0). */
function generate_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $groups = [];
    for ($group = 0; $group < 4; $group++) {
        $chunk = '';
        for ($i = 0; $i < 4; $i++) {
            $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $groups[] = $chunk;
    }
    return implode('-', $groups);
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
