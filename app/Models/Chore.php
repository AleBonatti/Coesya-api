<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chore extends Model
{
    protected $fillable = [
        'family_id',
        'title',
        'frequency',
        'category',
        'weight',
        'priority',
        'is_active',
    ];
}
