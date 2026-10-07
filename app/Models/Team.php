<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * For display and filtering only: teams never change who sees which leads.
 */
class Team extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('name');
    }
}
