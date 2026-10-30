<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRegistrationOpen
{
    /**
     * Close public self-registration unless REGISTRATION_OPEN is explicitly true.
     *
     * Attached only to POST /api/auth/register so it runs before FormRequest
     * validation (empty bodies get 403, not 422) and cannot affect login.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('auth.registration_open', false)) {
            return response()->json([
                'message' => 'ปิดรับสมัครสมาชิกแล้ว',
            ], 403);
        }

        return $next($request);
    }
}
