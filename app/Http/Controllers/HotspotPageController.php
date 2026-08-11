<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Http\Response;

/**
 * Serves the pages that get installed onto a MikroTik's HotSpot.
 *
 * The router fetches these over HTTP at install time and stores them in its own file system,
 * so they are plain HTML carrying RouterOS $(...) placeholders rather than anything dynamic.
 */
class HotspotPageController extends Controller
{
    public function __invoke(string $portalKey, string $page): Response
    {
        // Public and unauthenticated, like the portal itself: the key is what identifies
        // the device, so the lookup deliberately spans every ISP.
        $router = Tenancy::withoutScope(
            fn (): ?Router => Router::query()->withoutGlobalScopes()->where('portal_key', $portalKey)->first(),
        );

        abort_if($router === null, 404);

        $view = match ($page) {
            'login.html' => 'hotspot.login',
            'alogin.html' => 'hotspot.alogin',
            default => abort(404),
        };

        return response()
            ->view($view, ['portalUrl' => $router->portalUrl(), 'router' => $router])
            ->header('Content-Type', 'text/html; charset=utf-8');
    }
}
