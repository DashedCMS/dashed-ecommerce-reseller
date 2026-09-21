<?php

namespace Dashed\DashedEcommerceReseller\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Dashed\DashedCore\Classes\ApiTokenAbilities;
use Dashed\DashedEcommerceReseller\Http\ApiError;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;

class EnsureResellerAccess
{
    public const PROFILE = 'reseller_profile';

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local') && ! $request->secure()) {
            return ApiError::response('https_required', 'De API is alleen via https bereikbaar.', 403);
        }

        $user = $request->user();

        if (! ApiTokenAbilities::isReseller($user?->currentAccessToken())) {
            return ApiError::response('forbidden', 'Deze sleutel is geen afnemerssleutel.', 403);
        }

        $profile = ResellerProfile::query()
            ->with(['user', 'assortment.rules', 'webhookSubscription'])
            ->where('user_id', $user->getKey())
            ->first();

        if ($profile === null) {
            return ApiError::response('access_disabled', 'Dit account heeft geen toegang tot de afnemers-API.', 403);
        }

        // Een API-sleutel slaat MFA over. Is het account intussen beheerder
        // geworden, dan horen zijn afnemerssleutels weg.
        if ($user->mustLoginViaPanel()) {
            $profile->revokeTokens();

            return ApiError::response('access_disabled', 'Dit account heeft geen toegang tot de afnemers-API.', 403);
        }

        if (! $profile->isActive()) {
            return ApiError::response('access_disabled', 'API-toegang staat uit voor dit account.', 403);
        }

        $request->attributes->set(self::PROFILE, $profile);
        config(['dashed-core.dashed_site_id' => $profile->siteId()]);

        return $next($request);
    }

    public static function profile(Request $request): ResellerProfile
    {
        return $request->attributes->get(self::PROFILE);
    }
}
