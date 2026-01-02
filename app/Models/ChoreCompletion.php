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
        'completed_by',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime:Y-m-d'
    ];


    public function chore()
    {
        return $this->belongsTo(Chore::class, 'chore_id', 'id')->orderBy('created_at', 'desc');
    }
}
