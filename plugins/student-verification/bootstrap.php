<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Hook;
use App\Services\Plugin;
use Blessing\Filter;
use Blessing\Rejection;
use Illuminate\Contracts\Events\Dispatcher;
use StudentVerification\Models\StudentVerification;

return function (Plugin $plugin): void {
    // Register routes
    Hook::addRoute(function ($router) {
        $router->get('student-verification', 'StudentVerification\Controllers\VerificationController@showVerifyPage');
        $router->post('student-verification/verify', 'StudentVerification\Controllers\VerificationController@verify');
        $router->get('student-verification/status', 'StudentVerification\Controllers\VerificationController@status');
    });

    // Register middleware
    Hook::pushMiddleware(StudentVerification\Middleware\VerifiedStudent::class);

    // Register filters to block unverified users from skin operations
    $filters = [
        'can_upload_texture',
        'can_delete_texture',
        'can_update_texture_privacy',
        'can_update_texture_name',
        'can_update_texture_type',
    ];

    foreach ($filters as $filter) {
        resolve(Filter::class)->add($filter, function ($can, $user) {
            if (!$can) {
                return $can;
            }

            $user = $user instanceof User ? $user : auth()->user();

            if (!$user) {
                return $can;
            }

            $verified = StudentVerification::where('user_id', $user->uid)->value('verified');

            if (!$verified) {
                return new Rejection(trans('StudentVerification::student-verification.verify_required'));
            }

            return $can;
        });
    }

    // Block player creation for unverified users
    // $playerName: string, $user: User (dispatched as [$playerName, $user] in PlayerController)
    resolve(Dispatcher::class)->listen('player.adding', function (string $playerName, User $user) {
        $verified = StudentVerification::where('user_id', $user->uid)->value('verified');

        if (!$verified) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                trans('StudentVerification::student-verification.verify_required')
            );
        }
    });

    // Add user menu item
    Hook::addUserMenu(
        'student-verification',
        [
            'title' => trans('StudentVerification::student-verification.title'),
            'link' => 'student-verification',
            'icon' => 'graduation-cap',
        ]
    );
};
