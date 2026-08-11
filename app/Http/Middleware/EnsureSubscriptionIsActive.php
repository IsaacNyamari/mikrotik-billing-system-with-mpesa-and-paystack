<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Facades\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds an ISP out of the console until they have paid for it.
 *
 * Runs after ResolveTenant, so the ISP is already known. Three things it deliberately does
 * not do:
 *
 * - **It never blocks the billing screen.** An ISP locked out of the page where they pay can
 *   only get back in by telephoning somebody, which turns every late payment into support.
 * - **It never touches super admins.** They belong to no ISP and are the people who have to
 *   put a lapsed account back in order.
 * - **It never touches the captive portal or the callbacks.** Those are outside `auth`
 *   entirely: a customer who has paid for an hour of wifi is not a party to the argument
 *   between their ISP and the platform, and a webhook that stopped arriving would take the
 *   ISP's own collections down with it.
 */
class EnsureSubscriptionIsActive
{
    /**
     * Reachable whatever the ISP owes: paying, leaving, and the account screens that are the
     * user's own rather than the ISP's.
     *
     * @var array<int, string>
     */
    protected array $alwaysAllowed = [
        'billing.*',
        'logout',
        'profile.edit',
        'appearance.edit',
        'security.edit',
        'tenants.select',
        'well-known.passkeys',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->isSuperAdmin()) {
            return $next($request);
        }

        if ($request->routeIs(...$this->alwaysAllowed)) {
            return $next($request);
        }

        $tenant = Tenancy::tenant();

        if ($tenant === null || $tenant->billing()->permitsAccess()) {
            return $next($request);
        }

        // A Livewire update cannot follow a redirect into a new page, and answering one with
        // HTML would replace a fragment with a whole document. Livewire understands this
        // header and navigates the browser itself.
        if ($request->hasHeader('X-Livewire')) {
            return response('', 403)->header('X-Livewire-Redirect', route('billing.index'));
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('A subscription payment is due.')], 402);
        }

        return redirect()->route('billing.index');
    }
}
