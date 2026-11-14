<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ClassroomMember;
use App\Models\ClassroomSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Quién es quién en una clase: el profesor se reconoce por el secreto guardado en su
 * sesión, y cada navegador de alumno por una clave aleatoria de presencia.
 */
trait ClassroomAccess
{
    /** Cada alumno renueva su "visto por última vez" como mucho una vez cada estos segundos. */
    protected int $presenceWriteSeconds = 15;

    protected function isOwner(Request $request, ClassroomSession $room): bool
    {
        $secret = $request->session()->get("classroom_owners.{$room->id}");

        return is_string($secret) && hash_equals($room->owner_hash, hash('sha256', $secret));
    }

    protected function isMember(Request $request, ClassroomSession $room): bool
    {
        return $this->isOwner($request, $room)
            || $request->session()->get("classroom_members.{$room->id}") === true;
    }

    /**
     * Marca al alumno como conectado. La base de datos solo se escribe cada
     * $presenceWriteSeconds, salvo con $force (para asegurar que el registro exista).
     */
    protected function trackPresence(Request $request, ClassroomSession $room, bool $force = false): void
    {
        if ($this->isOwner($request, $room) || ! $room->isActive()) {
            return;
        }
        $sessionKey = "classroom_presence.{$room->id}";
        $presence = $request->session()->get($sessionKey);
        if (! is_array($presence) || ! is_string($presence['key'] ?? null)) {
            $presence = ['key' => Str::random(40), 'at' => 0];
        }
        if (! $force && now()->timestamp - (int) $presence['at'] < $this->presenceWriteSeconds) {
            return;
        }
        ClassroomMember::upsert(
            [['classroom_session_id' => $room->id, 'member_key' => $presence['key'], 'last_seen_at' => now()]],
            ['classroom_session_id', 'member_key'],
            ['last_seen_at'],
        );
        $presence['at'] = now()->timestamp;
        $request->session()->put($sessionKey, $presence);
    }

    /** El registro del alumno que hace la petición (null para el profesor). */
    protected function currentMember(Request $request, ClassroomSession $room): ?ClassroomMember
    {
        if ($this->isOwner($request, $room)) {
            return null;
        }
        $key = $request->session()->get("classroom_presence.{$room->id}.key");
        $member = is_string($key)
            ? $room->members()->where('member_key', $key)->first()
            : null;
        if (! $member && $room->isActive()) {
            $this->trackPresence($request, $room, true);
            $member = $room->members()
                ->where('member_key', $request->session()->get("classroom_presence.{$room->id}.key"))->first();
        }

        return $member;
    }
}
