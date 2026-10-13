<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    protected $fillable = [
        'name',
        'email',
        'message',
        'read',
    ];

    protected $casts = [
        'read' => 'boolean',
    ];
}
