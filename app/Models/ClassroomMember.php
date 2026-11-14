<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassroomMember extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }
}
