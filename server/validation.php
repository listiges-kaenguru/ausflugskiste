<?php
// Eingabeprüfung für die API (ersetzt die früheren zod-Schemas).

declare(strict_types=1);

function json_body(): array
{
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        fail(400, 'VALIDATION_ERROR', 'Ungültige Eingabe');
    }
    return $body;
}

function validation_failed(array $errors): never
{
    $details = [];
    foreach ($errors as $field => $message) {
        $details[] = ['field' => $field, 'message' => $message];
    }
    fail(400, 'VALIDATION_ERROR', reset($errors) ?: 'Ungültige Eingabe', $details);
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function string_field(array $body, string $field): ?string
{
    $value = $body[$field] ?? null;
    return is_string($value) ? $value : null;
}

/** Leere optionale Textfelder werden als NULL gespeichert. */
function optional_text(array $body, string $field, int $maxLength, array &$errors): ?string
{
    $value = trim(string_field($body, $field) ?? '');
    if (text_length($value) > $maxLength) {
        $errors[$field] = "Maximal $maxLength Zeichen erlaubt";
    }
    return $value === '' ? null : $value;
}

function username_error(string $username): ?string
{
    if (text_length($username) < 3) {
        return 'Benutzername ist zu kurz';
    }
    return text_length($username) > 64 ? 'Benutzername ist zu lang' : null;
}

function validate_username(array $body): string
{
    $username = trim(string_field($body, 'username') ?? '');
    $error = username_error($username);
    if ($error !== null) {
        validation_failed(['username' => $error]);
    }
    return $username;
}

/** bcrypt berücksichtigt nur die ersten 72 Bytes, deshalb die Obergrenze. */
function password_error(string $password, string $label): ?string
{
    if (strlen($password) < 6) {
        return "$label ist zu kurz";
    }
    return strlen($password) > 72 ? "$label ist zu lang (maximal 72 Bytes)" : null;
}

function validate_credentials(array $body): array
{
    $username = trim(string_field($body, 'username') ?? '');
    $password = string_field($body, 'password') ?? '';
    $errors = array_filter([
        'username' => username_error($username),
        'password' => password_error($password, 'Passwort'),
    ]);
    if ($errors) {
        validation_failed($errors);
    }
    return ['username' => $username, 'password' => $password];
}

function validate_change_password(array $body, bool $requireCurrent = true): array
{
    $current = string_field($body, 'currentPassword') ?? '';
    $new = string_field($body, 'newPassword') ?? '';
    $errors = array_filter([
        'currentPassword' => $requireCurrent && strlen($current) < 6 ? 'Aktuelles Passwort fehlt' : null,
        'newPassword' => password_error($new, 'Neues Passwort'),
    ]);
    if ($errors) {
        validation_failed($errors);
    }
    return ['currentPassword' => $current, 'newPassword' => $new];
}

function validate_destination(array $body): array
{
    $errors = [];

    $name = trim(string_field($body, 'name') ?? '');
    if ($name === '') {
        $errors['name'] = 'Name ist erforderlich';
    } elseif (text_length($name) > 200) {
        $errors['name'] = 'Maximal 200 Zeichen erlaubt';
    }

    $link = optional_text($body, 'googleMapsLink', 2000, $errors);
    if ($link !== null && !preg_match('#^https?://#i', $link)) {
        $errors['googleMapsLink'] = 'Link muss mit http:// oder https:// beginnen';
    }

    $rating = $body['rating'] ?? null;
    if ($rating === '' || $rating === null) {
        $rating = null;
    } elseif (filter_var($rating, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]) === false) {
        $errors['rating'] = 'Bewertung muss zwischen 1 und 5 liegen';
    } else {
        $rating = (int) $rating;
    }

    $tagIds = $body['tagIds'] ?? null;
    if ($tagIds !== null && (!is_array($tagIds) || array_filter($tagIds, fn ($id) => !is_string($id)))) {
        $errors['tagIds'] = 'Ungültige Tags';
        $tagIds = [];
    }

    $result = [
        'name' => $name,
        'address' => optional_text($body, 'address', 500, $errors),
        'googleMapsLink' => $link,
        'description' => optional_text($body, 'description', 5000, $errors),
        'rating' => $rating,
        'favorite' => ($body['favorite'] ?? false) === true,
        'visited' => ($body['visited'] ?? false) === true,
        'privateNotes' => optional_text($body, 'privateNotes', 5000, $errors),
        'tagIds' => $tagIds === null ? null : array_values($tagIds),
    ];

    if ($errors) {
        validation_failed($errors);
    }
    return $result;
}

function validate_tag(array $body): string
{
    $name = trim(string_field($body, 'name') ?? '');
    if ($name === '') {
        validation_failed(['name' => 'Name ist erforderlich']);
    }
    if (text_length($name) > 50) {
        validation_failed(['name' => 'Maximal 50 Zeichen erlaubt']);
    }
    return $name;
}

function validate_passkey_name(array $body): string
{
    $name = trim(string_field($body, 'name') ?? '');
    if ($name === '') {
        return 'Passkey';
    }
    if (text_length($name) > 60) {
        validation_failed(['name' => 'Maximal 60 Zeichen erlaubt']);
    }
    return $name;
}
