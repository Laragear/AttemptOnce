# Once
[![Latest Version on Packagist](https://img.shields.io/packagist/v/laragear/attempt-once.svg)](https://packagist.org/packages/laragear/attempt-once)
[![Latest stable test run](https://github.com/Laragear/AttemptOnce/actions/workflows/php.yml/badge.svg?branch=1.x)](https://github.com/Laragear/AttemptOnce/actions/workflows/php.yml)
[![Codecov coverage](https://codecov.io/gh/Laragear/AttemptOnce/branch/1.x/graph/badge.svg?token=I8c1hGBtqc)](https://codecov.io/gh/Laragear/AttemptOnce)
[![Maintainability](https://qlty.sh/gh/Laragear/projects/AttemptOnce/maintainability.svg)](https://qlty.sh/gh/Laragear/projects/AttemptOnce)
[![Sonarcloud Status](https://sonarcloud.io/api/project_badges/measure?project=Laragear_AttemptOnce&metric=alert_status)](https://sonarcloud.io/dashboard?id=Laragear_AttemptOnce)
[![Laravel Octane Compatibility](https://img.shields.io/badge/Laravel%20Octane-Compatible-success?style=flat&logo=laravel)](https://laravel.com/docs/12.x/octane#introduction)

Run and manage callbacks across multiple app instances, atomically.

```php
use App\Mails\Newsletter;

attempt_once(function () => {
    Newsletter::send();
});
```

## Become a sponsor

[![](.github/assets/support.png)](https://github.com/sponsors/DarkGhostHunter)

Your support allows me to keep this package free, up-to-date and maintainable.

## Requirements

* PHP 8.4
* Laravel 11 or later

## Installation

You can install the package via Composer. 

```bash
composer require laragear/once
```

## How does this work?

It uses Laravel's Rate Limiter behind the scenes to allow callbacks to run once. This way all app instances are _aware_ of the callback execution, ensuring only runs once inside a given window of time, which is 1 minute by default. 

## Usage 

The simplest way to use `attempt_once()` is to just issue a callback, which will be executed only once for 30 seconds. A key will be computed from the callback as `laragear|attempt_once:{hash}` based on the callback unique position in your application.

```php
attempt_once(function () {
    // ... do something.
})
```

Adding a second argument after the callback will set the amount of time to "hold" the execution. You may use a `DateTimeInterface` instance, a `DateInterval` object or just an amount of seconds.

```php
attempt_once(function () {
    // ... do something.
}, now()->addMinute())
```

> [!IMPORTANT]
> 
> When using a single callback, the hash computed takes into account the place where is called. Duplicating the code **will make both callbacks hashes different**, even if they do exactly the same.

While the above may suffice for most simple scenarios, you may have more granular control on the execution by calling `attempt_once()` with a key to identify the execution, or a string backed enum. You will receive a builder instance to configure how to run it:

```php
$result = attempt_once('send-email')->in(60)->run(function () {
    // ...
});

use App\Enums\EmailType;

$result = attempt_once(EmailType::Transactional)->in(60)->run(function () {
    // ...
});

```

### Custom key

The `attempt_once()` method allows to identify the callback to run once with a simple string. With a constant string, you may [check the execution status elsewhere in your app](#checking-execution).

```php
attempt_once('send-email')->run(function () {
    // ...
});
```

You can issue multiple strings or arrays, which is great when you need to separate executions programmatically. The resulting key will be concatenated using `|`. If you pass an array, the key and value will be concatenated as `{key}:{value}`.

```php
// "send-email|transaction|user:1"
attempt_once('send-email', 'transaction', ['user' => 1])->run(function () {
    // ...
});
```

If you pass an Eloquent Model as second parameter, it will be used to identify the callback as `{key}|{class}:{key}`, essentially making the callback unique for each model you pass.

```php
use App\Models\User;

$user = User::find(1);

// "send-email|\App\Models\User:1"
attempt_once('send-email', $user)->run(function () {
    // ...
});
```

You may also use Backed Enums. The key will be appended using the name of the class and its value (a number or a string).

```php
use App\Models\User;
use App\Enums\EmailType;

$user = User::find(1);

// "send-email|\App\Enums\EmailType:3|\App\Models\User:1"
attempt_once('send-email', EmailType::Transactional)->run(function () {
    // ...
});
```

### Time

You may change the window of time using `for()` with the amount of seconds. You may also use `DateTimeInterface` instance, or a `DateInterval` instance.

```php
use function Illuminate\Support\seconds;

// Using seconds
attempt_once('send-email')->for(30)->run(function () {
    // ...
});

// Using a DateTimeInterface, like a Carbon instance
attempt_once('send-email')->for(now()->addSeconds(30))->run(function () {
    // ...
});

// Using a DateInterval, like the `seconds()` helper.
attempt_once('send-email')->for(seconds(30))->run(function () {
    // ...
});
```

> [!IMPORTANT]
> 
> You _may_ change the window of time programmatically, but consider that only previous successful execution will set the time. Since subsequent executions won't run, the time won't be updated.

## Callback result

When the callback runs, its result will be returned. When the callback does not run because it's rate limited, `false` will be returned.

```php
use App\Models\Article;
use Illuminate\Support\Facades\Route;

Route::get('/articles/all', function () {
    $articles = attempt_once(function () {
        return Article::limit(10)->latest()->get();
    });
    
    // Check the callback was not executed
    if ($articles === false) {
        return 'You need to wait 30 seconds to retrieve more articles';
    }
    
    return $articles;
});
```

### Default result

Sometimes you will want to return another value rather than `false` if the callback is not executed. For that, use the `default()` method.

```php
use App\Models\Article;
use function Illuminate\Support\minutes;

$collection = attempt_once('latest-articles')
    ->default(new Collection())
    ->run(function () {
        return Article::limit(10)->latest()->get();
    });
```

### Checking execution

> [!IMPORTANT]
> 
> To check the execution of the callback, a [key](#custom-key) is required.

To check if the callback was executed, you may use the `wasExecuted()` and `wasNotExecuted()` methods.

```php
if (attempt_once('send-email')->wasExecuted()) {
    return 'An email was already sent!';
}

if (attempt_once('send-email')->wasNotExecuted()) {
    return 'You can send a new email now';
}
```

To check how many seconds remain to execute the callback again, use `availableIn()`, or `readyAt()` to get a Carbon instance.

```php
$seconds = attempt_once('send-email')->availableIn();

echo "You can send a new email in $seconds seconds.";

$datetime = attempt_once('send-email')->readyAt();
 
echo "You can send a new email at $datetime."
```

### Cache Store

By default, Laravel's Rate Limiter uses the application default cache store. If you want to change the cache store to use, [configure the Rate Limiter in your app configuration](https://laravel.com/docs/12.x/rate-limiting#cache-configuration).

## Laravel Octane compatibility

- There are no singletons using a stale app instance.
- There are no singletons using a stale config instance.
- There are no singletons using a stale request instance.
- There are no static properties written during a request.

There should be no problems using this package with Laravel Octane.

## Security

If you discover any security-related issues, issue a [Security Advisory](https://github.com/Laragear/Once/security/advisories/new).

# License

This specific package version is licensed under the terms of the [MIT License](LICENSE.md), at the time of publishing.

[Laravel](https://laravel.com) is a Trademark of [Taylor Otwell](https://github.com/TaylorOtwell/). Copyright © 2011-2026 Laravel LLC.
