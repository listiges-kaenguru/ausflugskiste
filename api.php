<?php
// JSON-API der App. Aufruf: api.php?r=<ressource>[/<id>[/<aktion>[/<unter-id>]]]

declare(strict_types=1);

// PHP-Warnungen gehören ins Fehlerprotokoll, nicht in die JSON-Antwort.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/server/bootstrap.php';
require __DIR__ . '/server/validation.php';
require __DIR__ . '/server/webauthn.php';
require __DIR__ . '/server/totp.php';

security_headers();

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $segments = array_values(array_filter(explode('/', (string) ($_GET['r'] ?? '')), 'strlen'));
    $resource = $segments[0] ?? '';
    $id = $segments[1] ?? null;
    $action = $segments[2] ?? null;
    $subId = $segments[3] ?? null;

    // Schreibende Anfragen müssen per fetch() aus der App kommen (Schutz gegen CSRF).
    if ($method !== 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
        fail(403, 'FORBIDDEN', 'Ungültige Anfrage');
    }

    start_session();

    match (true) {
        $resource === 'me' && $method === 'GET' => me(),
        $resource === 'setup' && $method === 'POST' => setup(),
        $resource === 'login' && $id === null && $method === 'POST' => login(),
        $resource === 'login' && $id === 'totp' && $method === 'POST' => login_totp(),
        $resource === 'logout' && $method === 'POST' => logout(),
        $resource === 'change-password' && $method === 'POST' => change_password(),

        $resource === 'totp' && $id === null && $method === 'GET' => totp_status(),
        $resource === 'totp' && $id === 'setup' && $method === 'POST' => totp_setup(),
        $resource === 'totp' && $id === null && $method === 'POST' => enable_totp(),
        $resource === 'totp' && $id === 'recovery-codes' && $method === 'POST' => renew_recovery_codes(),
        $resource === 'totp' && $id === null && $method === 'DELETE' => disable_totp(),

        $resource === 'passkey-login' && $id === 'options' && $method === 'POST' => passkey_login_options(),
        $resource === 'passkey-login' && $id === null && $method === 'POST' => passkey_login(),
        $resource === 'passkeys' && $id === null && $method === 'GET' => list_passkeys(),
        $resource === 'passkeys' && $id === 'options' && $method === 'POST' => passkey_register_options(),
        $resource === 'passkeys' && $id === null && $method === 'POST' => create_passkey(),
        $resource === 'passkeys' && $id !== null && $method === 'PUT' => rename_passkey($id),
        $resource === 'passkeys' && $id !== null && $method === 'DELETE' => delete_passkey($id),

        $resource === 'destinations' && $id === null && $method === 'GET' => list_destinations(),
        $resource === 'destinations' && $id === null && $method === 'POST' => create_destination(),
        $resource === 'destinations' && $id !== null && $method === 'GET' => respond(load_destination(own_destination($id))),
        $resource === 'destinations' && $id !== null && $method === 'PUT' => update_destination($id),
        $resource === 'destinations' && $id !== null && $method === 'DELETE' => delete_destination($id),

        $resource === 'tags' && $id === null && $method === 'GET' => list_tags(),
        $resource === 'tags' && $id === null && $method === 'POST' => create_tag(),
        $resource === 'tags' && $id !== null && $method === 'PUT' => update_tag($id),
        $resource === 'tags' && $id !== null && $method === 'DELETE' => delete_tag($id),

        $resource === 'images' && $id === null && $method === 'POST' => upload_image(),
        $resource === 'images' && $id !== null && $action === 'file' && $method === 'GET' => send_image($id),
        $resource === 'images' && $id !== null && $action === null && $method === 'DELETE' => delete_image($id),

        $resource === 'users' && $id === null && $method === 'GET' => list_users(),
        $resource === 'users' && $id === null && $method === 'POST' => create_user(),
        $resource === 'users' && $id !== null && $action === null && $method === 'PUT' => update_user($id),
        $resource === 'users' && $id !== null && $action === null && $method === 'DELETE' => delete_user($id),
        $resource === 'users' && $id !== null && $action === 'password' && $method === 'POST' => reset_user_password($id),
        $resource === 'users' && $id !== null && $action === 'passkeys' && $subId === null && $method === 'GET' => list_user_passkeys($id),
        $resource === 'users' && $id !== null && $action === 'passkeys' && $subId !== null && $method === 'DELETE' => delete_user_passkey($id, $subId),
        $resource === 'users' && $id !== null && $action === 'totp' && $method === 'DELETE' => reset_user_totp($id),

        default => fail(404, 'NOT_FOUND', 'Unbekannter Endpunkt'),
    };
} catch (ApiException $error) {
    respond_error($error);
} catch (Throwable $error) {
    error_log('Ausflugskiste API: ' . $error);
    respond_error(new ApiException(500, 'INTERNAL_ERROR', 'Interner Fehler'));
}

// ---------------------------------------------------------------- Auth

function current_user(): ?array
{
    $userId = $_SESSION['userId'] ?? null;
    if (!is_string($userId)) {
        return null;
    }
    // Gesperrte Benutzer gelten als abgemeldet – bestehende Sessions enden damit sofort.
    $stmt = db()->prepare('SELECT id, username, role, createdAt, mustChangePassword, totpSecret IS NOT NULL AS totpEnabled FROM "User" WHERE id = ? AND disabledAt IS NULL');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return $user ? ['mustChangePassword' => (bool) $user['mustChangePassword'], 'totpEnabled' => (bool) $user['totpEnabled']] + $user : null;
}

