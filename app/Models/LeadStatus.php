<?php

namespace App\Models;

use App\Enums\LeadMilestone;
use App\Enums\LeadStatusType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadStatus extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'color', 'sort_order', 'is_active', 'type', 'description', 'milestone'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'type' => LeadStatusType::class,
            'milestone' => LeadMilestone::class,
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
