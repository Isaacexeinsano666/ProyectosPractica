<?php

namespace Tests\Feature;

use App\Models\ClassroomSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassroomTest extends TestCase
{
    use RefreshDatabase;

    private function room(array $attributes = []): ClassroomSession
    {
        return ClassroomSession::create(array_merge([
            'code' => 'ABCD1234',
            'title' => 'Clase de fluidos',
            'owner_hash' => hash('sha256', 'teacher-secret'),
            'state' => config('physics.initial_state'),
            'expires_at' => now()->addMinutes(ClassroomSession::IDLE_MINUTES),
        ], $attributes));
    }

    private function teacher(ClassroomSession $room): array
    {
        return ['classroom_owners' => [$room->id => 'teacher-secret']];
    }

    private function student(ClassroomSession $room): array
    {
        return ['classroom_members' => [$room->id => true], 'classroom_owners' => []];
    }

    public function test_home_shows_the_join_menu_and_creation_form(): void
    {
        $this->get('/')->assertOk()->assertSee('Código de la sesión')->assertSee('Crear sesión')
            ->assertDontSee('id="selectLiquido1"', false);
    }

    public function test_creating_a_room_sets_owner_access_and_persists_initial_state(): void
    {
        $response = $this->post('/sesiones');
        $room = ClassroomSession::firstOrFail();
        $response->assertRedirect(route('classroom.show', $room->code))
            ->assertSessionHas("classroom_owners.{$room->id}")
            ->assertSessionHas("classroom_members.{$room->id}", true);
        $this->assertSame('Laboratorio de fluidos', $room->title);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $room->code);
        $this->assertSame(config('physics.initial_state'), $room->state);
        $this->get(route('classroom.show', $room->code))->assertOk()->assertSee('Profesor');
        $this->get('/')->assertSee('Continuar tu clase')->assertSee('Laboratorio de fluidos');
    }

    public function test_student_can_join_with_lowercase_hyphenated_code(): void
    {
        $room = $this->room();
        $this->post('/unirse', ['code' => ' abcd-1234 ', 'name' => 'Ana'])->assertRedirect(route('classroom.show', $room->code))
            ->assertSessionHas("classroom_members.{$room->id}", true);
        $this->get(route('classroom.show', $room->code))->assertOk()
            ->assertSee('Principio de Arquímedes')->assertSee('Presión hidrostática')->assertDontSee('Sí, finalizar clase');
    }

    public function test_invalid_closed_and_expired_codes_are_rejected(): void
    {
        $this->post('/unirse', ['code' => 'NOTACODE', 'name' => 'Ana'])->assertSessionHasErrors('code');
        $room = $this->room(['ended_at' => now()]);
        $this->post('/unirse', ['code' => $room->code, 'name' => 'Ana'])->assertSessionHasErrors('code');
        $room->update(['ended_at' => null, 'expires_at' => now()->subMinute()]);
        $this->post('/unirse', ['code' => $room->code, 'name' => 'Ana'])->assertSessionHasErrors('code');
    }

    public function test_room_and_state_require_membership(): void
    {
        $room = $this->room();
        $this->get(route('classroom.show', $room->code))->assertRedirect(route('home'));
        $this->getJson(route('classroom.state', $room->code))->assertForbidden();
    }

    public function test_student_cannot_publish_or_end_a_class_even_with_owner_code(): void
    {
        $room = $this->room();
        $this->withSession($this->student($room));
        $this->putJson(route('classroom.update', $room->code), config('physics.initial_state'))->assertForbidden();
        $this->post(route('classroom.end', $room->code))->assertForbidden();
        $this->assertNull($room->fresh()->ended_at);
    }

    public function test_forged_owner_secret_cannot_publish(): void
    {
        $room = $this->room();
        $this->withSession(['classroom_owners' => [$room->id => 'wrong-secret']])
            ->putJson(route('classroom.update', $room->code), config('physics.initial_state'))->assertForbidden();
    }

    public function test_teacher_changes_are_available_to_students_and_late_joiners(): void
    {
        $room = $this->room();
        $state = [
            'activity' => 'hidrostatica',
            'arquimedes_fluido1' => 'mercurio',
            'arquimedes_fluido2' => 'alcohol',
            'objeto1' => 'hielo',
            'objeto2' => 'aluminio',
            'masa1' => 2.5,
            'masa2' => 0.75,
            'hidrostatica_fluido1' => 'agua',
            'hidrostatica_fluido2' => 'aceite',
            'altura1' => 1.75,
            'altura2' => 0.5,
        ];
        $this->withSession($this->teacher($room))
            ->putJson(route('classroom.update', $room->code), $state)->assertOk()
            ->assertJsonPath('state.arquimedes_fluido1', 'mercurio')->assertJsonPath('revision', 1);
        $this->withSession($this->student($room))->getJson(route('classroom.state', $room->code))
            ->assertOk()->assertJsonPath('state.objeto1', 'hielo')->assertJsonPath('state.objeto2', 'aluminio')
            ->assertJsonPath('state.masa1', 2.5)->assertJsonPath('state.masa2', 0.75)
            ->assertJsonPath('active', true)->assertJsonMissingPath('owner_hash');
        $this->withSession(['classroom_owners' => [], 'classroom_members' => []]);
        $this->post('/unirse', ['code' => $room->code, 'name' => 'Ana'])->assertRedirect();
        $this->get(route('classroom.show', $room->code))->assertSee('Mercurio')->assertSee('Hielo');
    }

    public function test_invalid_fluid_and_object_never_change_state(): void
    {
        $room = $this->room();
        $this->withSession($this->teacher($room))->putJson(route('classroom.update', $room->code), [
            'activity' => 'otra',
            'arquimedes_fluido1' => '<script>',
            'arquimedes_fluido2' => 'agua',
            'objeto1' => 'piedra',
            'objeto2' => 'aluminio',
            'masa1' => 1,
            'masa2' => 1,
            'hidrostatica_fluido1' => 'agua',
            'hidrostatica_fluido2' => 'aceite',
            'altura1' => 1,
            'altura2' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['activity', 'arquimedes_fluido1', 'objeto1']);
        $this->assertSame(0, $room->fresh()->revision);
    }

    public function test_mass_must_be_between_limits(): void
    {
        $room = $this->room();
        $state = array_merge(config('physics.initial_state'), ['masa1' => 0, 'masa2' => 'abc']);
        $this->withSession($this->teacher($room))->putJson(route('classroom.update', $room->code), $state)
            ->assertUnprocessable()->assertJsonValidationErrors(['masa1', 'masa2']);
        $state = array_merge(config('physics.initial_state'), ['masa1' => 100.5]);
        $this->putJson(route('classroom.update', $room->code), $state)
            ->assertUnprocessable()->assertJsonValidationErrors(['masa1']);
        $this->assertSame(0, $room->fresh()->revision);
    }

    public function test_rooms_created_before_mass_existed_get_default_mass(): void
    {
        $oldState = config('physics.initial_state');
        unset($oldState['masa1'], $oldState['masa2']);
        $room = $this->room(['state' => $oldState]);
        $this->withSession($this->student($room))->getJson(route('classroom.state', $room->code))
            ->assertOk()->assertJsonPath('state.masa1', 1)->assertJsonPath('state.masa2', 1);
        $this->get(route('classroom.show', $room->code))->assertOk()
            ->assertSee('id="masa1"', false)->assertSee('Peso del sólido')->assertSee('Densidad del líquido')
            ->assertSee('Volumen del líquido desplazado o volumen del sólido sumergido');
    }

    public function test_ending_a_class_notifies_members_and_blocks_updates_and_new_joins(): void
    {
        $room = $this->room();
        $this->withSession($this->teacher($room))->post(route('classroom.end', $room->code))
            ->assertRedirect(route('home'));
        $this->putJson(route('classroom.update', $room->code), config('physics.initial_state'))->assertGone();
        $this->withSession($this->student($room))->getJson(route('classroom.state', $room->code))
            ->assertOk()->assertJsonPath('active', false);
        $this->post('/unirse', ['code' => $room->code, 'name' => 'Ana'])->assertSessionHasErrors('code');
    }

    public function test_old_ended_and_expired_rooms_are_pruned(): void
    {
        $live = $this->room();
        $justEnded = $this->room(['code' => 'ENDED001', 'ended_at' => now()]);
        $oldEnded = $this->room(['code' => 'ENDED002', 'ended_at' => now()->subDays(2)]);
        $oldExpired = $this->room(['code' => 'EXPIRED1', 'expires_at' => now()->subDays(2)]);
        $oldEnded->members()->create(['member_key' => 'alumno-viejo', 'last_seen_at' => now()->subDays(2)]);
        $live->members()->create(['member_key' => 'alumno-actual', 'last_seen_at' => now()]);

        $this->artisan('model:prune', ['--model' => [ClassroomSession::class]])->assertSuccessful();

        $this->assertDatabaseMissing('classroom_members', ['member_key' => 'alumno-viejo']);
        $this->assertDatabaseHas('classroom_members', ['member_key' => 'alumno-actual']);

        $this->assertModelExists($live);
        $this->assertModelExists($justEnded);
        $this->assertModelMissing($oldEnded);
        $this->assertModelMissing($oldExpired);
    }

    public function test_sessions_are_isolated(): void
    {
        $room = $this->room();
        $other = $this->room(['code' => 'OTHER123']);
        $this->withSession($this->teacher($room))->putJson(route('classroom.update', $other->code), config('physics.initial_state'))
            ->assertForbidden();
        $this->withSession($this->student($room))->getJson(route('classroom.state', $other->code))->assertForbidden();
    }

    public function test_new_room_closes_after_one_hour_without_changes(): void
    {
        $this->freezeSecond();
        $this->post('/sesiones');
        $room = ClassroomSession::firstOrFail();
        $this->getJson(route('classroom.state', $room->code))
            ->assertJsonPath('active', true)->assertJsonPath('expires_in', 3600);
        $this->travel(59)->minutes();
        $this->getJson(route('classroom.state', $room->code))->assertJsonPath('active', true);
        $this->travel(2)->minutes();
        $this->getJson(route('classroom.state', $room->code))
            ->assertJsonPath('active', false)->assertJsonPath('expires_in', 0);
        $this->putJson(route('classroom.update', $room->code), config('physics.initial_state'))->assertGone();
        $this->post('/unirse', ['code' => $room->code, 'name' => 'Ana'])->assertSessionHasErrors('code');
    }

    public function test_each_teacher_change_restarts_the_inactivity_timer(): void
    {
        $this->freezeSecond();
        $room = $this->room();
        $this->withSession($this->teacher($room));
        $this->travel(50)->minutes();
        $this->putJson(route('classroom.update', $room->code), config('physics.initial_state'))
            ->assertOk()->assertJsonPath('expires_in', 3600);
        $this->travel(50)->minutes();
        $this->getJson(route('classroom.state', $room->code))->assertJsonPath('active', true);
        $this->travel(11)->minutes();
        $this->getJson(route('classroom.state', $room->code))->assertJsonPath('active', false);
    }

    private function studentBrowser(ClassroomSession $room, string $key): array
    {
        return $this->student($room) + ['classroom_presence' => [$room->id => ['key' => $key, 'at' => 0]]];
    }

    public function test_teacher_sees_how_many_students_are_connected(): void
    {
        $room = $this->room();
        $this->withSession($this->teacher($room))->getJson(route('classroom.state', $room->code))
            ->assertJsonPath('students.online', 0)->assertJsonPath('students.joined', 0);

        foreach (['alumno-a', 'alumno-b', 'alumno-c'] as $key) {
            $this->withSession($this->studentBrowser($room, $key))
                ->getJson(route('classroom.state', $room->code))->assertOk()->assertJsonMissingPath('students');
        }
        // El mismo alumno consultando muchas veces sigue contando como uno.
        $this->withSession($this->studentBrowser($room, 'alumno-a'))->getJson(route('classroom.state', $room->code));

        $this->withSession($this->teacher($room))->getJson(route('classroom.state', $room->code))
            ->assertJsonPath('students.online', 3)->assertJsonPath('students.joined', 3);
        $this->get(route('classroom.show', $room->code))->assertSee('3 alumnos conectados');
    }

    public function test_students_who_stop_polling_are_no_longer_counted_as_connected(): void
    {
        $room = $this->room();
        $this->withSession($this->studentBrowser($room, 'alumno-a'))->getJson(route('classroom.state', $room->code));
        $this->withSession($this->studentBrowser($room, 'alumno-b'))->getJson(route('classroom.state', $room->code));
        $this->travel(ClassroomSession::ONLINE_SECONDS + 5)->seconds();
        // Solo "alumno-b" sigue con la página abierta.
        $this->withSession($this->studentBrowser($room, 'alumno-b'))->getJson(route('classroom.state', $room->code));

        $this->withSession($this->teacher($room))->getJson(route('classroom.state', $room->code))
            ->assertJsonPath('students.online', 1)->assertJsonPath('students.joined', 2);
    }

    public function test_joining_counts_the_student_and_teacher_is_not_counted(): void
    {
        $room = $this->room();
        $this->post('/unirse', ['code' => $room->code, 'name' => 'Ana'])->assertRedirect();
        $this->withSession($this->teacher($room))->getJson(route('classroom.state', $room->code))
            ->assertJsonPath('students.online', 1)->assertJsonPath('students.joined', 1);
        $this->get(route('classroom.show', $room->code))->assertSee('1 alumno conectado');
    }

    public function test_student_polling_does_not_keep_the_class_open(): void
    {
        $room = $this->room();
        $this->withSession($this->student($room));
        foreach ([20, 20, 20] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->getJson(route('classroom.state', $room->code))->assertOk();
        }
        $this->travel(1)->minutes();
        $this->getJson(route('classroom.state', $room->code))->assertJsonPath('active', false);
    }
}
