<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\HasProfilePhoto;

class Family extends Model
{
    use HasProfilePhoto;

    protected $appends = ['profile_photo_url'];


    public function chores()
    {
        return $this->hasMany(Chore::class)->orderBy('created_at', 'desc');
    }
}
