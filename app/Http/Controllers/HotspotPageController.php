<?php

namespace App\Http\Controllers;

use App\Models\PortalSetting;
use App\Models\Router;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Http\Response;

/**
 * Serves the pages that get installed onto a MikroTik's HotSpot.
 *
 * The router fetches these at setup time and keeps them in its own file system, so they are
 * plain HTML carrying RouterOS `$(...)` placeholders rather than anything dynamic. Every one
 * of them is what a customer sees when something has gone wrong or has just gone right, and a
 * device missing them serves MikroTik's own — which asks for a username and password no
 * customer of ours has ever been given.
 *
 * They are styled inline and load nothing external, deliberately. A phone looking at these is
 * either unauthenticated or has just been disconnected: a stylesheet on another host is one
 * that will not arrive.
 *
 * Branding is baked in when the router fetches them, so an ISP who changes their colours
 * re-runs the setup to see it on these pages. The portal itself, which is where customers
 * spend their time, updates immediately.
 */
class HotspotPageController extends Controller
{
    /**
     * Every page the platform can hand a router, and the view behind it.
     *
     * @var array<string, string>
     */
    public const PAGES = [
        // Where an unauthenticated customer lands. Hands them to the portal.
        'login.html' => 'hotspot.login',
        // Shown the instant a login succeeds, before the router forwards them on.
        'alogin.html' => 'hotspot.alogin',
        // A login that failed — a spent code, a wrong one, a package already in use.
        'error.html' => 'hotspot.error',
        // After signing out.
        'logout.html' => 'hotspot.logout',
        // What is left of what they bought.
        'status.html' => 'hotspot.status',
        // The router's own redirects. Without these it serves a blank page mid-hop.
        'rlogin.html' => 'hotspot.rlogin',
        'redirect.html' => 'hotspot.redirect',
    ];

    public function __invoke(string $portalKey, string $page): Response
    {
        // Public and unauthenticated, like the portal itself: the key is what identifies the
        // device, so the lookup deliberately spans every ISP and then binds one.
        $router = Tenancy::withoutScope(
            fn (): ?Router => Router::query()->withoutGlobalScopes()->where('portal_key', $portalKey)->first(),
        );

        abort_if($router === null, 404);
        abort_unless(isset(self::PAGES[$page]), 404);

        return Tenancy::forTenant($router->tenant, function () use ($router, $page): Response {
            $branding = PortalSetting::current();

            return response()
                ->view(self::PAGES[$page], [
                    'portalUrl' => $router->portalUrl(),
                    'router' => $router,
                    'branding' => $branding,
                    'brand' => $branding->displayName($router->tenant),
                    'colour' => $branding->primary(),
                    'onColour' => $branding->onPrimary(),
                ])
                ->header('Content-Type', 'text/html; charset=utf-8');
        });
    }
}
