<?php

namespace Dashed\DashedEcommerceReseller\Http;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;

final class LocaleParam
{
    /**
     * @return array{locales: list<string>, single: ?string}
     */
    public static function resolve(Request $request, ResellerProfile $profile): array
    {
        $available = $profile->locales();
        $asked = (string) $request->query('locale', '');

        if ($asked === '') {
            return ['locales' => [$available[0]], 'single' => $available[0]];
        }

        if ($asked === 'all') {
            return ['locales' => $available, 'single' => null];
        }

        if (! in_array($asked, $available, true)) {
            throw ValidationException::withMessages([
                'locale' => 'Onbekende taal. Kies uit ' . implode(', ', $available) . ' of all.',
            ]);
        }

        return ['locales' => [$asked], 'single' => $asked];
    }
}
