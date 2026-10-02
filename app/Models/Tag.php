<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'color'];

    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class);
    }
}
