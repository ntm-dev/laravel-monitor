<?php

namespace LaravelMonitor\Support;

use function count;

/**
 * One node in a decoded JSON tree, built by {@see JsonTree} for the Log
 * Context viewer (resources/views/components/json-node.blade.php). A scalar
 * leaf carries $value; 'object'/'array' carry $children instead and leave
 * $value null.
 */
class JsonNode
{
    public function __construct(
        public string $type,
        /** Property name for an object member; null for a top-level node or an array item. */
        public ?string $key = null,
        public mixed $value = null,
        /** @var JsonNode[] */
        public array $children = [],
    ) {
    }

    public function isContainer(): bool
    {
        return $this->type === 'object' || $this->type === 'array';
    }

    public function count(): int
    {
        return count($this->children);
    }
}
