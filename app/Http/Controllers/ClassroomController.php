<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ClassroomAccess;
use App\Models\ClassroomSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ClassroomController extends Controller
{
    use ClassroomAccess;

    /** Lo que el profesor puede mostrar a la clase: los dos experimentos o la pizarra. */
    public const ACTIVITIES = ['arquimedes', 'hidrostatica', 'pascal', 'pizarra'];

    public function home(Request $request): View
    {
        $owned = array_keys($request->session()->get('classroom_owners', []));
        $rooms = ClassroomSession::whereIn('id', $owned)
            ->whereNull('ended_at')->where('expires_at', '>', now())->latest()->get();

        return view('home', ['rooms' => $rooms]);
    }

    public function create(Request $request): RedirectResponse
    {
        $secret = Str::random(64);
        $room = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $room = ClassroomSession::create([
                    'code' => strtoupper(Str::random(8)),
                    'title' => 'Laboratorio de fluidos',
                    'owner_hash' => hash('sha256', $secret),
                    'state' => config('physics.initial_state'),
                    'expires_at' => now()->addMinutes(ClassroomSession::IDLE_MINUTES),
                ]);
                break;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 4) {
                    throw $exception;
                }
            }
        }

        $request->session()->put("classroom_owners.{$room->id}", $secret);
        $request->session()->put("classroom_members.{$room->id}", true);

        return redirect()->route('classroom.show', $room->code);
    }

    public function join(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:40'],
        ], [
            'code.required' => 'Ingresa el código que te compartió tu profesor.',
            'code.max' => 'Revisa el código de la sesión.',
            'name.required' => 'Escribe tu nombre para que el profesor sepa quién eres.',
            'name.max' => 'Usa un nombre de hasta 40 caracteres.',
        ]);
        $name = trim(preg_replace('/\s+/u', ' ', $request->input('name')));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Escribe tu nombre para que el profesor sepa quién eres.']);
        }
        $code = strtoupper(preg_replace('/[\s-]+/', '', $request->input('code')));
        $room = preg_match('/^[A-Z0-9]{8}$/', $code)
            ? ClassroomSession::where('code', $code)->first() : null;
        if (! $room || ! $room->isActive()) {
            throw ValidationException::withMessages([
                'code' => 'No encontramos una sesión activa con ese código. Revísalo con tu profesor.',
            ]);
        }
        $request->session()->put("classroom_members.{$room->id}", true);
        $member = $this->currentMember($request, $room);
        if ($member && $member->name !== $name) {
            $member->update(['name' => $name]);
            // El nombre aparece en el panel de permisos de la pizarra.
            $room->increment('board_revision');
        }

        return redirect()->route('classroom.show', $room->code);
    }

    public function show(Request $request, ClassroomSession $room): View|RedirectResponse
    {
        if (! $this->isMember($request, $room)) {
            return redirect()->route('home')->with('notice', 'Ingresa el código de tu clase para unirte.');
        }

        $this->trackPresence($request, $room);
        $room->state = $this->normalizedState($room->state);
        $isOwner = $this->isOwner($request, $room);

        return view('comparador', [
            'room' => $room,
            'isOwner' => $isOwner,
            'active' => $room->isActive(),
            'physics' => config('physics'),
            'students' => $isOwner ? $room->studentCounts() : null,
        ]);
    }

    public function state(Request $request, ClassroomSession $room): JsonResponse
    {
        abort_unless($this->isMember($request, $room), 403);
        $this->trackPresence($request, $room);

        return $this->snapshot($room, $this->isOwner($request, $room));
    }

    public function update(Request $request, ClassroomSession $room): JsonResponse
    {
        abort_unless($this->isOwner($request, $room), 403);
        abort_unless($room->isActive(), 410, 'La sesión ha finalizado.');
        $fluids = array_keys(config('physics.fluids'));
        $objects = array_keys(config('physics.objects'));
        $state = $request->validate([
            'activity' => ['required', Rule::in(self::ACTIVITIES)],
            'arquimedes_fluido1' => ['required', Rule::in($fluids)],
            'arquimedes_fluido2' => ['required', Rule::in($fluids)],
            'objeto1' => ['required', Rule::in($objects)],
            'objeto2' => ['required', Rule::in($objects)],
            'masa1' => ['required', 'numeric', 'between:0.01,100'],
            'masa2' => ['required', 'numeric', 'between:0.01,100'],
            'hidrostatica_fluido1' => ['required', Rule::in($fluids)],
            'hidrostatica_fluido2' => ['required', Rule::in($fluids)],
            'altura1' => ['required', 'numeric', 'between:0.1,2'],
            'altura2' => ['required', 'numeric', 'between:0.1,2'],
            'pascal_area1' => ['present', 'nullable', 'numeric', 'between:0.1,1000'],
            'pascal_area2' => ['present', 'nullable', 'numeric', 'between:0.1,1000'],
            'pascal_force1' => ['present', 'nullable', 'numeric', 'between:0.1,1000'],
            'pascal_distance1' => ['present', 'nullable', 'numeric', 'between:0.1,1000'],
        ]);
        foreach (['altura1', 'altura2', 'masa1', 'masa2', 'pascal_area1', 'pascal_area2', 'pascal_force1', 'pascal_distance1'] as $field) {
            if ($state[$field] !== null) {
                $state[$field] = round((float) $state[$field], 2);
            }
        }
        // En una prensa hidráulica el pistón de salida (A₂) es el grande.
        if ($state['pascal_area1'] !== null && $state['pascal_area2'] !== null
            && $state['pascal_area2'] <= $state['pascal_area1']) {
            throw ValidationException::withMessages([
                'pascal_area2' => 'El área del pistón grande debe ser mayor que la del pistón pequeño.',
            ]);
        }
        $state['pascal_configured'] = count(array_filter([
            $state['pascal_area1'],
            $state['pascal_area2'],
            $state['pascal_force1'],
            $state['pascal_distance1'],
        ], fn ($value) => $value !== null)) > 0;
        // The conditional write also protects against an end request racing this update.
        // Each change by the teacher resets the inactivity timer.
        $written = ClassroomSession::whereKey($room->id)->whereNull('ended_at')
            ->where('expires_at', '>', now())->update([
                'state' => json_encode($state, JSON_THROW_ON_ERROR),
                'revision' => DB::raw('revision + 1'),
                'expires_at' => now()->addMinutes(ClassroomSession::IDLE_MINUTES),
                'updated_at' => now(),
            ]);
        abort_unless($written, 410, 'La sesión ha finalizado.');

        return $this->snapshot($room->refresh(), true);
    }

    public function end(Request $request, ClassroomSession $room): RedirectResponse
    {
        abort_unless($this->isOwner($request, $room), 403);
        ClassroomSession::whereKey($room->id)->whereNull('ended_at')->update(['ended_at' => now()]);

        return redirect()->route('home')->with('notice', 'La sesión finalizó.');
    }

    private function snapshot(ClassroomSession $room, bool $forOwner = false): JsonResponse
    {
        $data = [
            'state' => $this->normalizedState($room->state),
            'revision' => $room->revision,
            'board_revision' => $room->board_revision,
            'active' => $room->isActive(),
            'expires_in' => $room->secondsUntilExpiry(),
        ];
        // Solo el profesor recibe cuántos alumnos hay.
        if ($forOwner) {
            $data['students'] = $room->studentCounts();
        }

        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }

    private function normalizedState(mixed $state): array
    {
        $stored = is_array($state) ? $state : [];
        $normalized = array_merge(config('physics.initial_state'), $stored);
        if (! array_key_exists('pascal_configured', $stored)) {
            $normalized['pascal_area1'] = null;
            $normalized['pascal_area2'] = null;
            $normalized['pascal_force1'] = null;
            $normalized['pascal_distance1'] = null;
            $normalized['pascal_configured'] = false;
        }

        return $normalized;
    }
}
