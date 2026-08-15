<?php

namespace LaravelMonitor\Support;

use Illuminate\Support\Arr;

use function array_keys;
use function array_map;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_last_error;

/**
 * Decodes a raw JSON string into a {@see JsonNode} tree for the Log Context
 * viewer. Kept separate from JsonNode itself so the parse/decode-error
 * handling doesn't leak into the tree's own shape.
 */
class JsonTree
{
    public static function parse(string $raw): ?JsonNode
    {
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return self::build($decoded);
    }

    public static function build(mixed $value, ?string $key = null): JsonNode
    {
        return match (true) {
            is_array($value) && Arr::isList($value) => new JsonNode(
                type: 'array',
                key: $key,
                children: array_map(static fn (mixed $item): JsonNode => self::build($item), $value),
            ),
            is_array($value) => new JsonNode(
                type: 'object',
                key: $key,
                children: array_map(
                    static fn (mixed $itemKey, mixed $item): JsonNode => self::build($item, (string) $itemKey),
                    array_keys($value),
                    $value,
                ),
            ),
            is_string($value) => new JsonNode(type: 'string', key: $key, value: $value),
            is_int($value) || is_float($value) => new JsonNode(type: 'number', key: $key, value: $value),
            is_bool($value) => new JsonNode(type: 'bool', key: $key, value: $value),
            default => new JsonNode(type: 'null', key: $key),
        };
    }
}
