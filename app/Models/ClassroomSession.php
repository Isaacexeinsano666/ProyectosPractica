<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class ClassroomSession extends Model
{
    use Prunable;

    protected $guarded = [];

    /**
     * Salas finalizadas o caducadas hace más de un día (se borran con `php artisan model:prune`).
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDay();

        return static::where('expires_at', '<', $cutoff)->orWhere('ended_at', '<', $cutoff);
    }

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
