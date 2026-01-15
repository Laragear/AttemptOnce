<?php

namespace Tests;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laragear\AttemptOnce\Builder;
use Mockery as m;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use function attempt_once;
use function now;

class FunctionTest extends TestCase
{
    public function test_executes_once_with_hashed_function_as_key(): void
    {
        $this->mock(RateLimiter::class, function (MockInterface $mock) {
            $mock->expects('tooManyAttempts')->withArgs(function (string $key, int $attempts): true {
                static::assertStringStartsWith(Builder::KEY_PREPEND.':', $key);
                static::assertSame(1, $attempts);

                return true;
            })->andReturnFalse();

            $mock->expects('increment')->withArgs(function (string $key, int $ttl): true {
                static::assertStringStartsWith(Builder::KEY_PREPEND.':', $key);
                static::assertSame(30, $ttl);

                return true;
            });
        });

        static::assertTrue(attempt_once(fn() => true));
    }

    public function test_executes_once_with_custom_ttl(): void
    {
        $customTtl = now()->addMinutes(30);

        $this->mock(RateLimiter::class, function (MockInterface $mock) use ($customTtl): void {
            $mock->expects('tooManyAttempts')->andReturnFalse();

            $mock->expects('increment')->withArgs(function (string $key, Carbon $ttl) use ($customTtl): true {
                static::assertStringStartsWith(Builder::KEY_PREPEND.':', $key);
                static::assertSame($customTtl, $ttl);

                $hash = Str::after($key, ':');

                // Check the function is a xxh128 hash.
                static::assertTrue(strlen($hash) === 32 && ctype_xdigit($hash));

                return true;
            });
        });

        static::assertTrue(attempt_once(fn () => true, $customTtl));
    }

    public function test_does_not_executes_if_already_executed(): void
    {
        $this->mock(RateLimiter::class, function (MockInterface $mock) {
            $mock->expects('tooManyAttempts')->andReturnTrue();
            $mock->expects('increment')->never();
        });

        static::assertFalse(attempt_once(fn() => true));
    }

    public function test_returns_builder_with_custom_key(): void
    {
        $this->mock(RateLimiter::class, function (MockInterface $mock) {
            $mock->expects('tooManyAttempts')->with('test', 1)->andReturnFalse();
            $mock->expects('increment')->with('test', 30);
        });

        $builder = attempt_once('test');

        static::assertInstanceOf(Builder::class, $builder);

        static::assertTrue(attempt_once('test')->run(fn () => true));
    }

    public function test_returns_builder_with_backed_enum(): void
    {
        $this->mock(RateLimiter::class, function (MockInterface $mock) {
            $mock->expects('tooManyAttempts')->with('testType1', 1)->andReturnFalse();
            $mock->expects('increment')->with('testType1', 30);
        });

        $builder = attempt_once('test');

        static::assertInstanceOf(Builder::class, $builder);

        static::assertTrue(attempt_once(Fixtures\TestType::TestType1)->run(fn () => true));
    }

    public function test_builder_key_uses_appended_values(): void
    {
        $this->mock(RateLimiter::class, function (MockInterface $mock) {
            $mock->expects('tooManyAttempts')->andReturnFalse();

            $mock->expects('increment')->withArgs(function (string $key, int $ttl): true {
                static::assertStringStartsWith(
                    'test|foo:bar|' . Fixtures\TestType::class . ':TestType1|'. User::class . ':99', $key
                );
                static::assertSame(30, $ttl);

                return true;
            });
        });

        $user = (new User())->forceFill(['id' => 99]);

        $result = attempt_once('test', ['foo' => 'bar'], Fixtures\TestType::TestType1, $user)->run(fn () => true);

        static::assertTrue($result);
    }

    public function test_builder_uses_default_ttl(): void
    {
        $this->mock(RateLimiter::class, function (MockInterface $mock) {
            $mock->expects('tooManyAttempts')->andReturnFalse();

            $mock->expects('increment')->withArgs(function (string $key, int $ttl): true {
                static::assertStringStartsWith('test', $key);
                static::assertSame(30, $ttl);

                return true;
            });
        });

        static::assertTrue(attempt_once('test')->run(fn () => true));
    }

    public function test_builder_uses_custom_ttl(): void
    {
        $customTtl = now()->addMinutes(30);

        $this->mock(RateLimiter::class, function (MockInterface $mock) use ($customTtl): void {
            $mock->expects('tooManyAttempts')->andReturnFalse();

            $mock->expects('increment')->withArgs(function (string $key, Carbon $ttl) use ($customTtl): true {
                static::assertStringStartsWith('test', $key);
                static::assertSame($customTtl, $ttl);

                return true;
            });
        });

        static::assertTrue(attempt_once('test')->for($customTtl)->run(fn () => true));
    }

    public function test_builder_uses_or_value(): void
    {
        $this->mock(RateLimiter::class, function (MockInterface $mock) {
            $mock->expects('tooManyAttempts')->andReturnTrue();
            $mock->expects('increment')->never();
        });

        static::assertSame('executed', attempt_once('test')->or(fn () => 'executed')->run(fn () => true));
    }

    public function test_builder_was_executed(): void
    {
        $this->mock(RateLimiter::class, function (MockInterface $mock) {
            $mock->expects('tooManyAttempts')->with('test', 1)->andReturnTrue()->twice();
            $mock->expects('tooManyAttempts')->with('test', 1)->andReturnFalse()->twice();
        });

        static::assertTrue(attempt_once('test')->wasExecuted());
        static::assertFalse(attempt_once('test')->wasNotExecuted());

        static::assertFalse(attempt_once('test')->wasExecuted());
        static::assertTrue(attempt_once('test')->wasNotExecuted());
    }

    public function test_builder_available_in(): void
    {
        $this->mock(RateLimiter::class, function (MockInterface $mock) {
            $mock->expects('availableIn')->with('test')->andReturn(30);
        });

        static::assertSame(30, attempt_once('test')->availableIn());
    }

    public function test_builder_ready_at(): void
    {
        $this->freezeSecond();

        $this->mock(RateLimiter::class, function (MockInterface $mock) {
            $mock->expects('availableIn')->with('test')->andReturn(30);
        });

        static::assertEquals(now()->addSeconds(30), attempt_once('test')->readyAt());
    }
}
