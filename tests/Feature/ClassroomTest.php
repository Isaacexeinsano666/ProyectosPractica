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
            'expires_at' => now()->addHours(8),
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
        $this->post('/unirse', ['code' => ' abcd-1234 '])->assertRedirect(route('classroom.show', $room->code))
            ->assertSessionHas("classroom_members.{$room->id}", true);
        $this->get(route('classroom.show', $room->code))->assertOk()
            ->assertSee('Principio de Arquímedes')->assertSee('Presión hidrostática')->assertDontSee('Sí, finalizar clase');
    }

    public function test_invalid_closed_and_expired_codes_are_rejected(): void
    {
        $this->post('/unirse', ['code' => 'NOTACODE'])->assertSessionHasErrors('code');
        $room = $this->room(['ended_at' => now()]);
        $this->post('/unirse', ['code' => $room->code])->assertSessionHasErrors('code');
        $room->update(['ended_at' => null, 'expires_at' => now()->subMinute()]);
        $this->post('/unirse', ['code' => $room->code])->assertSessionHasErrors('code');
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
        $this->post('/unirse', ['code' => $room->code])->assertRedirect();
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
        $this->post('/unirse', ['code' => $room->code])->assertSessionHasErrors('code');
    }

    public function test_old_ended_and_expired_rooms_are_pruned(): void
    {
        $live = $this->room();
        $justEnded = $this->room(['code' => 'ENDED001', 'ended_at' => now()]);
        $oldEnded = $this->room(['code' => 'ENDED002', 'ended_at' => now()->subDays(2)]);
        $oldExpired = $this->room(['code' => 'EXPIRED1', 'expires_at' => now()->subDays(2)]);

        $this->artisan('model:prune', ['--model' => [ClassroomSession::class]])->assertSuccessful();

        $this->assertModelExists($live);
        $this->assertModelExists($justEnded);
        $this->assertModelMissing($oldEnded);
        $this->assertModelMissing($oldExpired);
    }

    public function test_sessions_are_isolated_and_expire_after_eight_hours(): void
    {
        $room = $this->room();
        $other = $this->room(['code' => 'OTHER123']);
        $this->withSession($this->teacher($room))->putJson(route('classroom.update', $other->code), config('physics.initial_state'))
            ->assertForbidden();
        $this->withSession($this->student($room))->getJson(route('classroom.state', $other->code))->assertForbidden();
        $this->travel(9)->hours();
        $this->getJson(route('classroom.state', $room->code))->assertOk()->assertJsonPath('active', false);
    }
}
