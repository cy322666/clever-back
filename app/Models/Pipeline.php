<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends Model
{
    protected $guarded = [];
    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('order_index');
    }
}
