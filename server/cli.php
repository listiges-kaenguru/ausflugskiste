<?php
// Wartung über die Kommandozeile, z. B. wenn das Admin-Passwort vergessen wurde.
//   php server/cli.php create-user <benutzername> <passwort> [ADMIN|USER]
//   php server/cli.php set-password <benutzername> <passwort>
//   php server/cli.php list-users
//   php server/cli.php list-passkeys <benutzername>
//   php server/cli.php delete-passkeys <benutzername> [<passkey-id>]   (ohne ID: alle)
//   php server/cli.php disable-totp <benutzername>   (Zwei-Faktor-Anmeldung abschalten, z. B. nach Handyverlust)
//   php server/cli.php lock-user <benutzername>      (sperren, beendet laufende Sitzungen)
//   php server/cli.php unlock-user <benutzername>
//   php server/cli.php delete-user <benutzername>    (samt Zielen und Fotos, mit Rückfrage)
//   php server/cli.php seed-demo        (nur für die lokale Entwicklung, gibt zufällige Passwörter aus)

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/validation.php';

// Einrichtungsfehler (z. B. fehlendes pdo_sqlite) lesbar ausgeben statt als Stacktrace.
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, ($error instanceof ApiException ? $error->getMessage() : (string) $error) . "\n");
    exit(1);
});

$command = $argv[1] ?? '';

switch ($command) {
    case 'create-user':
        $username = cli_username($argv[2] ?? '');
        $password = cli_password($argv[3] ?? '');
        $role = strtoupper($argv[4] ?? 'USER') === 'ADMIN' ? 'ADMIN' : 'USER';
        if (find_user_id($username) !== null) {
            cli_fail("Benutzername $username ist bereits vergeben.");
        }
        cli_insert_user($username, $password, $role);
        echo "Benutzer $username ($role) angelegt.\n";
        break;

    case 'set-password':
        $user = cli_user($argv[2] ?? '');
        $password = cli_password($argv[3] ?? '');
        // Wie beim Passwortwechsel in der App: alle laufenden Sitzungen des Benutzers enden
        db()->prepare('UPDATE "User" SET passwordHash = ?, sessionVersion = sessionVersion + 1, updatedAt = ? WHERE id = ?')
            ->execute([hash_password($password), now(), $user['id']]);
        echo "Passwort für {$user['username']} geändert, laufende Sitzungen beendet.\n";
        break;

    case 'list-users':
        foreach (db()->query('SELECT username, role, createdAt, disabledAt, mustChangePassword, totpSecret IS NOT NULL AS totp FROM "User" ORDER BY username') as $user) {
            $status = ($user['disabledAt'] === null ? '' : "\tgesperrt") . ($user['mustChangePassword'] ? "\tPasswortwechsel ausstehend" : '') . ($user['totp'] ? "\t2FA" : '');
            echo "{$user['username']}\t{$user['role']}\t{$user['createdAt']}$status\n";
        }
        break;

    case 'list-passkeys':
        $userId = cli_user_id($argv[2] ?? '');
        $stmt = db()->prepare('SELECT id, name, createdAt, lastUsedAt FROM "Passkey" WHERE userId = ? ORDER BY createdAt');
        $stmt->execute([$userId]);
        $passkeys = $stmt->fetchAll();
        foreach ($passkeys as $passkey) {
            echo "{$passkey['id']}\t{$passkey['name']}\t{$passkey['createdAt']}\t" . ($passkey['lastUsedAt'] ?? 'nie genutzt') . "\n";
        }
        if (!$passkeys) {
            echo "Keine Passkeys.\n";
        }
        break;

    case 'delete-passkeys':
        $userId = cli_user_id($argv[2] ?? '');
        $passkeyId = $argv[3] ?? null;
        if ($passkeyId === null) {
            $stmt = db()->prepare('DELETE FROM "Passkey" WHERE userId = ?');
            $stmt->execute([$userId]);
        } else {
            $stmt = db()->prepare('DELETE FROM "Passkey" WHERE userId = ? AND id = ?');
            $stmt->execute([$userId, $passkeyId]);
        }
        echo $stmt->rowCount() === 1 ? "1 Passkey entfernt.\n" : "{$stmt->rowCount()} Passkeys entfernt.\n";
        break;

    case 'disable-totp':
        $user = cli_user($argv[2] ?? '');
        db()->prepare('UPDATE "User" SET totpSecret = NULL, totpLastStep = NULL, totpRecoveryCodes = NULL, updatedAt = ? WHERE id = ?')
            ->execute([now(), $user['id']]);
        echo "Zwei-Faktor-Anmeldung für {$user['username']} abgeschaltet.\n";
        break;

    case 'lock-user':
        $user = cli_user($argv[2] ?? '');
        if ($user['disabledAt'] !== null) {
            echo "Benutzer {$user['username']} ist bereits gesperrt.\n";
            break;
        }
        cli_keep_last_admin($user, 'gesperrt');
        db()->prepare('UPDATE "User" SET disabledAt = ?, updatedAt = ? WHERE id = ?')->execute([now(), now(), $user['id']]);
        echo "Benutzer {$user['username']} gesperrt.\n";
        break;

    case 'unlock-user':
        $user = cli_user($argv[2] ?? '');
        if ($user['disabledAt'] === null) {
            echo "Benutzer {$user['username']} ist nicht gesperrt.\n";
            break;
        }
        db()->prepare('UPDATE "User" SET disabledAt = NULL, updatedAt = ? WHERE id = ?')->execute([now(), $user['id']]);
        echo "Benutzer {$user['username']} entsperrt.\n";
        break;

    case 'delete-user':
        $user = cli_user($argv[2] ?? '');
        cli_keep_last_admin($user, 'gelöscht');
        $stmt = db()->prepare('SELECT COUNT(*) FROM "Destination" WHERE createdBy = ? AND deletedAt IS NULL');
        $stmt->execute([$user['id']]);
        $count = (int) $stmt->fetchColumn();
        echo "Benutzer {$user['username']} mit $count " . ($count === 1 ? 'Ziel' : 'Ziele') . " und allen Fotos endgültig löschen?\n";
        echo 'Zum Bestätigen „ja“ eingeben: ';
        if (trim((string) fgets(STDIN)) !== 'ja') {
            echo "\nAbgebrochen.\n";
            exit(1);
        }
        delete_user_account($user['id']);
        echo "Benutzer {$user['username']} gelöscht.\n";
        break;

    case 'seed-demo':
        seed_demo();
        echo "Demo-Daten angelegt.\n";
        break;

    default:
        echo "Befehle: create-user, set-password, list-users, list-passkeys, delete-passkeys, disable-totp, lock-user, unlock-user, delete-user, seed-demo\n";
        exit(1);
}

