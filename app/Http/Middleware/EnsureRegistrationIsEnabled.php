<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Service\InvitationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hides the sign-up screen when APP_ENABLE_REGISTRATION is off.
 *
 * This runs on the whole Fortify route group and acts only on the two register routes.
 * Dropping Features::registration() from config/fortify.php would be the obvious way to do
 * this, but it takes the named route with it, and both the invitation flow and Ziggy's
 * client side route('register') expect that name to resolve.
 *
 * Invitations are the one way past it: an invited person still has to create an account
 * before they can join, so OrganizationInvitationController flags the session on the way to
 * the screen. That only reveals the form - which email may actually register is decided
 * against the invitations table in CreateNewUser, which is the enforcement point.
 */
class EnsureRegistrationIsEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        // Matched on the URI rather than the route name because Fortify names the GET route
        // 'register' and leaves the POST that submits it unnamed. Both need to be gone.
        if (! ($route instanceof Route) || $route->uri() !== 'register') {
            return $next($request);
        }

        if (config('app.enable_registration')) {
            return $next($request);
        }

        if (app(InvitationService::class)->isInviteeRegistering()) {
            return $next($request);
        }

        throw new NotFoundHttpException;
    }
}
