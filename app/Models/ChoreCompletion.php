<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChoreCompletion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'family_id',
        'chore_id',
        'period_key',
        'completed_by_user_id',
        'completed_at',
    ];
}