/** Solange ein vom Admin vergebenes Passwort nicht geändert wurde, ist nur der Passwortwechsel erlaubt. */
function require_user(bool $allowPendingPasswordChange = false): array
{
    $user = current_user() ?? fail(401, 'UNAUTHORIZED', 'Nicht angemeldet');
    if ($user['mustChangePassword'] && !$allowPendingPasswordChange) {
        fail(403, 'PASSWORD_CHANGE_REQUIRED', 'Bitte lege zuerst ein eigenes Passwort fest');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_user();
    if ($user['role'] !== 'ADMIN') {
        fail(403, 'FORBIDDEN', 'Nur für Administratoren');
    }
    return $user;
}

function needs_setup(): bool
{
    return (int) db()->query('SELECT COUNT(*) FROM "User"')->fetchColumn() === 0;
}

function sign_in(array $user): void
{
    session_regenerate_id(true);
    unset($_SESSION['totpLogin']);
    $_SESSION['userId'] = $user['id'];
}

function me(): never
{
    respond([
        'user' => current_user(),
        'needsSetup' => needs_setup(),
        'passkeys' => passkeys_available(),
        'version' => APP_VERSION,
        'sourceUrl' => SOURCE_URL,
    ]);
}

// Legt beim ersten Aufruf den Admin-Zugang an (ersetzt die festen Seed-Zugangsdaten).
function setup(): never
{
    $input = validate_credentials(json_body());
    db()->beginTransaction();
    if (!needs_setup()) {
        db()->rollBack();
        fail(403, 'FORBIDDEN', 'Die Einrichtung ist bereits abgeschlossen');
    }
    $user = insert_user($input['username'], $input['password'], 'ADMIN');
    db()->commit();
    sign_in($user);
    respond(['user' => $user], 201);
}

function login(): never
{
    $key = 'login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (is_rate_limited($key)) {
        fail(429, 'RATE_LIMITED', 'Zu viele Anmeldeversuche. Bitte später erneut versuchen.');
    }

    $input = validate_credentials(json_body());
    $stmt = db()->prepare('SELECT * FROM "User" WHERE username = ?');
    $stmt->execute([$input['username']]);
    $user = $stmt->fetch();

    if (!$user || !verify_password($input['password'], $user['passwordHash'])) {
        fail(401, 'INVALID_CREDENTIALS', 'Benutzername oder Passwort ist falsch');
    }
    assert_not_disabled($user);

    // Mit Zwei-Faktor-Anmeldung gilt das Passwort erst zusammen mit dem Code aus der App.
    if ($user['totpSecret'] !== null) {
        session_regenerate_id(true);
        unset($_SESSION['userId']);
        $_SESSION['totpLogin'] = ['userId' => $user['id'], 'expires' => time() + TOTP_LOGIN_TIMEOUT];
        respond(['totpRequired' => true]);
    }

    sign_in($user);
    respond(['user' => session_user_json($user)]);
}

/** Zweiter Schritt der Anmeldung: Code aus der Authenticator-App oder ein Wiederherstellungscode. */
function login_totp(): never
{
    $pending = $_SESSION['totpLogin'] ?? null;
    if (!is_array($pending) || $pending['expires'] < time()) {
        unset($_SESSION['totpLogin']);
        fail(401, 'TOTP_EXPIRED', 'Die Anmeldung hat zu lange gedauert. Bitte erneut mit Passwort anmelden.');
    }
    $stmt = db()->prepare('SELECT * FROM "User" WHERE id = ?');
    $stmt->execute([$pending['userId']]);
    $user = $stmt->fetch();
    if (!$user || $user['totpSecret'] === null) {
        unset($_SESSION['totpLogin']);
        fail(401, 'TOTP_EXPIRED', 'Bitte erneut mit Passwort anmelden.');
    }
    assert_not_disabled($user);

    $usedRecoveryCode = check_second_factor($user, string_field(json_body(), 'code') ?? '', 401);
    $user = session_user_json($user);
    sign_in($user);
    respond(['user' => $user, 'recoveryCodesLeft' => $usedRecoveryCode ? recovery_codes_left($pending['userId']) : null]);
}

/**
 * Prüft einen TOTP- oder Wiederherstellungscode und verbraucht ihn. Liefert true, wenn es ein
 * Wiederherstellungscode war. Höchstens 5 Versuche in 5 Minuten je Benutzer, damit sich der
 * sechsstellige Code nicht durchprobieren lässt.
 */