function find_user_id(string $username): ?string
{
    $stmt = db()->prepare('SELECT id FROM "User" WHERE username = ?');
    $stmt->execute([$username]);
    return $stmt->fetchColumn() ?: null;
}

function cli_user_id(string $username): string
{
    return cli_user($username)['id'];
}

function cli_fail(string $message): never
{
    fwrite(STDERR, "$message\n");
    exit(1);
}

/** Neuer Benutzername nach denselben Regeln wie in der App (getrimmt, 3–64 Zeichen). */
function cli_username(string $username): string
{
    if (preg_match('//u', $username) !== 1) {
        cli_fail('Benutzername ist kein gültiges UTF-8.');
    }
    $username = trim($username);
    $error = username_error($username);
    if ($error !== null) {
        cli_fail("$error (3–64 Zeichen).");
    }
    return $username;
}

/** Passwort nach denselben Regeln wie in der App (6–72 Bytes). */
function cli_password(string $password): string
{
    $error = password_error($password, 'Passwort');
    if ($error !== null) {
        cli_fail(str_contains($error, 'kurz') ? "$error (mindestens 6 Zeichen)." : "$error.");
    }
    return $password;
}

/** Benutzer zum Namen oder Abbruch mit Fehlermeldung. */
function cli_user(string $username): array
{
    if ($username === '') {
        cli_fail('Benutzername fehlt.');
    }
    $stmt = db()->prepare('SELECT id, username, role, disabledAt FROM "User" WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) {
        fwrite(STDERR, "Benutzer $username nicht gefunden.\n");
        exit(1);
    }
    return $user;
}

/** Bricht ab, wenn der Benutzer der letzte aktive Admin ist – sonst käme niemand mehr in die Verwaltung. */
function cli_keep_last_admin(array $user, string $what): void
{
    if ($user['role'] !== 'ADMIN' || $user['disabledAt'] !== null) {
        return;
    }
    $others = db()->prepare('SELECT COUNT(*) FROM "User" WHERE role = \'ADMIN\' AND disabledAt IS NULL AND id <> ?');
    $others->execute([$user['id']]);
    if ((int) $others->fetchColumn() === 0) {
        fwrite(STDERR, "{$user['username']} ist der letzte aktive Admin und kann nicht $what werden. Zuerst einen weiteren Admin anlegen.\n");
        exit(1);
    }
}

function cli_insert_user(string $username, string $password, string $role): string
{
    $id = uuid();
    $timestamp = now();
    db()->prepare('INSERT INTO "User" (id, username, passwordHash, role, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$id, $username, hash_password($password), $role, $timestamp, $timestamp]);
    return $id;
}

function seed_demo(): void
{
    $timestamp = now();
    $userIds = [];
    foreach (['admin' => 'ADMIN', 'demo' => 'USER'] as $username => $role) {
        $userIds[$username] = find_user_id($username);
        if ($userIds[$username] === null) {
            // Keine festen Zugangsdaten im Code: Passwort zufällig erzeugen und einmalig anzeigen.
            $password = bin2hex(random_bytes(6));
            echo "Benutzer $username ($role) angelegt, Passwort: $password\n";
            $userIds[$username] = cli_insert_user($username, $password, $role);
        }
    }

    $insertTag = db()->prepare('INSERT OR IGNORE INTO "Tag" (id, name, createdAt, updatedAt) VALUES (?, ?, ?, ?)');
    foreach (['Natur', 'Kultur', 'Food', 'Familie', 'Weekend'] as $name) {
        $insertTag->execute([uuid(), $name, $timestamp, $timestamp]);
    }

    $stmt = db()->prepare('SELECT 1 FROM "Destination" WHERE name = ? AND createdBy = ?');
    $stmt->execute(['Bergwanderung', $userIds['demo']]);
    if (!$stmt->fetchColumn()) {
        $id = uuid();
        db()->prepare('INSERT INTO "Destination" (id, name, address, description, rating, favorite, visited, createdAt, updatedAt, createdBy) VALUES (?, ?, ?, ?, 5, 1, 0, ?, ?, ?)')
            ->execute([$id, 'Bergwanderung', 'Bergstraße 12, 8000 Zürich', 'Schöner Tagesausflug mit Aussichtspunkt.', $timestamp, $timestamp, $userIds['demo']]);
        db()->prepare('INSERT INTO "DestinationTag" (destinationId, tagId) SELECT ?, id FROM "Tag" WHERE name IN (?, ?)')
            ->execute([$id, 'Natur', 'Weekend']);
    }
}
