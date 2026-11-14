<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ClassroomAccess;
use App\Models\ClassroomMember;
use App\Models\ClassroomSession;
use App\Models\ClassroomStroke;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pizarra compartida de la clase. El profesor siempre puede dibujar y borrar; cada
 * alumno solo dibuja si el profesor se lo permite (o la pizarra está libre), y solo
 * borra trazos ajenos si tiene el permiso "borrar todo".
 */
class BoardController extends Controller
{
    use ClassroomAccess;

    /** Límite de trazos por pizarra, para que una clase no crezca sin control. */
    public const MAX_STROKES = 2000;

    public const COLORS = ['#1f2a2e', '#2563c9', '#d93025', '#1a8a5a', '#c98a00'];

    public function show(Request $request, ClassroomSession $room): JsonResponse
    {
        abort_unless($this->isMember($request, $room), 403);

        return $this->snapshot($request, $room);
    }

    public function storeStroke(Request $request, ClassroomSession $room): JsonResponse
    {
        [$isOwner, $member] = $this->actor($request, $room);
        abort_unless($this->canDraw($room, $isOwner, $member), 403, 'No tienes permiso para dibujar.');
        $data = $request->validate([
            'type' => ['required', Rule::in(['pen', 'arrow'])],
            'color' => ['required', Rule::in(self::COLORS)],
            'points' => ['required', 'array', 'min:1', $request->input('type') === 'arrow' ? 'size:2' : 'max:1500'],
            'points.*' => ['required', 'array', 'size:2'],
            'points.*.*' => ['required', 'numeric', 'between:0,1'],
        ]);
        abort_if($room->strokes()->count() >= self::MAX_STROKES, 422, 'La pizarra está llena. Borra algunos trazos para seguir.');

        $room->strokes()->create([
            'classroom_member_id' => $member?->id,
            'author_name' => $isOwner ? 'Profesor' : $member->displayName(),
            'type' => $data['type'],
            'color' => $data['color'],
            'points' => array_map(fn ($p) => [round((float) $p[0], 4), round((float) $p[1], 4)], $data['points']),
        ]);
        $this->changed($room, $isOwner);

        return $this->snapshot($request, $room);
    }

    public function destroyStroke(Request $request, ClassroomSession $room, ClassroomStroke $stroke): JsonResponse
    {
        [$isOwner, $member] = $this->actor($request, $room);
        $own = ! $isOwner && $member && $stroke->classroom_member_id === $member->id;
        abort_unless($own || $this->canModerate($isOwner, $member), 403, 'Solo puedes borrar tus propios trazos.');
        $stroke->delete();
        $this->changed($room, $isOwner);

        return $this->snapshot($request, $room);
    }

    public function clear(Request $request, ClassroomSession $room): JsonResponse
    {
        [$isOwner, $member] = $this->actor($request, $room);
        abort_unless($this->canModerate($isOwner, $member), 403, 'No tienes permiso para borrar toda la pizarra.');
        $room->strokes()->delete();
        $this->changed($room, $isOwner);

        return $this->snapshot($request, $room);
    }

    public function raiseHand(Request $request, ClassroomSession $room): JsonResponse
    {
        [$isOwner, $member] = $this->actor($request, $room);
        abort_if($isOwner || ! $member, 403);
        $raised = $request->validate(['raised' => ['required', 'boolean']])['raised'];
        $member->update(['hand_raised' => (bool) $raised]);
        $this->changed($room, false);

        return $this->snapshot($request, $room);
    }

    public function permissions(Request $request, ClassroomSession $room, ClassroomMember $member): JsonResponse
    {
        [$isOwner] = $this->actor($request, $room);
        abort_unless($isOwner, 403);
        $data = $request->validate([
            'can_draw' => ['required', 'boolean'],
            'can_moderate' => ['required', 'boolean'],
        ]);
        // "Borrar todo" incluye dibujar; dar permiso de dibujo baja la mano.
        $canModerate = (bool) $data['can_moderate'];
        $canDraw = (bool) $data['can_draw'] || $canModerate;
        $member->update([
            'can_draw' => $canDraw,
            'can_moderate' => $canModerate,
            'hand_raised' => $canDraw ? false : $member->hand_raised,
        ]);
        $this->changed($room, true);

        return $this->snapshot($request, $room);
    }

    public function setOpen(Request $request, ClassroomSession $room): JsonResponse
    {
        [$isOwner] = $this->actor($request, $room);
        abort_unless($isOwner, 403);
        $room->update(['board_open' => (bool) $request->validate(['open' => ['required', 'boolean']])['open']]);
        $this->changed($room, true);

        return $this->snapshot($request, $room);
    }

    /**
     * Quién hace la petición. Toda escritura exige ser parte de la clase y que siga activa.
     *
     * @return array{0: bool, 1: ?ClassroomMember}
     */
    private function actor(Request $request, ClassroomSession $room): array
    {
        abort_unless($this->isMember($request, $room), 403);
        abort_unless($room->isActive(), 410, 'La sesión ha finalizado.');
        $isOwner = $this->isOwner($request, $room);

        return [$isOwner, $isOwner ? null : $this->currentMember($request, $room)];
    }

    private function canDraw(ClassroomSession $room, bool $isOwner, ?ClassroomMember $member): bool
    {
        return $isOwner || ($member && ($room->board_open || $member->can_draw || $member->can_moderate));
    }

    private function canModerate(bool $isOwner, ?ClassroomMember $member): bool
    {
        return $isOwner || ($member && $member->can_moderate);
    }

    /** Avisa a todos los navegadores que la pizarra cambió; si fue el profesor, reinicia el cierre por inactividad. */
    private function changed(ClassroomSession $room, bool $byOwner): void
    {
        $room->increment('board_revision', 1, $byOwner
            ? ['expires_at' => now()->addMinutes(ClassroomSession::IDLE_MINUTES)]
            : []);
    }

    private function snapshot(Request $request, ClassroomSession $room): JsonResponse
    {
        $room->refresh();
        $isOwner = $this->isOwner($request, $room);
        $member = $isOwner ? null : $this->currentMember($request, $room);

        $data = [
            'revision' => $room->board_revision,
            'active' => $room->isActive(),
            'open' => $room->board_open,
            'me' => [
                'is_owner' => $isOwner,
                'name' => $isOwner ? 'Profesor' : $member?->displayName(),
                'can_draw' => $this->canDraw($room, $isOwner, $member),
                'can_moderate' => $this->canModerate($isOwner, $member),
                'hand_raised' => (bool) $member?->hand_raised,
            ],
            'strokes' => $room->strokes()->orderBy('id')->get()->map(fn (ClassroomStroke $s) => [
                'id' => $s->id,
                'author' => $s->author_name,
                'mine' => $isOwner ? $s->classroom_member_id === null : ($member && $s->classroom_member_id === $member->id),
                'type' => $s->type,
                'color' => $s->color,
                'points' => $s->points,
            ]),
        ];
        // Solo el profesor recibe la lista de alumnos con sus permisos.
        if ($isOwner) {
            $online = now()->subSeconds(ClassroomSession::ONLINE_SECONDS);
            $data['students'] = $room->members()->orderBy('id')->get()->map(fn (ClassroomMember $m) => [
                'id' => $m->id,
                'name' => $m->displayName(),
                'online' => $m->last_seen_at >= $online,
                'can_draw' => $m->can_draw || $m->can_moderate,
                'can_moderate' => $m->can_moderate,
                'hand_raised' => $m->hand_raised,
            ]);
        }

        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}
