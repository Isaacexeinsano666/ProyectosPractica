<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassroomStroke extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'points' => 'array',
        ];
    }
}
