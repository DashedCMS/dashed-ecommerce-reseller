<?php

namespace Dashed\DashedEcommerceReseller\Models;

use Illuminate\Database\Eloquent\Model;

class ApiLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'dashed__reseller_api_logs';

    protected $fillable = ['token_id', 'user_id', 'method', 'path', 'status', 'duration_ms', 'ip'];

    protected $casts = [
        'status' => 'integer',
        'duration_ms' => 'integer',
    ];
}
