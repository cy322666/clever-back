<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stage extends Model
{
    protected $guarded = [];
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }
}
