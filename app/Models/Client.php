<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $guarded = [];

    protected $fillable = [
        'external_id',
        'source_type',
        'name',
        'legal_name',
        'inn',
        'kpp',
        'category',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'annual_revenue_estimate' => 'decimal:2',
            'margin_target' => 'decimal:4',
        ];
    }
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(SalesOpportunity::class);
    }

    public function salesLeads(): HasMany
    {
        return $this->hasMany(SalesLead::class);
    }

    public function supportContracts(): HasMany
    {
        return $this->hasMany(SupportContract::class);
    }

    public function buyers(): HasMany
    {
        return $this->hasMany(Buyer::class, 'client_id');
    }

    public function revenueTransactions(): HasMany
    {
        return $this->hasMany(RevenueTransaction::class);
    }

    public function expenseTransactions(): HasMany
    {
        return $this->hasMany(ExpenseTransaction::class);
    }
}
