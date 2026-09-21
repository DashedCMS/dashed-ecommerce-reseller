<?php

namespace Dashed\DashedEcommerceReseller\Filament\Resources\AssortmentResource\Pages;

use Illuminate\Support\Arr;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Dashed\DashedEcommerceReseller\Models\Assortment;
use Dashed\DashedEcommerceReseller\Filament\Resources\AssortmentResource;

class EditAssortment extends EditRecord
{
    protected static string $resource = AssortmentResource::class;

    protected array $pendingRules = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label(__('Voorbeeld'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->url(fn (): string => AssortmentResource::getUrl('preview', ['record' => $this->record])),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return array_merge($data, $this->record->rulesForForm());
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingRules = Assortment::rulesFromForm($data);

        return Arr::except($data, Assortment::FORM_FIELDS);
    }

    protected function afterSave(): void
    {
        $this->record->syncRules($this->pendingRules);
    }
}
