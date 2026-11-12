<?php

namespace App\Http\Middleware;

use App\Modules\HealthService\Services\HealthSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A health tool switched off in Health › Settings accepts no new entries (403, code
 * health_tool_disabled). Reading and deleting what the customer already saved keeps working.
 * Usage: ->middleware(EnsureHealthToolEnabled::class.':bmi')
 */
class EnsureHealthToolEnabled
{
    public function handle(Request $request, Closure $next, string $tool): Response
    {
        if (!HealthSettings::current()->toolEnabled($tool)) {
            return response()->json([
                'message' => api_trans('health.messages.tool_disabled'),
                'code' => 'health_tool_disabled',
                'tool' => $tool,
            ], 403);
        }

        return $next($request);
    }
}