function check_second_factor(array $user, string $code, int $failStatus = 400): bool
{
    if (is_rate_limited('totp:' . $user['id'], 5, 300)) {
        fail(429, 'RATE_LIMITED', 'Zu viele falsche Codes. Bitte in ein paar Minuten erneut versuchen.');
    }
    $code = trim($code);
    $digits = (string) preg_replace('/\s+/', '', $code);
    $step = totp_verify($user['totpSecret'], $digits, $user['totpLastStep'] === null ? null : (int) $user['totpLastStep']);
    if ($step !== null) {
        db()->prepare('UPDATE "User" SET totpLastStep = ? WHERE id = ?')->execute([$step, $user['id']]);
        db()->prepare('DELETE FROM "LoginAttempt" WHERE key = ?')->execute(['totp:' . $user['id']]);
        return false;
    }

    $hashes = json_decode((string) $user['totpRecoveryCodes'], true) ?: [];
    $index = $code === '' ? false : array_search(totp_recovery_hash($code), $hashes, true);
    if ($index !== false) {
        array_splice($hashes, $index, 1);
        db()->prepare('UPDATE "User" SET totpRecoveryCodes = ? WHERE id = ?')->execute([json_encode($hashes), $user['id']]);
        db()->prepare('DELETE FROM "LoginAttempt" WHERE key = ?')->execute(['totp:' . $user['id']]);
        return true;
    }
    fail($failStatus, 'INVALID_CODE', 'Der Code ist falsch oder abgelaufen');
}

function recovery_codes_left(string $userId): int
{
    $stmt = db()->prepare('SELECT totpRecoveryCodes FROM "User" WHERE id = ?');
    $stmt->execute([$userId]);
    return count(json_decode((string) $stmt->fetchColumn(), true) ?: []);
}

function session_user_json(array $user): array
{
    return [
        'id' => $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'mustChangePassword' => (bool) $user['mustChangePassword'],
        'totpEnabled' => ($user['totpSecret'] ?? null) !== null,
    ];
}

function assert_not_disabled(array $user): void
{
    if ($user['disabledAt'] !== null) {
        fail(403, 'ACCOUNT_DISABLED', 'Dieser Zugang ist gesperrt. Bitte wende dich an einen Administrator.');
    }
}

function is_rate_limited(string $key, int $limit = 5, int $windowSeconds = 60): bool
{
    $now = time();
    $stmt = db()->prepare('SELECT count, resetAt FROM "LoginAttempt" WHERE key = ?');
    $stmt->execute([$key]);
    $entry = $stmt->fetch();

    if (!$entry || $entry['resetAt'] <= $now) {
        db()->prepare('INSERT OR REPLACE INTO "LoginAttempt" (key, count, resetAt) VALUES (?, 1, ?)')
            ->execute([$key, $now + $windowSeconds]);
        db()->prepare('DELETE FROM "LoginAttempt" WHERE resetAt <= ?')->execute([$now]);
        return false;
    }
    if ($entry['count'] >= $limit) {
        return true;
    }
    db()->prepare('UPDATE "LoginAttempt" SET count = count + 1 WHERE key = ?')->execute([$key]);
    return false;
}

