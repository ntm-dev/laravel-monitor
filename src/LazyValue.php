<?php

namespace LaravelMonitor;

use JsonSerializable;

use function call_user_func;

/**
 * @internal
 *
 * @template TValue
 */
final class LazyValue implements JsonSerializable
{
    /**
     * @param  (callable(): TValue)  $callback
     */
    public function __construct(
        private $callback,
    ) {
        //
    }

    /**
     * @return TValue
     */
    public function resolve(): mixed
    {
        return call_user_func($this->callback);
    }

    /**
     * @return TValue
     */
    public function jsonSerialize(): mixed
    {
        return $this->resolve();
    }
}
