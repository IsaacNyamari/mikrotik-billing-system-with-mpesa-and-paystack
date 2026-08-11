<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\Provisioning\RouterSetupScript;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hands a router its own configuration file.
 *
 * Necessarily public: the router fetches this *before* the tunnel exists, and before it has
 * any credentials — that is the whole point of it. The token in the path is therefore the only
 * thing standing between this and anyone on the internet, so it is treated the way the
 * specification requires of a provisioning token: unguessable, short-lived, and single-use.
 *
 * What it returns is the same script the operator sees on screen, so there is one description
 * of a ready router rather than two that drift apart.
 */
class RouterProvisionController extends Controller
{
    public function __invoke(Request $request, string $token, RouterSetupScript $scripts): Response
    {
        // Unauthenticated, so it deliberately spans every ISP and then binds the one this
        // router belongs to.
        $router = Tenancy::withoutScope(
            fn (): ?Router => Router::query()->withoutGlobalScopes()->where('provision_token', $token)->first(),
        );

        // Nothing here distinguishes an unknown token from an expired or spent one. Telling
        // the difference would let someone learn which tokens once existed.
        if ($router === null || ! $router->provisionTokenIsUsable()) {
            Log::warning('Provisioning script requested with an unusable token', [
                'ip' => $request->ip(),
            ]);

            abort(404);
        }

        return Tenancy::forTenant($router->tenant, function () use ($router, $scripts, $request): Response {
            // Spent on the way out, not on the way in: a router that failed to fetch should be
            // able to try again, but one that succeeded must not hand the same file to anyone
            // else. The window is the download itself.
            $router->provision_fetched_at = now();
            $router->save();

            Log::info('Router fetched its provisioning script', [
                'router' => $router->id,
                'ip' => $request->ip(),
            ]);

            $body = $scripts->configurationFile($router);

            // text/plain rather than a download: RouterOS /tool/fetch writes whatever it gets
            // to the filename it was given, and a browser opening the link should show it
            // rather than silently save it.
            return response($body, 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        });
    }
}
