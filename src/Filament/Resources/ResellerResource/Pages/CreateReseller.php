<?php

namespace Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Dashed\DashedEcommerceReseller\Webhooks\ResellerWebhooks;
use Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource;

class CreateReseller extends CreateRecord
{
    protected static string $resource = ResellerResource::class;

    protected function afterCreate(): void
    {
        ResellerWebhooks::configure($this->record, $this->data['webhook_url'] ?? null);
    }

    protected function getRedirectUrl(): string
    {
        return ResellerResource::getUrl('edit', ['record' => $this->record]);
    }
}
