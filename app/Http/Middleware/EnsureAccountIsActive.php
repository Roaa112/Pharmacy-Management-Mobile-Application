<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deactivated customer's tokens stop working immediately (403, code account_inactive); a
 * deactivated admin is signed out of the dashboard. Accounts are active by default, so nobody
 * is affected until an admin deactivates them.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // API: only requests that carry a token resolve a user here.
        $user = $request->is('api/*') && ($request->bearerToken() || Auth::guard('sanctum')->hasUser())
            ? Auth::guard('sanctum')->user()
            : null;

        if ($user instanceof User && !$user->isActive()) {
            return response()->json(['message' => 'This account has been deactivated.', 'code' => 'account_inactive'], 403);
        }

        $admin = Auth::guard('admin')->user();
        if ($admin instanceof Admin && $admin->is_active === false) {
            Auth::guard('admin')->logout();

            return redirect()->route('login')->with('error', 'This admin account has been deactivated.');
        }

        return $next($request);
    }
}
