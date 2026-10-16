<?php
// Zwei-Faktor-Anmeldung mit Einmalcodes aus einer Authenticator-App (TOTP nach RFC 6238).
// Kommt ohne Bibliotheken aus: HMAC-SHA1 liefert PHP selbst.

declare(strict_types=1);

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;
// Erlaubt eine Abweichung der Uhr von bis zu ±30 Sekunden.
const TOTP_WINDOW = 1;
const TOTP_RECOVERY_CODES = 10;
// So lange darf man sich nach dem Passwort Zeit für den Code lassen.
const TOTP_LOGIN_TIMEOUT = 300;
const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_generate_secret(): string
{
    return base32_encode(random_bytes(20));
}

function base32_encode(string $data): string
{
    $bits = '';
    foreach (str_split($data) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $result = '';
    foreach (str_split($bits, 5) as $chunk) {
        $result .= BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
    }
    return $result;
}

function base32_decode(string $text): string
{
    $bits = '';
    foreach (str_split(strtoupper(rtrim($text, '='))) as $char) {
        $value = strpos(BASE32_ALPHABET, $char);
        if ($value === false) {
            throw new InvalidArgumentException('Ungültiges Base32');
        }
        $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
    }
    $result = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $result .= chr(bindec($byte));
        }
    }
    return $result;
}

/** Code für ein Zeitfenster (Anzahl der 30-Sekunden-Schritte seit 1970). */
function totp_code(string $secret, int $step): string
{
    $hash = hash_hmac('sha1', pack('J', $step), base32_decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
    return str_pad((string) ($value % 10 ** TOTP_DIGITS), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Prüft einen Code und liefert das passende Zeitfenster oder null. Fenster bis einschließlich
 * `$lastStep` sind verbraucht – so lässt sich ein abgefangener Code nicht ein zweites Mal verwenden.
 */
function totp_verify(string $secret, string $code, ?int $lastStep = null): ?int
{
    if (!preg_match('/^\d{' . TOTP_DIGITS . '}$/', $code)) {
        return null;
    }
    $current = intdiv(time(), TOTP_PERIOD);
    for ($step = $current - TOTP_WINDOW; $step <= $current + TOTP_WINDOW; $step++) {
        if (($lastStep === null || $step > $lastStep) && hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

/** Link für Authenticator-Apps, als QR-Code oder zum Antippen auf dem Handy. */
function totp_uri(string $secret, string $username): string
{
    $issuer = 'Ausflugskiste';
    return 'otpauth://totp/' . rawurlencode("$issuer:$username") . '?' . http_build_query([
        'secret' => $secret,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => TOTP_DIGITS,
        'period' => TOTP_PERIOD,
    ], '', '&', PHP_QUERY_RFC3986);
}

/** Wiederherstellungscodes wie „k7mp-x9qr“, gespeichert wird nur ihr Hash. */
function totp_new_recovery_codes(): array
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $codes = [];
    for ($n = 0; $n < TOTP_RECOVERY_CODES; $n++) {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $codes[] = substr($code, 0, 4) . '-' . substr($code, 4);
    }
    return $codes;
}

/** Leer- und Bindestriche sowie Groß-/Kleinschreibung spielen bei der Eingabe keine Rolle. */
function totp_recovery_hash(string $code): string
{
    return hash('sha256', strtolower((string) preg_replace('/[\s-]+/', '', $code)));
}
