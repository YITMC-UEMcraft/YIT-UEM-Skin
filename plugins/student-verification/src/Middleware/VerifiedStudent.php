<?php

namespace StudentVerification\Middleware;

use Closure;
use Illuminate\Http\Request;
use StudentVerification\Models\StudentVerification;

class VerifiedStudent
{
    public function handle(Request $request, Closure $next)
    {
        $protectedPaths = [
            'user/player',
            'user/closet',
            'skinlib/upload',
        ];

        $path = $request->path();
        $needsProtection = false;
        foreach ($protectedPaths as $protected) {
            if (str_starts_with($path, $protected)) {
                $needsProtection = true;
                break;
            }
        }

        if ($needsProtection && auth()->check()) {
            $verified = StudentVerification::isUserVerified(auth()->user()->uid);
            if (!$verified) {
                if ($request->expectsJson() || $request->isMethod('POST')) {
                    return response()->json([
                        'code' => 1,
                        'message' => trans('StudentVerification::student-verification.verify_required'),
                    ], 403);
                }
                return redirect()->route('student-verification.page');
            }
        }

        return $next($request);
    }
}
