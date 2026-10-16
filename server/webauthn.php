<?php
// Passkeys (WebAuthn) ohne externe Bibliothek.
// Unterstützt ES256 (alle gängigen Passkeys) und RS256 (u. a. Windows Hello),
// Attestierung „none“ – die App vertraut dem Gerät, nicht dem Hersteller.
// Benötigt die PHP-Erweiterung openssl; ohne sie sind Passkeys abgeschaltet.

declare(strict_types=1);

const WEBAUTHN_TIMEOUT_MS = 120000;
const COSE_ALG_ES256 = -7;
const COSE_ALG_RS256 = -257;

// Bekannte Passwortmanager (AAGUID), damit ein neuer Passkey gleich einen sprechenden Namen bekommt.
const PASSKEY_PROVIDERS = [
    'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => 'Google Passwortmanager',
    'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' => 'iCloud-Schlüsselbund',
    'dd4ec289-e01d-41c9-bb89-70fa845d4bf2' => 'iCloud-Schlüsselbund',
    '08987058-cadc-4b81-b6e1-30de50dcbe96' => 'Windows Hello',
    '9ddd1817-af5a-4672-a2b9-3e3dd95000a9' => 'Windows Hello',
    '6028b017-b1d4-4c02-b4b3-afcdafc96bb2' => 'Windows Hello',
    'adce0002-35bc-c60a-648b-0b25f1f05503' => 'Chrome auf dem Mac',
    'bada5566-a7aa-401f-bd96-45619a55120d' => '1Password',
    'd548826e-79b4-db40-a3d8-11116f7e8349' => 'Bitwarden',
    '53414d53-554e-4700-0000-000000000000' => 'Samsung Pass',
];

function passkeys_available(): bool
{
    return function_exists('openssl_verify') && function_exists('openssl_pkey_get_public');
}

