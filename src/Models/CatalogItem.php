<?php

namespace Dashed\DashedEcommerceReseller\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class CatalogItem extends Model
{
    protected $table = 'dashed__reseller_catalog_items';

    protected $fillable = ['user_id', 'product_id', 'fingerprint', 'changed_at', 'removed_at'];

    protected $casts = [
        'user_id' => 'integer',
        'product_id' => 'integer',
        'changed_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }
}
