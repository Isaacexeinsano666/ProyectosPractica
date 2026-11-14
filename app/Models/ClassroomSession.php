<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassroomSession extends Model
{
    use Prunable;

    /**
     * Minutos sin cambios del profesor tras los que la clase se cierra sola.
     * Cada cambio publicado vuelve a dar este margen.
     */
    public const IDLE_MINUTES = 60;

    /** Un alumno cuenta como conectado si su página consultó la clase en estos segundos. */
    public const ONLINE_SECONDS = 40;

    public function members(): HasMany
    {
        return $this->hasMany(ClassroomMember::class);
    }

    public function strokes(): HasMany
    {
        return $this->hasMany(ClassroomStroke::class);
    }

    /** @return array{online: int, joined: int} */
    public function studentCounts(): array
    {
        return [
            'online' => $this->members()->where('last_seen_at', '>=', now()->subSeconds(self::ONLINE_SECONDS))->count(),
            'joined' => $this->members()->count(),
        ];
    }

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
            'board_open' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    /** Segundos que quedan antes del cierre automático (0 si ya está cerrada). */
    public function secondsUntilExpiry(): int
    {
        return $this->isActive() ? (int) max(0, now()->diffInSeconds($this->expires_at)) : 0;
    }

    public function displayCode(): string
    {
        return substr($this->code, 0, 4).'-'.substr($this->code, 4);
    }
}
