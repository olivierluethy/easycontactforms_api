<?php
// Identifier generation. Shared by the API (via bootstrap.php) and the CLI
// migration runner, which cannot load bootstrap.php because that sends headers.
//
// Every identifier that leaves the server — in a URL, an API payload, or an
// embed snippet — comes from here. Internal AUTO_INCREMENT primary keys are
// never exposed: they are sequential, so exposing one lets anybody enumerate
// their neighbours.

declare(strict_types=1);

if (!function_exists('uuid4')) {
    /**
     * RFC 4122 version 4 UUID from a CSPRNG.
     *
     * Deliberately NOT MySQL's UUID(): that returns a version 1 UUID derived
     * from the clock and the server's MAC address, so consecutive rows differ
     * in only a few predictable characters. That would defeat the entire point
     * of having a non-guessable identifier.
     */
    function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

if (!function_exists('gen_token')) {
    /**
     * Random hex token. Used for user API tokens (24 bytes) and for the public
     * project/form tokens that appear in embed snippets (12 bytes = 96 bits,
     * far beyond brute-forcing over HTTP).
     */
    function gen_token(int $bytes = 24): string
    {
        return bin2hex(random_bytes($bytes));
    }
}

if (!function_exists('is_uuid')) {
    /** True when the value looks like a canonical v4 UUID. */
    function is_uuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
