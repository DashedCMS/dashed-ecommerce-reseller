<?php

namespace Dashed\DashedEcommerceReseller\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssortmentRule extends Model
{
    public const MODE_INCLUDE = 'include';
    public const MODE_EXCLUDE = 'exclude';

    protected $table = 'dashed__reseller_assortment_rules';

    protected $fillable = ['assortment_id', 'type', 'target_id', 'mode'];

    protected $casts = ['target_id' => 'integer'];

    public function assortment(): BelongsTo
    {
        return $this->belongsTo(Assortment::class);
    }
}
