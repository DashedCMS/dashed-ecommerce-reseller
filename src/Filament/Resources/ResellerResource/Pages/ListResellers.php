<?php

namespace Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource;

class ListResellers extends ListRecords
{
    protected static string $resource = ResellerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
