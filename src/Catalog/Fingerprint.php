<?php

namespace Dashed\DashedEcommerceReseller\Catalog;

final class Fingerprint
{
    public static function of(array $payload): string
    {
        unset($payload['changed_at']);

        return hash('sha256', json_encode(
            self::normalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            return round($value, 4);
        }

        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::normalize(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
