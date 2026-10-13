<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visit extends Model
{
    // Only created_at exists (set by the DB default), so Eloquent must not touch updated_at.
    public $timestamps = false;

    protected $fillable = [
        'url',
        'referrer',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'device_type',
        'screen_width',
        'language',
        'country',
        'duration',
        'scroll_depth',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
