<?php

namespace LaravelMonitor\Support;

/**
 * Reversibly disguises a monitor_entries row's own auto-increment id as a
 * uuid-shaped path segment (e.g. mail/notification/outgoing "single send"
 * detail links) — unlike KeyHash, which is one-way and resolved by matching
 * against known keys, an id has no small enumerable set to match against, so
 * this has to decode straight back to the int instead. AES-128-ECB on a
 * single 16-byte block: exactly one block in, one block out, no IV/padding
 * bookkeeping — not a security boundary, just enough to stop a raw
 * incrementing id in the URL.
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
