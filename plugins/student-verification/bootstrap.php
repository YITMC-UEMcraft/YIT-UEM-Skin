<?php

declare(strict_types=1);

use App\Events\RenderingBadges;
use App\Models\User;
use App\Services\Hook;
use App\Services\Plugin;
use Blessing\Filter;
use Blessing\Rejection;
use Illuminate\Contracts\Events\Dispatcher;
use StudentVerification\Models\StudentVerification;

return function (Plugin $plugin): void {
    // Register routes (web middleware group is required for session & CSRF)
    Hook::addRoute(function ($router) {
        $router->group(['middleware' => 'web'], function () use ($router) {
            $router->get('student-verification', 'StudentVerification\Controllers\VerificationController@showVerifyPage')
                ->name('student-verification.page');
            $router->post('student-verification/verify', 'StudentVerification\Controllers\VerificationController@verify')
                ->name('student-verification.verify');
            $router->get('student-verification/status', 'StudentVerification\Controllers\VerificationController@status')
                ->name('student-verification.status');

            $router->post('student-verification/uem/qr/create', 'StudentVerification\Controllers\VerificationController@uemQrCreate')
                ->name('student-verification.uem-qr-create');
            $router->get('student-verification/uem/qr/status', 'StudentVerification\Controllers\VerificationController@uemQrStatus')
                ->name('student-verification.uem-qr-status');
            $router->get('student-verification/uem/qr/image', 'StudentVerification\Controllers\VerificationController@uemQrImage')
                ->name('student-verification.uem-qr-image');
            $router->get('privacy', 'StudentVerification\Controllers\VerificationController@privacy')
                ->name('student-verification.privacy');
            $router->post('student-verification/code', 'StudentVerification\Controllers\VerificationController@codeVerify')
                ->name('student-verification.code-verify');
            $router->get('admin/student-verification/codes', 'StudentVerification\Controllers\CodeAdminController@index')
                ->name('student-verification.admin-codes');
            $router->post('admin/student-verification/codes', 'StudentVerification\Controllers\CodeAdminController@store')
                ->name('student-verification.admin-codes.store');
            $router->post('admin/student-verification/codes/{id}/revoke', 'StudentVerification\Controllers\CodeAdminController@revoke')
                ->name('student-verification.admin-codes.revoke');
        });
    });

    // Register middleware
    Hook::pushMiddleware(\StudentVerification\Middleware\VerifiedStudent::class);

    // Register filters to block unverified users from skin operations
    $filters = [
        'can_upload_texture',
        'can_delete_texture',
        'can_update_texture_privacy',
        'can_update_texture_name',
        'can_update_texture_type',
    ];

    foreach ($filters as $filter) {
        resolve(Filter::class)->add($filter, function ($can) {
            if (!$can) {
                return $can;
            }

            if (!auth()->check()) {
                return $can;
            }

            $verified = StudentVerification::where('user_id', auth()->user()->uid)->value('verified');

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

    // Add admin menu item (invitation code management)
    Hook::addMenuItem(
        'admin',
        6,
        [
            'title' => 'StudentVerification::student-verification.admin_codes_menu',
            'link' => 'admin/student-verification/codes',
            'icon' => 'fa-ticket-alt',
        ]
    );

    // Add user menu item (user center sidebar)
    Hook::addMenuItem(
        'user',
        3,
        [
            'title' => 'StudentVerification::student-verification.menu_title',
            'link' => 'student-verification',
            'icon' => 'fa-graduation-cap',
        ]
    );

    // Show verification status as a badge in the sidebar user panel
    resolve(Dispatcher::class)->listen(RenderingBadges::class, function (RenderingBadges $event) {
        if (!auth()->check()) {
            return;
        }

        $verification = StudentVerification::forUser(auth()->user()->uid);
        $verified = $verification !== null && $verification->verified;

        $event->badges[] = $verified
            ? [
                'text' => trans('StudentVerification::student-verification.badge_verified'),
                'color' => 'success',
            ]
            : [
                'text' => trans('StudentVerification::student-verification.badge_unverified'),
                'color' => 'warning',
            ];
    });
};