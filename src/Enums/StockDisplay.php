<?php

namespace Dashed\DashedEcommerceReseller\Enums;

enum StockDisplay: string
{
    case Exact = 'exact';
    case Capped = 'capped';
    case Status = 'status';

    public function label(): string
    {
        return match ($this) {
            self::Exact => __('Exact aantal'),
            self::Capped => __('Aantal met plafond'),
            self::Status => __('Alleen op voorraad of niet'),
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
