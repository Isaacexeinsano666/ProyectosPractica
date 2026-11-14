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
            'can_draw' => 'boolean',
            'can_moderate' => 'boolean',
            'hand_raised' => 'boolean',
        ];
    }

    /** Nombre a mostrar; los alumnos que entraron antes de pedir nombre reciben uno genérico. */
    public function displayName(): string
    {
        return $this->name ?: 'Alumno '.$this->id;
    }
}
