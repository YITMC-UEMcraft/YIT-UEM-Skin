<?php

/**
 * Regression check for the free player quota (免费角色额度) feature:
 * every user — new or long-registered — can create up to `free_player_quota`
 * (default 5) players without spending scores; beyond the quota the regular
 * score cost applies; deleting a player within the quota never returns scores
 * (no score farming), while paid players still do.
 *
 * Runs inside a rolled-back transaction with a throwaway account, so real
 * rows are never touched. Run from the project root as www-data:
 *   sudo -u www-data php scripts/verify-free-quota.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\PlayerController;
use App\Models\Player;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL')."  $name".(!$ok && $detail !== '' ? "  << $detail" : '')."\n";
    if (!$ok) {
        $failures++;
    }
}

/** Probe player names must be unique per run to avoid colliding with real players. */
function uniqueName(int $i): string
{
    return 'fqprobe'.substr((string) time(), -5).$i;
}

/** Remove probe players, in case a failure leaves rows behind mid-transaction. */
function cleanup(): void
{
    DB::table('players')->where('name', 'like', 'fqprobe%')->delete();
}

$email = 'free-quota-test@example.invalid';
$quota = max(0, (int) option('free_player_quota'));

try {
    DB::beginTransaction();

    $user = new User();
    $user->email = $email;
    $user->nickname = 'FreeQuotaTest';
    $user->score = 0;
    $user->avatar = 0;
    $user->permission = User::NORMAL;
    $user->ip = '127.0.0.1';
    $user->password = app('cipher')->hash('not-a-real-password', config('secure.salt'));
    $user->register_at = now();
    $user->last_sign_at = now();
    $user->save();

    // The student-verification plugin gates player.adding on verified students;
    // simulate a verified student for the probe account (rolled back below).
    DB::table('student_verifications')->insert([
        'user_id' => $user->uid,
        'school' => 'yit',
        'student_id' => 'fqtest000',
        'student_name' => 'FreeQuotaProbe',
        'verified' => true,
        'verified_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $controller = app(PlayerController::class);
    Auth::login($user);

    $add = function (string $name) use ($controller): array {
        $request = Request::create('/user/player/add', 'POST', ['name' => $name]);
        app()->instance('request', $request);
        try {
            $result = $controller->add(
                $request,
                app(Illuminate\Contracts\Events\Dispatcher::class),
                app(Blessing\Filter::class)
            );
        } catch (Illuminate\Validation\ValidationException $e) {
            return ['code' => -1, 'message' => $e->validator->errors()->first()];
        } catch (\Throwable $e) {
            return ['code' => -3, 'message' => get_class($e).': '.$e->getMessage()];
        }

        if ($result instanceof Illuminate\Http\JsonResponse) {
            $data = $result->getData(true);

            return ['code' => (int) $data['code'], 'message' => (string) $data['message']];
        }

        return ['code' => -2, 'message' => 'unexpected response'];
    };

    // 1. Zero scores + zero players: the free quota applies (core requirement).
    $res = $add(uniqueName(1));
    check('zero-score user creates player for free', $res['code'] === 0, $res['message']);

    // 2. Fill the whole quota; every add must be free.
    $count = Player::where('uid', $user->uid)->count();
    while ($count < $quota) {
        $res = $add(uniqueName($count + 1));
        check("free player #".($count + 1)." of $quota (no scores spent)", $res['code'] === 0);
        $count = Player::where('uid', $user->uid)->count();
    }

    // 3. Beyond the quota, zero scores must be rejected with lack-score.
    $res = $add(uniqueName($quota + 1));
    check('beyond quota, zero-score add is rejected with code 7', $res['code'] === 7);
    check(
        'rejection message is the lack-score message',
        $res['message'] === trans('user.player.add.lack-score')
    );

    // 4. With scores, creating beyond the quota is paid.
    $user->refresh();
    $user->score = 1000;
    $user->save();
    $count = Player::where('uid', $user->uid)->count();
    $res = $add(uniqueName($count + 1));
    check('paid player beyond quota succeeds', $res['code'] === 0);
    $user->refresh();
    check('paid player deducts scores', (int) $user->score === 1000 - (int) option('score_per_player'));

    // 5. Deleting the paid player returns scores.
    $paidPlayer = Player::where('uid', $user->uid)->orderByDesc('pid')->first();
    app(PlayerController::class)->delete(
        app(Illuminate\Contracts\Events\Dispatcher::class),
        app(Blessing\Filter::class),
        $paidPlayer
    );
    $user->refresh();
    check('deleting the paid player returns scores', (int) $user->score === 1000);

    // 6. Deleting within the quota returns nothing (no score farming).
    $user->score = 500;
    $user->save();
    $freePlayer = Player::where('uid', $user->uid)->orderBy('pid')->first();
    app(PlayerController::class)->delete(
        app(Illuminate\Contracts\Events\Dispatcher::class),
        app(Blessing\Filter::class),
        $freePlayer
    );
    $user->refresh();
    check('deleting within quota returns no scores', (int) $user->score === 500);

    // 7. The players page passes the remaining quota to the UI.
    $view = app(PlayerController::class)->index(app(Blessing\Filter::class));
    $extra = $view->getData()['extra'];
    check('index passes free_quota to the UI', is_numeric($extra['free_quota']));
} finally {
    cleanup();
    DB::rollBack();
    User::where('email', $email)->delete();
}

exit($failures > 0 ? 1 : 0);
