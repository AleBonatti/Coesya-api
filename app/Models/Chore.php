<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chore extends Model
{
    protected $fillable = [
        'family_id',
        'category_id',
        'assigned_to_user_id',
        'title',
        'frequency',
        'category',
        'weight',
        'priority',
        'is_active',
    ];


    public function category()
    {
        return $this->hasOne(Category::class, 'id', 'category_id')->orderBy('created_at', 'desc');
    }
}
