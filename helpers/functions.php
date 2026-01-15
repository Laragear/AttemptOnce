<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Onceable;
use Laragear\AttemptOnce\Builder;
use const DEBUG_BACKTRACE_PROVIDE_OBJECT as OBJECT;

if (!function_exists('attempt_once')) {
    /**
     * Execute a callback once every 60 seconds, or build a callable-once.
     *
     * @template TReturn
     *
     * @param  (\Closure():TReturn)|\StringBackedEnum|string  $callback
     * @return ($callback is string ? \Laragear\AttemptOnce\Builder : TReturn|false)
     */
    function attempt_once(
        BackedEnum|Closure|string $callback,
        DateTimeInterface|DateInterval|BackedEnum|Model|string|array|int ...$append
    ): mixed {
        if (! $callback instanceof Closure) {
            return app(Builder::class, [
                'key' => [is_string($callback) ? $callback : $callback->value, ...$append],
            ]);
        }

        $builder = app(Builder::class, [
            'key' => [Builder::KEY_PREPEND.':'.Onceable::tryFromTrace(debug_backtrace(OBJECT, 2), $callback)->hash],
        ]);

        if (isset($append[0])) {
            $builder->for($append[0]);
        }

        return $builder->run($callback);
    }
}
