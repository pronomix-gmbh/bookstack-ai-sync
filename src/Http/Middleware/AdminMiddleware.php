<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            abort(403);
        }

        if (method_exists($user, 'isSystemAdmin') && $user->isSystemAdmin()) {
            return $next($request);
        }

        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return $next($request);
        }

        if (Gate::check('settings-manage') || Gate::check('manage-settings') || Gate::check('admin')) {
            return $next($request);
        }

        abort(403);
    }
}
