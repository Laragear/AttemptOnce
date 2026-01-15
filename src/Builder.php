<?php

namespace Laragear\AttemptOnce;

use BackedEnum;
use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use UnitEnum;
use function array_key_first;
use function array_map;
use function get_class;
use function implode;
use function is_array;
use function value;

/**
 * @template TDefault
 */
class Builder
{
    /**
     * The key to prepend to the default closure hash.
     *
     * @const string
     */
    public const string KEY_PREPEND = 'laragear|attempt_once';

    /**
     * Create a new Builder instance.
     *
     * @param  array<\Illuminate\Database\Eloquent\Model|\BackedEnum|string|array>  $key
     */
    public function __construct(
        protected RateLimiter $rateLimiter,
        protected array $key,
        protected mixed $default = false,
        protected DateTimeInterface|DateInterval|int $ttl = 30,
    ) {
        //
    }

    /**
     * Sets the window of time to allow only once execution attempt.
     *
     * @return $this
     */
    public function for(DateTimeInterface|DateInterval|int $ttl): static
    {
        $this->ttl = $ttl;

        return $this;
    }

    /**
     * Sets a default value to return when the callback is not executed.
     *
     * @param  TDefault|(callable():TDefault)  $value
     * @return $this
     */
    public function or(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    /**
     * Parses the key to use in the cache.
     */
    protected function parseKey(): string
    {
        return implode('|', array_map(static function (BackedEnum|Model|string|array $key): string {
            if (is_array($key)) {
                return array_key_first($key).':'.Arr::first($key);
            }

            if ($key instanceof Model) {
                return get_class($key).':'.$key->getKey();
            }

            if ($key instanceof UnitEnum) {
                return get_class($key).':'.$key->name;
            }

            return $key;
        }, $this->key));
    }

    /**
     * Attempt to run the callback.
     *
     * @template TResult
     *
     * @param  \Closure():TResult  $callback
     * @return TResult|TDefault|false
     */
    public function run(Closure $callback): mixed
    {
        $key = $this->parseKey();

        if ($this->rateLimiter->tooManyAttempts($key, 1)) {
            return value($this->default);
        }

        $result = $callback();

        $this->rateLimiter->increment($key, $this->ttl);

        return $result;
    }

    /**
     * Checks if the callback was executed.
     */
    public function wasExecuted(): bool
    {
        return $this->rateLimiter->tooManyAttempts($this->parseKey(), 1);
    }

    /**
     * Checks if the callback was not executed.
     */
    public function wasNotExecuted(): bool
    {
        return !$this->wasExecuted();
    }

    /**
     * Returns the amount of seconds the callback can be executed again.
     */
    public function availableIn(): int
    {
        return $this->rateLimiter->availableIn($this->parseKey());
    }

    /**
     * Returns the DateTime instance the callback can be executed again.
     */
    public function readyAt(): Carbon
    {
        return Carbon::now()->addSeconds($this->availableIn());
    }
}
