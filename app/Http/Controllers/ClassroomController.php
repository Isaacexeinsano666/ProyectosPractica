<?php

namespace App\Http\Controllers;

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
    public function home(Request $request): View
    {
        $owned = array_keys($request->session()->get('classroom_owners', []));
        $rooms = ClassroomSession::whereIn('id', $owned)
            ->whereNull('ended_at')->where('expires_at', '>', now())->latest()->get();

        return view('home', ['rooms' => $rooms]);
    }

    public function create(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:100']], [
            'title.required' => 'Escribe un nombre para tu clase.',
            'title.max' => 'El nombre puede tener hasta 100 caracteres.',
        ]);
        $secret = Str::random(64);
        $room = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $room = ClassroomSession::create([
                    'code' => strtoupper(Str::random(8)),
                    'title' => $data['title'],
                    'owner_hash' => hash('sha256', $secret),
                    'state' => config('physics.initial_state'),
                    'expires_at' => now()->addHours(8),
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
        $request->validate(['code' => ['required', 'string', 'max:30']], [
            'code.required' => 'Ingresa el código que te compartió tu profesor.',
            'code.max' => 'Revisa el código de la sesión.',
        ]);
        $code = strtoupper(preg_replace('/[\s-]+/', '', $request->input('code')));
        $room = preg_match('/^[A-Z0-9]{8}$/', $code)
            ? ClassroomSession::where('code', $code)->first() : null;
        if (! $room || ! $room->isActive()) {
            throw ValidationException::withMessages([
                'code' => 'No encontramos una sesión activa con ese código. Revísalo con tu profesor.',
            ]);
        }
        $request->session()->put("classroom_members.{$room->id}", true);

        return redirect()->route('classroom.show', $room->code);
    }

    public function show(Request $request, ClassroomSession $room): View|RedirectResponse
    {
        if (! $this->isMember($request, $room)) {
            return redirect()->route('home')->with('notice', 'Ingresa el código de tu clase para unirte.');
        }

        return view('comparador', [
            'room' => $room,
            'isOwner' => $this->isOwner($request, $room),
            'active' => $room->isActive(),
            'physics' => config('physics'),
        ]);
    }

    public function state(Request $request, ClassroomSession $room): JsonResponse
    {
        abort_unless($this->isMember($request, $room), 403);

        return $this->snapshot($room);
    }

    public function update(Request $request, ClassroomSession $room): JsonResponse
    {
        abort_unless($this->isOwner($request, $room), 403);
        abort_unless($room->isActive(), 410, 'La sesión ha finalizado.');
        $fluids = array_keys(config('physics.fluids'));
        $state = $request->validate([
            'fluido1' => ['required', Rule::in($fluids)],
            'fluido2' => ['required', Rule::in($fluids)],
            'altura1' => ['required', 'numeric', 'between:0.1,2'],
            'altura2' => ['required', 'numeric', 'between:0.1,2'],
        ]);
        $state['altura1'] = round((float) $state['altura1'], 2);
        $state['altura2'] = round((float) $state['altura2'], 2);
        // The conditional write also protects against an end request racing this update.
        $written = ClassroomSession::whereKey($room->id)->whereNull('ended_at')
            ->where('expires_at', '>', now())->update([
                'state' => json_encode($state, JSON_THROW_ON_ERROR),
                'revision' => DB::raw('revision + 1'),
                'updated_at' => now(),
            ]);
        abort_unless($written, 410, 'La sesión ha finalizado.');

        return $this->snapshot($room->refresh());
    }

    public function end(Request $request, ClassroomSession $room): RedirectResponse
    {
        abort_unless($this->isOwner($request, $room), 403);
        ClassroomSession::whereKey($room->id)->whereNull('ended_at')->update(['ended_at' => now()]);

        return redirect()->route('home')->with('notice', 'La sesión finalizó. Los alumnos ya no recibirán cambios.');
    }

    private function isOwner(Request $request, ClassroomSession $room): bool
    {
        $secret = $request->session()->get("classroom_owners.{$room->id}");

        return is_string($secret) && hash_equals($room->owner_hash, hash('sha256', $secret));
    }

    private function isMember(Request $request, ClassroomSession $room): bool
    {
        return $this->isOwner($request, $room)
            || $request->session()->get("classroom_members.{$room->id}") === true;
    }

    private function snapshot(ClassroomSession $room): JsonResponse
    {
        return response()->json([
            'state' => $room->state,
            'revision' => $room->revision,
            'active' => $room->isActive(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
