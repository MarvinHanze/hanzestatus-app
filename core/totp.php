<?php
declare(strict_types=1);

/**
 * Compacte eigen TOTP-implementatie (RFC 6238, HMAC-SHA1, 30s-stap, 6 cijfers).
 * Geen Composer-dependency nodig (bewuste keuze, consistent met de andere
 * HanzeOnline-apps). Generiek, geen tabel-afhankelijkheden.
 */

function totpBase32Encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0');
        $output .= $alphabet[bindec($chunk)];
    }
    return $output;
}

function totpBase32Decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(rtrim($b32, '='));
    $bits = '';
    foreach (str_split($b32) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) < 8) continue;
        $bytes .= chr(bindec($byte));
    }
    return $bytes;
}

function totpGenerateSecret(): string
{
    return totpBase32Encode(random_bytes(20));
}

function totpCodeAt(string $secret, int $timestamp): string
{
    $key = totpBase32Decode($secret);
    $counter = intdiv($timestamp, 30);
    $binCounter = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord($hash[19]) & 0xf;
    $code = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string) ($code % 1000000), 6, '0', STR_PAD_LEFT);
}

function totpCurrentCode(string $secret): string
{
    return totpCodeAt($secret, time());
}

/** Accepteert de code van het huidige en het vorige/volgende tijdvak (klokdrift-marge). */
function totpVerify(string $secret, string $code): bool
{
    $code = trim($code);
    for ($drift = -1; $drift <= 1; $drift++) {
        if (hash_equals(totpCodeAt($secret, time() + $drift * 30), $code)) {
            return true;
        }
    }
    return false;
}

function totpOtpauthUri(string $secret, string $email, string $issuer = 'HanzeStatus'): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email)
        . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}
