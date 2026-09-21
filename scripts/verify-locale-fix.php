<?php

/**
 * Regression check for bs-community/blessing-skin-server#684:
 * `?lang=` must no longer persist an illegal locale into users.locale,
 * poisoned accounts must heal on their next visit, and
 * JavaScript::generate() must not throw for locales without language files.
 *
 * Creates a throwaway account inside a rolled-back transaction; it never
 * touches real rows. Run from the project root:
 *   php scripts/verify-locale-fix.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Listeners\SetAppLocale;
use App\Models\User;
use App\Services\Translations\JavaScript;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL')."  $name\n";
    if (!$ok) {
        $failures++;
    }
}

$email = 'locale-fix-test@example.invalid';

try {
    DB::beginTransaction();

    User::where('email', $email)->delete();
    $user = new User();
    $user->email = $email;
    $user->nickname = 'LocaleFixTest';
    $user->score = 0;
    $user->avatar = 0;
    $user->permission = User::NORMAL;
    $user->ip = '127.0.0.1';
    $user->password = app('cipher')->hash('not-a-real-password', config('secure.salt'));
    $user->register_at = now();
    $user->last_sign_at = now();
    $user->save();

    $listener = fn (Request $request) => (new SetAppLocale($request))->handle(new Authenticated('web', $user));

    // 1. Attack: an illegal ?lang must never be persisted.
    $listener(Request::create('/', 'GET', ['lang' => 'HACKED']));
    check('illegal ?lang is rejected', $user->locale === null);

    // 2. Non-string ?lang must be rejected too.
    $listener(Request::create('/', 'GET', ['lang' => ['evil']]));
    check('array ?lang is rejected', $user->locale === null);

    // 3. Legal values still work; aliases are normalised.
    $listener(Request::create('/', 'GET', ['lang' => 'en_US']));
    check('?lang=en_US persists as en', $user->locale === 'en');
    $listener(Request::create('/', 'GET', ['lang' => 'zh_TW']));
    check('?lang=zh_TW persists', $user->locale === 'zh_TW');

    // 4. An account poisoned before the fix heals on its next visit.
    $user->locale = 'HACKED';
    $user->save();
    app()->setLocale(config('app.fallback_locale'));
    $listener(Request::create('/', 'GET'));
    check('poisoned locale ignored, fallback kept', app()->getLocale() === config('app.fallback_locale'));
    check('poisoned locale healed to null', $user->refresh()->locale === null);

    // 5. Defence in depth: generate() must survive a file-less locale.
    $js = app(JavaScript::class);
    $url = $js->generate('HACKED');
    check('generate() survives illegal locale', is_string($url) && str_contains($url, 'lang/HACKED.js'));
    $junk = public_path('lang/HACKED.js');
    if (is_file($junk)) {
        unlink($junk);
    }

    // 6. A real locale must still generate normally.
    $url = $js->generate('zh_CN');
    check('generate() still works for zh_CN', is_string($url) && str_contains($url, 'lang/zh_CN.js'));
} finally {
    DB::rollBack();
    User::where('email', $email)->delete();
}

exit($failures > 0 ? 1 : 0);