function invalid_passkey_data(): never
{
    fail(400, 'VALIDATION_ERROR', 'Ungültige Passkey-Daten');
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(mixed $data): string
{
    if (!is_string($data) || !preg_match('/^[A-Za-z0-9_-]*$/', $data)) {
        invalid_passkey_data();
    }
    return (string) base64_decode(strtr($data, '-_', '+/'), true);
}

/** Relying-Party-ID ist der Hostname, unter dem die App aufgerufen wird. */
function webauthn_rp_id(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    return strtolower((string) (parse_url('http://' . $host, PHP_URL_HOST) ?: 'localhost'));
}

/** Erzeugt eine einmalige Challenge und merkt sie sich in der Session. */
function webauthn_challenge(string $purpose): string
{
    $challenge = base64url_encode(random_bytes(32));
    $_SESSION['webauthn'] = [
        'purpose' => $purpose,
        'challenge' => $challenge,
        'expires' => time() + intdiv(WEBAUTHN_TIMEOUT_MS, 1000) + 30,
    ];
    return $challenge;
}

/**
 * Prüft clientDataJSON gegen die gespeicherte Challenge und den Aufruf-Ursprung.
 * Die Challenge wird dabei verbraucht und kann nicht erneut verwendet werden.
 */
function webauthn_check_client_data(string $clientDataJSON, string $type, string $purpose): void
{
    $pending = $_SESSION['webauthn'] ?? null;
    unset($_SESSION['webauthn']);

    $clientData = json_decode($clientDataJSON, true);
    if (!is_array($clientData) || ($clientData['type'] ?? null) !== $type) {
        invalid_passkey_data();
    }
    if (
        !is_array($pending) || $pending['purpose'] !== $purpose || $pending['expires'] < time()
        || !hash_equals($pending['challenge'], (string) ($clientData['challenge'] ?? ''))
    ) {
        fail(400, 'CHALLENGE_EXPIRED', 'Die Anfrage ist abgelaufen. Bitte erneut versuchen.');
    }

    // Der Ursprung muss genau dieser Host sein – über HTTPS, auf localhost auch HTTP.
    $origin = parse_url((string) ($clientData['origin'] ?? ''));
    $host = strtolower((string) ($origin['host'] ?? ''));
    $scheme = $origin['scheme'] ?? '';
    $isLocal = in_array($host, ['localhost', '127.0.0.1'], true);
    if ($host !== webauthn_rp_id() || !($scheme === 'https' || ($scheme === 'http' && $isLocal))) {
        fail(400, 'VALIDATION_ERROR', 'Passkey gehört zu einer anderen Adresse');
    }
}

/** Zerlegt authenticatorData (WebAuthn §6.1) und prüft RP-ID sowie Benutzerprüfung. */
function webauthn_parse_auth_data(string $authData): array
{
    if (strlen($authData) < 37 || !hash_equals(hash('sha256', webauthn_rp_id(), true), substr($authData, 0, 32))) {
        fail(400, 'VALIDATION_ERROR', 'Passkey gehört zu einer anderen Adresse');
    }
    $flags = ord($authData[32]);
    // Bit 0: Benutzer anwesend, Bit 2: Benutzer verifiziert (PIN, Biometrie, …)
    if (($flags & 0x01) === 0 || ($flags & 0x04) === 0) {
        fail(400, 'VALIDATION_ERROR', 'Der Passkey wurde nicht per PIN oder Biometrie bestätigt');
    }
    $result = ['signCount' => unpack('N', substr($authData, 33, 4))[1]];

    if ($flags & 0x40) {
        if (strlen($authData) < 55) {
            invalid_passkey_data();
        }
        $result['aaguid'] = format_uuid(substr($authData, 37, 16));
        $idLength = unpack('n', substr($authData, 53, 2))[1];
        $result['credentialId'] = substr($authData, 55, $idLength);
        $offset = 55 + $idLength;
        $result['publicKey'] = cbor_decode($authData, $offset);
    }
    return $result;
}

/** Wertet die Antwort von navigator.credentials.create() aus. */
function webauthn_verify_registration(array $body): array
{
    $clientDataJSON = base64url_decode($body['clientDataJSON'] ?? null);
    webauthn_check_client_data($clientDataJSON, 'webauthn.create', 'register');

    $offset = 0;
    $attestation = cbor_decode(base64url_decode($body['attestationObject'] ?? null), $offset);
    if (!is_array($attestation) || !is_string($attestation['authData'] ?? null)) {
        invalid_passkey_data();
    }
    $authData = webauthn_parse_auth_data($attestation['authData']);
    if (!isset($authData['credentialId'], $authData['publicKey']) || $authData['credentialId'] === '') {
        invalid_passkey_data();
    }
    [$algorithm, $pem] = cose_to_pem($authData['publicKey']);

    return [
        'provider' => PASSKEY_PROVIDERS[$authData['aaguid']] ?? null,
        'credentialId' => base64url_encode($authData['credentialId']),
        'publicKey' => $pem,
        'algorithm' => $algorithm,
        'signCount' => $authData['signCount'],
    ];
}

/** Prüft die Signatur einer Anmeldung mit einem gespeicherten Passkey. */
function webauthn_verify_assertion(array $body, array $passkey): int
{
    $clientDataJSON = base64url_decode($body['clientDataJSON'] ?? null);
    $authDataRaw = base64url_decode($body['authenticatorData'] ?? null);
    $signature = base64url_decode($body['signature'] ?? null);
    webauthn_check_client_data($clientDataJSON, 'webauthn.get', 'login');
    $authData = webauthn_parse_auth_data($authDataRaw);

    $key = openssl_pkey_get_public($passkey['publicKey']);
    $signed = $authDataRaw . hash('sha256', $clientDataJSON, true);
    if ($key === false || openssl_verify($signed, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
        fail(401, 'INVALID_CREDENTIALS', 'Passkey konnte nicht bestätigt werden');
    }

    // Ein nicht steigender Zähler deutet auf einen kopierten Schlüssel hin (Synchron-Passkeys melden stets 0).
    $stored = (int) $passkey['signCount'];
    if ($authData['signCount'] !== 0 && $authData['signCount'] <= $stored) {
        fail(401, 'INVALID_CREDENTIALS', 'Passkey konnte nicht bestätigt werden');
    }
    return $authData['signCount'];
}

// ---------------------------------------------------------------- COSE-Schlüssel

/** Wandelt einen COSE-Schlüssel in PEM um, damit openssl ihn prüfen kann. */
function cose_to_pem(mixed $cose): array
{
    if (!is_array($cose)) {
        fail(400, 'VALIDATION_ERROR', 'Ungültiger Passkey-Schlüssel');
    }
    $kty = $cose[1] ?? null;
    $alg = $cose[3] ?? null;

    if ($kty === 2 && $alg === COSE_ALG_ES256 && ($cose[-1] ?? null) === 1
        && is_string($cose[-2] ?? null) && strlen($cose[-2]) === 32
        && is_string($cose[-3] ?? null) && strlen($cose[-3]) === 32) {
        // SubjectPublicKeyInfo für P-256 mit unkomprimiertem Punkt 0x04 || x || y
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $cose[-2] . $cose[-3];
    } elseif ($kty === 3 && $alg === COSE_ALG_RS256 && is_string($cose[-1] ?? null) && is_string($cose[-2] ?? null)) {
        $rsaKey = der_sequence(der_integer($cose[-1]) . der_integer($cose[-2]));
        $algorithm = der_sequence(hex2bin('06092a864886f70d0101010500'));
        $der = der_sequence($algorithm . der_tlv(0x03, "\x00" . $rsaKey));
    } else {
        fail(400, 'VALIDATION_ERROR', 'Dieser Passkey-Typ wird nicht unterstützt');
    }

    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    if (openssl_pkey_get_public($pem) === false) {
        fail(400, 'VALIDATION_ERROR', 'Ungültiger Passkey-Schlüssel');
    }
    return [$alg, $pem];
}

function der_tlv(int $tag, string $value): string
{
    $length = strlen($value);
    if ($length < 0x80) {
        return chr($tag) . chr($length) . $value;
    }
    $lengthBytes = ltrim(pack('N', $length), "\x00");
    return chr($tag) . chr(0x80 | strlen($lengthBytes)) . $lengthBytes . $value;
}

function der_sequence(string $value): string
{
    return der_tlv(0x30, $value);
}

function der_integer(string $bytes): string
{
    $bytes = ltrim($bytes, "\x00");
    if ($bytes === '' || ord($bytes[0]) & 0x80) {
        $bytes = "\x00" . $bytes;
    }
    return der_tlv(0x02, $bytes);
}

// ---------------------------------------------------------------- CBOR (RFC 8949, nur was WebAuthn braucht)

function cbor_decode(string $data, int &$offset, int $depth = 0): mixed
{
    if ($depth > 16 || $offset >= strlen($data)) {
        invalid_passkey_data();
    }
    $initial = ord($data[$offset++]);
    $major = $initial >> 5;
    $info = $initial & 0x1f;

    if ($major === 7) {
        return match ($info) {
            20 => false,
            21 => true,
            22, 23 => null,
            default => invalid_passkey_data(),
        };
    }

    $value = cbor_argument($data, $offset, $info);
    switch ($major) {
        case 0:
            return $value;
        case 1:
            return -1 - $value;
        case 2:
        case 3:
            if ($offset + $value > strlen($data)) {
                invalid_passkey_data();
            }
            $bytes = substr($data, $offset, $value);
            $offset += $value;
            return $bytes;
        case 4:
            $list = [];
            for ($i = 0; $i < $value; $i++) {
                $list[] = cbor_decode($data, $offset, $depth + 1);
            }
            return $list;
        case 5:
            $map = [];
            for ($i = 0; $i < $value; $i++) {
                $key = cbor_decode($data, $offset, $depth + 1);
                if (!is_int($key) && !is_string($key)) {
                    invalid_passkey_data();
                }
                $map[$key] = cbor_decode($data, $offset, $depth + 1);
            }
            return $map;
        default: // 6: Tag – der markierte Wert genügt
            return cbor_decode($data, $offset, $depth + 1);
    }
}

function cbor_argument(string $data, int &$offset, int $info): int
{
    if ($info < 24) {
        return $info;
    }
    [$size, $format] = match ($info) {
        24 => [1, 'C'],
        25 => [2, 'n'],
        26 => [4, 'N'],
        27 => [8, 'J'],
        default => invalid_passkey_data(),
    };
    if ($offset + $size > strlen($data)) {
        invalid_passkey_data();
    }
    $value = unpack($format, substr($data, $offset, $size))[1];
    $offset += $size;
    if ($value < 0 || $value > 0x7fffffff) {
        invalid_passkey_data();
    }
    return $value;
}
