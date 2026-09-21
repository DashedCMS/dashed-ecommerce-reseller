<?php

namespace Dashed\DashedEcommerceReseller\Filament\Resources\AssortmentResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Dashed\DashedEcommerceReseller\Filament\Resources\AssortmentResource;

class ListAssortments extends ListRecords
{
    protected static string $resource = AssortmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
