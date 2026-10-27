<?php

namespace LaravelMonitor\Support;

/**
 * Reversibly encodes a monitor_entries id as a uuid-shaped path segment
 * (AES-128-ECB on one 16-byte block). Hides the raw incrementing id; not a security boundary.
 */
class EntryId
{
    public static function encode(int $id): string
    {
        $block = str_pad(pack('J', $id), 16, "\0", STR_PAD_LEFT);
        $cipher = openssl_encrypt($block, 'aes-128-ecb', self::key(), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        $hex = bin2hex($cipher);

        return implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12)]);
    }

    public static function decode(string $value): ?int
    {
        $hex = str_replace('-', '', $value);

        if (strlen($hex) !== 32 || ! ctype_xdigit($hex)) {
            return null;
        }

        $block = openssl_decrypt(hex2bin($hex), 'aes-128-ecb', self::key(), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);

        if ($block === false || strlen($block) !== 16) {
            return null;
        }

        return unpack('J', substr($block, 8, 8))[1];
    }

    private static function key(): string
    {
        return substr(hash('sha256', (string) config('app.key'), true), 0, 16);
    }
}
