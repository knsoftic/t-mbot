<?php

namespace App\Http\Middleware;

use App\Services\PlanLimiter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customers can use the REST API only when their plan includes it.
 */
class EnsurePlanAllowsApi
{
    public function __construct(private readonly PlanLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $this->limiter->allows($request->user(), 'allow_api')) {
            return response()->json([
                'message' => 'REST API access is not included in your plan. Upgrade on the Billing page to use the API.',
            ], 403);
        }

        return $next($request);
    }
}
