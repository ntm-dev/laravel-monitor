<?php

namespace LaravelMonitor\Support;

use function json_decode;
use function json_encode;

class Json
{
    /**
     * Invalid UTF-8 can't fail the whole encode, non-ASCII isn't inflated to
     * \uXXXX escapes, and 1.0 doesn't decode back as int 1.
     */
    private const ENCODE_FLAGS = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** Encode with the package's storage flags, plus any extra (e.g. JSON_PRETTY_PRINT). */
    public static function encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, self::ENCODE_FLAGS | $flags);
    }

    /** Decode to associative arrays; null for invalid JSON (check json_last_error() to tell it from a literal null). */
    public static function decode(string $json): mixed
    {
        return json_decode($json, true);
    }
}
