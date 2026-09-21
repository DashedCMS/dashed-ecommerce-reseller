<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-3">
        <label for="reseller-preview" class="text-sm font-medium text-gray-950 dark:text-white">
            {{ $this->labelPricesFor() }}
        </label>
        <select
            id="reseller-preview"
            wire:model.live="resellerId"
            class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
        >
            <option value="">{{ $this->labelNoReseller() }}</option>
            @foreach ($this->resellerOptions() as $id => $label)
                <option value="{{ $id }}">{{ $label }}</option>
            @endforeach
        </select>
        <span class="text-sm text-gray-500 dark:text-gray-400">
            {{ $this->productCountLabel() }}
        </span>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