function logout(): never
{
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => 1,
        'path' => $params['path'],
        'secure' => $params['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_destroy();
    respond(['ok' => true]);
}

/** Nach einem vom Admin vergebenen Passwort entfällt die Abfrage des aktuellen Passworts. */
function change_password(): never
{
    $user = require_user(true);
    $input = validate_change_password(json_body(), !$user['mustChangePassword']);

    $stmt = db()->prepare('SELECT passwordHash FROM "User" WHERE id = ?');
    $stmt->execute([$user['id']]);
    $hash = (string) $stmt->fetchColumn();
    if (!$user['mustChangePassword'] && !verify_password($input['currentPassword'], $hash)) {
        fail(401, 'INVALID_CREDENTIALS', 'Aktuelles Passwort ist falsch');
    }
    if (verify_password($input['newPassword'], $hash)) {
        validation_failed(['newPassword' => 'Das neue Passwort muss sich vom bisherigen unterscheiden']);
    }

    db()->prepare('UPDATE "User" SET passwordHash = ?, mustChangePassword = 0, updatedAt = ? WHERE id = ?')
        ->execute([hash_password($input['newPassword']), now(), $user['id']]);
    respond(['ok' => true]);
}

// ---------------------------------------------------------------- Passkeys

function require_passkeys(): void
{
    if (!passkeys_available()) {
        fail(501, 'NOT_AVAILABLE', 'Passkeys sind auf diesem Server nicht verfügbar (PHP-Erweiterung openssl fehlt)');
    }
}

function passkey_json(array $row): array
{
    return [
        'id' => $row['id'],
        'name' => $row['name'],
        'createdAt' => $row['createdAt'],
        'lastUsedAt' => $row['lastUsedAt'],
    ];
}

function list_passkeys(): never
{
    $user = require_user();
    $stmt = db()->prepare('SELECT * FROM "Passkey" WHERE userId = ? ORDER BY createdAt');
    $stmt->execute([$user['id']]);
    respond(array_map('passkey_json', $stmt->fetchAll()));
}

/** Optionen für navigator.credentials.create(), Binärwerte als Base64URL. */
function passkey_register_options(): never
{
    require_passkeys();
    $user = require_user();
    $stmt = db()->prepare('SELECT credentialId, transports FROM "Passkey" WHERE userId = ?');
    $stmt->execute([$user['id']]);
    $exclude = array_map(fn (array $row) => [
        'type' => 'public-key',
        'id' => $row['credentialId'],
        'transports' => json_decode((string) $row['transports'], true) ?: [],
    ], $stmt->fetchAll());

    respond([
        'challenge' => webauthn_challenge('register'),
        'rp' => ['id' => webauthn_rp_id(), 'name' => 'Ausflugskiste'],
        'user' => ['id' => base64url_encode($user['id']), 'name' => $user['username'], 'displayName' => $user['username']],
        'pubKeyCredParams' => [
            ['type' => 'public-key', 'alg' => COSE_ALG_ES256],
            ['type' => 'public-key', 'alg' => COSE_ALG_RS256],
        ],
        'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
        'excludeCredentials' => $exclude,
        'attestation' => 'none',
        'timeout' => WEBAUTHN_TIMEOUT_MS,
    ]);
}

function create_passkey(): never
{
    require_passkeys();
    $user = require_user();
    $body = json_body();
    $credential = webauthn_verify_registration($body);
    // Erkannter Passwortmanager vor dem Gerätenamen, den der Browser vorschlägt.
    $fallback = trim(string_field($body, 'fallbackName') ?? '');
    $name = $credential['provider'] ?? ($fallback !== '' && text_length($fallback) <= 60 ? $fallback : 'Passkey');
    $transports = array_values(array_filter((array) ($body['transports'] ?? []), fn ($t) => is_string($t) && strlen($t) <= 32));

    $exists = db()->prepare('SELECT 1 FROM "Passkey" WHERE credentialId = ?');
    $exists->execute([$credential['credentialId']]);
    if ($exists->fetchColumn()) {
        fail(409, 'CONFLICT', 'Dieser Passkey ist bereits gespeichert');
    }

    $row = [
        'id' => uuid(),
        'name' => $name,
        'createdAt' => now(),
        'lastUsedAt' => null,
    ];
    db()->prepare('INSERT INTO "Passkey" (id, userId, credentialId, publicKey, algorithm, signCount, name, transports, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$row['id'], $user['id'], $credential['credentialId'], $credential['publicKey'], $credential['algorithm'], $credential['signCount'], $name, json_encode($transports), $row['createdAt']]);
    respond(passkey_json($row), 201);
}

function own_passkey(string $id): array
{
    $user = require_user();
    $stmt = db()->prepare('SELECT * FROM "Passkey" WHERE id = ? AND userId = ?');
    $stmt->execute([$id, $user['id']]);
    return $stmt->fetch() ?: fail(404, 'NOT_FOUND', 'Passkey nicht gefunden');
}

function rename_passkey(string $id): never
{
    $passkey = own_passkey($id);
    $passkey['name'] = validate_passkey_name(json_body());
    db()->prepare('UPDATE "Passkey" SET name = ? WHERE id = ?')->execute([$passkey['name'], $id]);
    respond(passkey_json($passkey));
}

function delete_passkey(string $id): never
{
    $passkey = own_passkey($id);
    db()->prepare('DELETE FROM "Passkey" WHERE id = ?')->execute([$id]);
    respond(['ok' => true, 'credentialId' => $passkey['credentialId']]);
}

/** Optionen für navigator.credentials.get(): ohne allowCredentials wählt der Browser den Passkey. */
function passkey_login_options(): never
{
    require_passkeys();
    respond([
        'challenge' => webauthn_challenge('login'),
        'rpId' => webauthn_rp_id(),
        'userVerification' => 'required',
        'timeout' => WEBAUTHN_TIMEOUT_MS,
    ]);
}

function passkey_login(): never
{
    require_passkeys();
    if (is_rate_limited('passkey:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10)) {
        fail(429, 'RATE_LIMITED', 'Zu viele Anmeldeversuche. Bitte später erneut versuchen.');
    }
    $body = json_body();
    $stmt = db()->prepare('SELECT p.*, u.username, u.role, u.disabledAt, u.mustChangePassword, u.totpSecret FROM "Passkey" p JOIN "User" u ON u.id = p.userId WHERE p.credentialId = ?');
    $stmt->execute([(string) ($body['id'] ?? '')]);
    $passkey = $stmt->fetch();
    if (!$passkey) {
        unset($_SESSION['webauthn']);
        fail(401, 'UNKNOWN_PASSKEY', 'Dieser Passkey ist hier nicht (mehr) registriert. Bitte mit Passwort anmelden.');
    }
    $userHandle = $body['userHandle'] ?? null;
    if ($userHandle !== null && $userHandle !== '' && base64url_decode($userHandle) !== $passkey['userId']) {
        fail(401, 'INVALID_CREDENTIALS', 'Passkey konnte nicht bestätigt werden');
    }

    $signCount = webauthn_verify_assertion($body, $passkey);
    assert_not_disabled($passkey);
    db()->prepare('UPDATE "Passkey" SET signCount = ?, lastUsedAt = ? WHERE id = ?')
        ->execute([$signCount, now(), $passkey['id']]);

    $user = session_user_json(['id' => $passkey['userId']] + $passkey);
    sign_in($user);
    respond(['user' => $user]);
}

// ---------------------------------------------------------------- Zwei-Faktor-Anmeldung (TOTP)

/** Eigener Benutzer samt TOTP-Spalten. */
function totp_user(): array
{
    $user = require_user();
    $stmt = db()->prepare('SELECT id, username, totpSecret, totpLastStep, totpRecoveryCodes FROM "User" WHERE id = ?');
    $stmt->execute([$user['id']]);
    return $stmt->fetch();
}

function totp_status(): never
{
    $user = totp_user();
    respond([
        'enabled' => $user['totpSecret'] !== null,
        'recoveryCodesLeft' => $user['totpSecret'] === null ? 0 : recovery_codes_left($user['id']),
    ]);
}

/** Erzeugt ein neues Geheimnis; aktiv wird es erst, wenn ein passender Code bestätigt wurde. */
function totp_setup(): never
{
    $user = totp_user();
    if ($user['totpSecret'] !== null) {
        fail(409, 'CONFLICT', 'Die Zwei-Faktor-Anmeldung ist bereits eingerichtet');
    }
    $secret = totp_generate_secret();
    $_SESSION['totpSetup'] = ['secret' => $secret, 'expires' => time() + 900];
    respond(['secret' => $secret, 'uri' => totp_uri($secret, $user['username'])]);
}

function enable_totp(): never
{
    $user = totp_user();
    $pending = $_SESSION['totpSetup'] ?? null;
    if ($user['totpSecret'] !== null) {
        fail(409, 'CONFLICT', 'Die Zwei-Faktor-Anmeldung ist bereits eingerichtet');
    }
    if (!is_array($pending) || $pending['expires'] < time()) {
        fail(400, 'TOTP_EXPIRED', 'Die Einrichtung ist abgelaufen. Bitte neu beginnen.');
    }
    if (is_rate_limited('totp:' . $user['id'], 5, 300)) {
        fail(429, 'RATE_LIMITED', 'Zu viele falsche Codes. Bitte in ein paar Minuten erneut versuchen.');
    }
    $step = totp_verify($pending['secret'], (string) preg_replace('/\s+/', '', string_field(json_body(), 'code') ?? ''));
    if ($step === null) {
        fail(400, 'INVALID_CODE', 'Der Code passt nicht. Stimmt die Uhrzeit auf dem Handy?');
    }

    $codes = totp_new_recovery_codes();
    db()->prepare('UPDATE "User" SET totpSecret = ?, totpLastStep = ?, totpRecoveryCodes = ?, updatedAt = ? WHERE id = ?')
        ->execute([$pending['secret'], $step, json_encode(array_map('totp_recovery_hash', $codes)), now(), $user['id']]);
    db()->prepare('DELETE FROM "LoginAttempt" WHERE key = ?')->execute(['totp:' . $user['id']]);
    unset($_SESSION['totpSetup']);
    respond(['recoveryCodes' => $codes]);
}

/** Ersetzt alle Wiederherstellungscodes, z. B. wenn sie aufgebraucht oder verloren sind. */
function renew_recovery_codes(): never
{
    $user = totp_user();
    if ($user['totpSecret'] === null) {
        fail(400, 'VALIDATION_ERROR', 'Die Zwei-Faktor-Anmeldung ist nicht eingerichtet');
    }
    check_second_factor($user, string_field(json_body(), 'code') ?? '');
    $codes = totp_new_recovery_codes();
    db()->prepare('UPDATE "User" SET totpRecoveryCodes = ? WHERE id = ?')
        ->execute([json_encode(array_map('totp_recovery_hash', $codes)), $user['id']]);
    respond(['recoveryCodes' => $codes]);
}

function disable_totp(): never
{
    $user = totp_user();
    if ($user['totpSecret'] === null) {
        respond(['ok' => true]);
    }
    check_second_factor($user, string_field(json_body(), 'code') ?? '');
    clear_totp($user['id']);
    respond(['ok' => true]);
}

function clear_totp(string $userId): void
{
    db()->prepare('UPDATE "User" SET totpSecret = NULL, totpLastStep = NULL, totpRecoveryCodes = NULL, updatedAt = ? WHERE id = ?')
        ->execute([now(), $userId]);
}

// ---------------------------------------------------------------- Benutzer

/** Mit `$mustChangePassword` muss der Benutzer das Passwort nach der ersten Anmeldung ändern. */
function insert_user(string $username, string $password, string $role, bool $mustChangePassword = false): array
{
    $exists = db()->prepare('SELECT 1 FROM "User" WHERE username = ?');
    $exists->execute([$username]);
    if ($exists->fetchColumn()) {
        fail(409, 'CONFLICT', 'Benutzername ist bereits vergeben');
    }
    $user = ['id' => uuid(), 'username' => $username, 'role' => $role, 'createdAt' => now(), 'mustChangePassword' => $mustChangePassword];
    db()->prepare('INSERT INTO "User" (id, username, passwordHash, role, createdAt, updatedAt, mustChangePassword) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$user['id'], $username, hash_password($password), $role, $user['createdAt'], $user['createdAt'], (int) $mustChangePassword]);
    return $user;
}

function user_json(array $row): array
{
    return [
        'id' => $row['id'],
        'username' => $row['username'],
        'role' => $row['role'],
        'createdAt' => $row['createdAt'],
        'disabled' => $row['disabledAt'] !== null,
        'disabledAt' => $row['disabledAt'],
        'mustChangePassword' => (bool) $row['mustChangePassword'],
        'destinationCount' => (int) ($row['destinationCount'] ?? 0),
        'passkeyCount' => (int) ($row['passkeyCount'] ?? 0),
        'totpEnabled' => (bool) ($row['totpEnabled'] ?? false),
    ];
}

function user_query(string $where = ''): string
{
    return <<<SQL
        SELECT u.id, u.username, u.role, u.createdAt, u.disabledAt, u.mustChangePassword, u.totpSecret IS NOT NULL AS totpEnabled,
            (SELECT COUNT(*) FROM "Destination" d WHERE d.createdBy = u.id AND d.deletedAt IS NULL) AS destinationCount,
            (SELECT COUNT(*) FROM "Passkey" p WHERE p.userId = u.id) AS passkeyCount
        FROM "User" u $where
        SQL;
}

function list_users(): never
{
    require_admin();
    respond(array_map('user_json', db()->query(user_query('ORDER BY u.username'))->fetchAll()));
}

/**
 * Ohne Passwort im Body erzeugt der Server eines und gibt es einmalig zurück.
 * Da der Admin das Passwort kennt, muss der neue Benutzer es nach der ersten Anmeldung ändern.
 */
function create_user(): never
{
    require_admin();
    $body = json_body();
    $role = ($body['role'] ?? 'USER') === 'ADMIN' ? 'ADMIN' : 'USER';
    if (($body['password'] ?? '') === '') {
        $password = generate_password();
        $user = insert_user(validate_username($body), $password, $role, true);
        respond(['password' => $password] + user_json($user + ['disabledAt' => null]), 201);
    }
    $input = validate_credentials($body);
    respond(user_json(insert_user($input['username'], $input['password'], $role, true) + ['disabledAt' => null]), 201);
}

/** Lädt einen anderen Benutzer – Admins können sich nicht selbst sperren, löschen oder zurücksetzen. */
function other_user(string $id): array
{
    $admin = require_admin();
    if ($id === $admin['id']) {
        fail(400, 'VALIDATION_ERROR', 'Das eigene Konto kann hier nicht geändert werden');
    }
    $stmt = db()->prepare(user_query('WHERE u.id = ?'));
    $stmt->execute([$id]);
    return $stmt->fetch() ?: fail(404, 'NOT_FOUND', 'Benutzer nicht gefunden');
}

function update_user(string $id): never
{
    $user = other_user($id);
    $disabled = json_body()['disabled'] ?? null;
    if (!is_bool($disabled)) {
        validation_failed(['disabled' => 'Ungültiger Wert']);
    }
    $user['disabledAt'] = $disabled ? ($user['disabledAt'] ?? now()) : null;
    db()->prepare('UPDATE "User" SET disabledAt = ?, updatedAt = ? WHERE id = ?')->execute([$user['disabledAt'], now(), $id]);
    respond(user_json($user));
}

function reset_user_password(string $id): never
{
    other_user($id);
    $password = generate_password();
    db()->prepare('UPDATE "User" SET passwordHash = ?, mustChangePassword = 1, updatedAt = ? WHERE id = ?')->execute([hash_password($password), now(), $id]);
    respond(['password' => $password]);
}

/** Passkeys eines anderen Benutzers, z. B. um nach einem verlorenen Gerät einen davon zu entfernen. */
function list_user_passkeys(string $id): never
{
    other_user($id);
    $stmt = db()->prepare('SELECT * FROM "Passkey" WHERE userId = ? ORDER BY createdAt');
    $stmt->execute([$id]);
    respond(array_map('passkey_json', $stmt->fetchAll()));
}

function delete_user_passkey(string $id, string $passkeyId): never
{
    other_user($id);
    $stmt = db()->prepare('DELETE FROM "Passkey" WHERE id = ? AND userId = ?');
    $stmt->execute([$passkeyId, $id]);
    if ($stmt->rowCount() === 0) {
        fail(404, 'NOT_FOUND', 'Passkey nicht gefunden');
    }
    respond(['ok' => true]);
}

/** Schaltet die Zwei-Faktor-Anmeldung eines anderen Benutzers ab, z. B. nach einem verlorenen Handy. */
function reset_user_totp(string $id): never
{
    other_user($id);
    clear_totp($id);
    respond(['ok' => true]);
}

/** Löscht den Benutzer samt Zielen, Fotos und Passkeys (Fremdschlüssel mit ON DELETE CASCADE). */
function delete_user(string $id): never
{
    other_user($id);
    $stmt = db()->prepare('SELECT i.filename FROM "DestinationImage" i JOIN "Destination" d ON d.id = i.destinationId WHERE d.createdBy = ?');
    $stmt->execute([$id]);
    $files = $stmt->fetchAll(PDO::FETCH_COLUMN);

    db()->prepare('DELETE FROM "User" WHERE id = ?')->execute([$id]);
    foreach ($files as $filename) {
        @unlink(image_path(['filename' => $filename]));
    }
    respond(['ok' => true]);
}

// ---------------------------------------------------------------- Ziele

function own_destination(string $id): array
{
    $user = require_user();
    $stmt = db()->prepare('SELECT * FROM "Destination" WHERE id = ? AND createdBy = ? AND deletedAt IS NULL');
    $stmt->execute([$id, $user['id']]);
    return $stmt->fetch() ?: fail(404, 'NOT_FOUND', 'Ziel nicht gefunden');
}

/** Ergänzt Tags und Bilder und wandelt SQLite-Werte in passende JSON-Typen. */
function load_destination(array $row): array
{
    return load_destinations([$row])[0];
}

function load_destinations(array $rows): array
{
    if (!$rows) {
        return [];
    }
    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $tags = [];
    $stmt = db()->prepare("SELECT dt.destinationId, t.id, t.name FROM \"DestinationTag\" dt JOIN \"Tag\" t ON t.id = dt.tagId WHERE dt.destinationId IN ($placeholders) ORDER BY t.name");
    $stmt->execute($ids);
    foreach ($stmt as $tag) {
        $tags[$tag['destinationId']][] = ['id' => $tag['id'], 'name' => $tag['name']];
    }

    $images = [];
    $stmt = db()->prepare("SELECT id, destinationId, mimeType, filesize, createdAt FROM \"DestinationImage\" WHERE destinationId IN ($placeholders) ORDER BY createdAt");
    $stmt->execute($ids);
    foreach ($stmt as $image) {
        $image['filesize'] = (int) $image['filesize'];
        $image['url'] = 'api.php?r=images/' . rawurlencode($image['id']) . '/file';
        $images[$image['destinationId']][] = $image;
    }

    return array_map(fn (array $row) => [
        'id' => $row['id'],
        'name' => $row['name'],
        'address' => $row['address'],
        'latitude' => $row['latitude'] === null ? null : (float) $row['latitude'],
        'longitude' => $row['longitude'] === null ? null : (float) $row['longitude'],
        'googleMapsLink' => $row['googleMapsLink'],
        'description' => $row['description'],
        'rating' => $row['rating'] === null ? null : (int) $row['rating'],
        'favorite' => (bool) $row['favorite'],
        'visited' => (bool) $row['visited'],
        'privateNotes' => $row['privateNotes'],
        'createdAt' => $row['createdAt'],
        'updatedAt' => $row['updatedAt'],
        'tags' => $tags[$row['id']] ?? [],
        'images' => $images[$row['id']] ?? [],
    ], $rows);
}

function list_destinations(): never
{
    $user = require_user();
    $sql = 'SELECT * FROM "Destination" WHERE createdBy = ? AND deletedAt IS NULL';
    $params = [$user['id']];

    foreach (['favorite', 'visited'] as $flag) {
        $value = $_GET[$flag] ?? null;
        if ($value === 'true' || $value === 'false') {
            $sql .= " AND $flag = ?";
            $params[] = $value === 'true' ? 1 : 0;
        }
    }

    $minRating = $_GET['minRating'] ?? '';
    if ($minRating !== '') {
        if (!is_string($minRating) || filter_var($minRating, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]) === false) {
            fail(400, 'VALIDATION_ERROR', 'Mindestbewertung muss zwischen 1 und 5 liegen');
        }
        $sql .= ' AND rating >= ?';
        $params[] = (int) $minRating;
    }

    $stmt = db()->prepare($sql . ' ORDER BY createdAt DESC');
    $stmt->execute($params);
    $destinations = load_destinations($stmt->fetchAll());

    // Textsuche in PHP statt per SQL-LIKE, damit auch Umlaute unabhängig von Groß-/Kleinschreibung gefunden werden.
    $search = lower(trim((string) ($_GET['search'] ?? '')));
    if ($search !== '') {
        $destinations = array_filter($destinations, function (array $destination) use ($search) {
            foreach (['name', 'address', 'description'] as $field) {
                if ($destination[$field] !== null && str_contains(lower($destination[$field]), $search)) {
                    return true;
                }
            }
            return false;
        });
    }

    // tag=Name oder mehrfach tag[]=Name: Treffer müssen alle angegebenen Tags haben.
    $tags = array_filter((array) ($_GET['tag'] ?? []), fn ($tag) => is_string($tag) && $tag !== '');
    if ($tags) {
        $destinations = array_filter(
            $destinations,
            fn (array $destination) => !array_diff($tags, array_column($destination['tags'], 'name')),
        );
    }

    respond(array_values($destinations));
}

function lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function create_destination(): never
{
    $user = require_user();
    $input = validate_destination(json_body());
    $id = uuid();
    $timestamp = now();

    db()->beginTransaction();
    db()->prepare('INSERT INTO "Destination" (id, name, address, googleMapsLink, description, rating, favorite, visited, privateNotes, createdAt, updatedAt, createdBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $input['name'], $input['address'], $input['googleMapsLink'], $input['description'], $input['rating'], (int) $input['favorite'], (int) $input['visited'], $input['privateNotes'], $timestamp, $timestamp, $user['id']]);
    set_destination_tags($id, $input['tagIds'] ?? []);
    db()->commit();

    respond(load_destination(own_destination($id)), 201);
}

function update_destination(string $id): never
{
    own_destination($id);
    $input = validate_destination(json_body());

    db()->beginTransaction();
    db()->prepare('UPDATE "Destination" SET name = ?, address = ?, googleMapsLink = ?, description = ?, rating = ?, favorite = ?, visited = ?, privateNotes = ?, updatedAt = ? WHERE id = ?')
        ->execute([$input['name'], $input['address'], $input['googleMapsLink'], $input['description'], $input['rating'], (int) $input['favorite'], (int) $input['visited'], $input['privateNotes'], now(), $id]);
    if ($input['tagIds'] !== null) {
        set_destination_tags($id, $input['tagIds']);
    }
    db()->commit();

    respond(load_destination(own_destination($id)));
}

function set_destination_tags(string $destinationId, array $tagIds): void
{
    db()->prepare('DELETE FROM "DestinationTag" WHERE destinationId = ?')->execute([$destinationId]);
    $insert = db()->prepare('INSERT OR IGNORE INTO "DestinationTag" (destinationId, tagId) SELECT ?, id FROM "Tag" WHERE id = ?');
    foreach (array_unique($tagIds) as $tagId) {
        $insert->execute([$destinationId, $tagId]);
    }
}

function delete_destination(string $id): never
{
    own_destination($id);
    db()->prepare('UPDATE "Destination" SET deletedAt = ?, updatedAt = ? WHERE id = ?')->execute([now(), now(), $id]);
    respond(['ok' => true]);
}

// ---------------------------------------------------------------- Tags

function list_tags(): never
{
    require_user();
    respond(db()->query('SELECT id, name FROM "Tag" ORDER BY name')->fetchAll());
}

function assert_tag_name_free(string $name, ?string $exceptId = null): void
{
    $stmt = db()->prepare('SELECT id FROM "Tag" WHERE name = ?');
    $stmt->execute([$name]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false && $existing !== $exceptId) {
        fail(409, 'CONFLICT', 'Diesen Tag gibt es bereits');
    }
}

function create_tag(): never
{
    require_user();
    $name = validate_tag(json_body());
    assert_tag_name_free($name);
    $tag = ['id' => uuid(), 'name' => $name];
    db()->prepare('INSERT INTO "Tag" (id, name, createdAt, updatedAt) VALUES (?, ?, ?, ?)')
        ->execute([$tag['id'], $name, now(), now()]);
    respond($tag, 201);
}

function update_tag(string $id): never
{
    require_admin();
    $name = validate_tag(json_body());
    assert_tag_name_free($name, $id);
    $stmt = db()->prepare('UPDATE "Tag" SET name = ?, updatedAt = ? WHERE id = ?');
    $stmt->execute([$name, now(), $id]);
    if ($stmt->rowCount() === 0) {
        fail(404, 'NOT_FOUND', 'Tag nicht gefunden');
    }
    respond(['id' => $id, 'name' => $name]);
}

function delete_tag(string $id): never
{
    require_admin();
    $stmt = db()->prepare('DELETE FROM "Tag" WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        fail(404, 'NOT_FOUND', 'Tag nicht gefunden');
    }
    respond(['ok' => true]);
}

// ---------------------------------------------------------------- Bilder

function upload_image(): never
{
    // Überschreitet die Anfrage post_max_size, verwirft PHP $_POST und $_FILES komplett.
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        fail(400, 'VALIDATION_ERROR', 'Das Bild ist zu groß für die Server-Einstellungen (post_max_size)');
    }
    $destination = own_destination((string) ($_POST['destinationId'] ?? ''));
    $file = $_FILES['file'] ?? null;

    if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        fail(400, 'VALIDATION_ERROR', 'Datei oder Ziel fehlt');
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > MAX_IMAGE_BYTES) {
        fail(400, 'VALIDATION_ERROR', 'Das Bild ist zu groß (maximal ' . (MAX_IMAGE_BYTES / 1024 / 1024) . ' MB)');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        fail(400, 'VALIDATION_ERROR', 'Upload fehlgeschlagen');
    }

    $info = @getimagesize($file['tmp_name']);
    $type = $info ? (ALLOWED_IMAGE_TYPES[$info[2]] ?? null) : null;
    if ($type === null) {
        fail(400, 'VALIDATION_ERROR', 'Nur JPEG, PNG, GIF und WebP werden unterstützt');
    }

    $image = [
        'id' => uuid(),
        'destinationId' => $destination['id'],
        'mimeType' => $type[0],
        'filesize' => (int) $file['size'],
        'createdAt' => now(),
    ];
    $filename = $image['id'] . '.' . $type[1];
    if (!move_uploaded_file($file['tmp_name'], data_dir() . '/uploads/' . $filename)) {
        throw new RuntimeException('Bild konnte nicht gespeichert werden');
    }

    db()->prepare('INSERT INTO "DestinationImage" (id, destinationId, filename, mimeType, filesize, createdAt) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$image['id'], $image['destinationId'], $filename, $image['mimeType'], $image['filesize'], $image['createdAt']]);

    $image['url'] = 'api.php?r=images/' . $image['id'] . '/file';
    respond($image, 201);
}

function own_image(string $id): array
{
    $user = require_user();
    $stmt = db()->prepare('SELECT i.* FROM "DestinationImage" i JOIN "Destination" d ON d.id = i.destinationId WHERE i.id = ? AND d.createdBy = ?');
    $stmt->execute([$id, $user['id']]);
    return $stmt->fetch() ?: fail(404, 'NOT_FOUND', 'Bild nicht gefunden');
}

function image_path(array $image): string
{
    return data_dir() . '/uploads/' . basename($image['filename']);
}

function send_image(string $id): never
{
    $path = image_path(own_image($id));
    if (!is_file($path)) {
        fail(404, 'NOT_FOUND', 'Bild nicht gefunden');
    }
    session_write_close();
    header_remove('Cache-Control');
    header('Cache-Control: private, max-age=86400');
    header('Content-Type: ' . own_image_mime($path));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function own_image_mime(string $path): string
{
    $info = @getimagesize($path);
    return $info ? (ALLOWED_IMAGE_TYPES[$info[2]][0] ?? 'application/octet-stream') : 'application/octet-stream';
}

function delete_image(string $id): never
{
    $image = own_image($id);
    db()->prepare('DELETE FROM "DestinationImage" WHERE id = ?')->execute([$id]);
    @unlink(image_path($image));
    respond(['ok' => true]);
}
