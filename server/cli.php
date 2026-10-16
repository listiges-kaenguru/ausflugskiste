<?php
// Wartung über die Kommandozeile, z. B. wenn das Admin-Passwort vergessen wurde.
//   php server/cli.php create-user <benutzername> <passwort> [ADMIN|USER]
//   php server/cli.php set-password <benutzername> <passwort>
//   php server/cli.php list-users
//   php server/cli.php list-passkeys <benutzername>
//   php server/cli.php delete-passkeys <benutzername> [<passkey-id>]   (ohne ID: alle)
//   php server/cli.php disable-totp <benutzername>   (Zwei-Faktor-Anmeldung abschalten, z. B. nach Handyverlust)
//   php server/cli.php seed-demo        (nur für die lokale Entwicklung, gibt zufällige Passwörter aus)

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';

// Einrichtungsfehler (z. B. fehlendes pdo_sqlite) lesbar ausgeben statt als Stacktrace.
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, ($error instanceof ApiException ? $error->getMessage() : (string) $error) . "\n");
    exit(1);
});

$command = $argv[1] ?? '';

switch ($command) {
    case 'create-user':
        [$username, $password] = [$argv[2] ?? '', $argv[3] ?? ''];
        $role = strtoupper($argv[4] ?? 'USER') === 'ADMIN' ? 'ADMIN' : 'USER';
        if (strlen($username) < 3 || strlen($password) < 6) {
            exit("Benutzername mindestens 3, Passwort mindestens 6 Zeichen.\n");
        }
        $timestamp = now();
        db()->prepare('INSERT INTO "User" (id, username, passwordHash, role, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([uuid(), $username, hash_password($password), $role, $timestamp, $timestamp]);
        echo "Benutzer $username ($role) angelegt.\n";
        break;

    case 'set-password':
        [$username, $password] = [$argv[2] ?? '', $argv[3] ?? ''];
        if (strlen($password) < 6) {
            exit("Passwort mindestens 6 Zeichen.\n");
        }
        $stmt = db()->prepare('UPDATE "User" SET passwordHash = ?, updatedAt = ? WHERE username = ?');
        $stmt->execute([hash_password($password), now(), $username]);
        echo $stmt->rowCount() ? "Passwort für $username geändert.\n" : "Benutzer $username nicht gefunden.\n";
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
        $stmt = $passkeyId === null
            ? db()->prepare('DELETE FROM "Passkey" WHERE userId = ?')
            : db()->prepare('DELETE FROM "Passkey" WHERE userId = ? AND id = ?');
        $stmt->execute($passkeyId === null ? [$userId] : [$userId, $passkeyId]);
        echo $stmt->rowCount() === 1 ? "1 Passkey entfernt.\n" : "{$stmt->rowCount()} Passkeys entfernt.\n";
        break;

    case 'disable-totp':
        $userId = cli_user_id($argv[2] ?? '');
        db()->prepare('UPDATE "User" SET totpSecret = NULL, totpLastStep = NULL, totpRecoveryCodes = NULL, updatedAt = ? WHERE id = ?')
            ->execute([now(), $userId]);
        echo "Zwei-Faktor-Anmeldung für {$argv[2]} abgeschaltet.\n";
        break;

    case 'seed-demo':
        seed_demo();
        echo "Demo-Daten angelegt.\n";
        break;

    default:
        echo "Befehle: create-user, set-password, list-users, list-passkeys, delete-passkeys, disable-totp, seed-demo\n";
        exit(1);
}

function cli_user_id(string $username): string
{
    $stmt = db()->prepare('SELECT id FROM "User" WHERE username = ?');
    $stmt->execute([$username]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        fwrite(STDERR, "Benutzer $username nicht gefunden.\n");
        exit(1);
    }
    return $id;
}

function seed_demo(): void
{
    $timestamp = now();
    $userIds = [];
    foreach (['admin' => 'ADMIN', 'demo' => 'USER'] as $username => $role) {
        $stmt = db()->prepare('SELECT id FROM "User" WHERE username = ?');
        $stmt->execute([$username]);
        $userIds[$username] = $stmt->fetchColumn() ?: null;
        if ($userIds[$username] === null) {
            // Keine festen Zugangsdaten im Code: Passwort zufällig erzeugen und einmalig anzeigen.
            $password = bin2hex(random_bytes(6));
            echo "Benutzer $username ($role) angelegt, Passwort: $password\n";
            $userIds[$username] = uuid();
            db()->prepare('INSERT INTO "User" (id, username, passwordHash, role, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$userIds[$username], $username, hash_password($password), $role, $timestamp, $timestamp]);
        }
    }

    foreach (['Natur', 'Kultur', 'Food', 'Familie', 'Weekend'] as $name) {
        db()->prepare('INSERT OR IGNORE INTO "Tag" (id, name, createdAt, updatedAt) VALUES (?, ?, ?, ?)')
            ->execute([uuid(), $name, $timestamp, $timestamp]);
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
