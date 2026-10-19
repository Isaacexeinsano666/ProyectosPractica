<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassroomSession extends Model
{
    protected $guarded = [];

    protected $hidden = ['owner_hash'];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    public function displayCode(): string
    {
        return substr($this->code, 0, 4).'-'.substr($this->code, 4);
    }
}
