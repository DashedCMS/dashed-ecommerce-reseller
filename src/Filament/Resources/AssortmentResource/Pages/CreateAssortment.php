<?php

namespace Dashed\DashedEcommerceReseller\Filament\Resources\AssortmentResource\Pages;

use Illuminate\Support\Arr;
use Dashed\DashedCore\Classes\Sites;
use Filament\Resources\Pages\CreateRecord;
use Dashed\DashedEcommerceReseller\Models\Assortment;
use Dashed\DashedEcommerceReseller\Filament\Resources\AssortmentResource;

class CreateAssortment extends CreateRecord
{
    protected static string $resource = AssortmentResource::class;

    protected array $pendingRules = [];

    /**
     * Modellen zijn in het CMS globaal unguarded, dus alles wat geen kolom
     * is moet hier weg voordat Eloquent het probeert op te slaan.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingRules = Assortment::rulesFromForm($data);
        $data['site_id'] = ($data['site_id'] ?? null) ?: Sites::getFirstSite()['id'];

        return Arr::except($data, Assortment::FORM_FIELDS);
    }

    protected function afterCreate(): void
    {
        $this->record->syncRules($this->pendingRules);
    }

    protected function getRedirectUrl(): string
    {
        return AssortmentResource::getUrl('preview', ['record' => $this->record]);
    }
}
