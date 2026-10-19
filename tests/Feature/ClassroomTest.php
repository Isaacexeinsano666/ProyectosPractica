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
        $this->get('/')->assertOk()->assertSee('Entrar a una clase')->assertSee('Crear sesión')
            ->assertDontSee('id="selectLiquido1"', false);
    }

    public function test_creating_a_room_sets_owner_access_and_persists_initial_state(): void
    {
        $response = $this->post('/sesiones');
        $room = ClassroomSession::firstOrFail();
        $response->assertRedirect(route('classroom.show', $room->code))
            ->assertSessionHas("classroom_owners.{$room->id}")
            ->assertSessionHas("classroom_members.{$room->id}", true);
        $this->assertSame('Presión hidrostática', $room->title);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $room->code);
        $this->assertSame(config('physics.initial_state'), $room->state);
        $this->get(route('classroom.show', $room->code))->assertOk()->assertSee('Profesor');
        $this->get('/')->assertSee('Continuar tu clase')->assertSee('Presión hidrostática');
    }

    public function test_student_can_join_with_lowercase_hyphenated_code(): void
    {
        $room = $this->room();
        $this->post('/unirse', ['code' => ' abcd-1234 '])->assertRedirect(route('classroom.show', $room->code))
            ->assertSessionHas("classroom_members.{$room->id}", true);
        $this->get(route('classroom.show', $room->code))->assertOk()
            ->assertSee('Estás siguiendo al profesor')->assertDontSee('Sí, finalizar clase');
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
        $state = ['fluido1' => 'mercurio', 'fluido2' => 'alcohol', 'altura1' => 1.75, 'altura2' => 0.5];
        $this->withSession($this->teacher($room))
            ->putJson(route('classroom.update', $room->code), $state)->assertOk()
            ->assertJsonPath('state.fluido1', 'mercurio')->assertJsonPath('revision', 1);
        $this->withSession($this->student($room))->getJson(route('classroom.state', $room->code))
            ->assertOk()->assertJsonPath('state.altura1', 1.75)->assertJsonPath('state.altura2', 0.5)
            ->assertJsonPath('active', true)->assertJsonMissingPath('owner_hash');
        $this->withSession(['classroom_owners' => [], 'classroom_members' => []]);
        $this->post('/unirse', ['code' => $room->code])->assertRedirect();
        $this->get(route('classroom.show', $room->code))->assertSee('mercurio')->assertSee('1.75');
    }

    public function test_invalid_fluid_and_out_of_range_heights_never_change_state(): void
    {
        $room = $this->room();
        $this->withSession($this->teacher($room))->putJson(route('classroom.update', $room->code), [
            'fluido1' => '<script>', 'fluido2' => 'agua', 'altura1' => -1, 'altura2' => 3,
        ])->assertUnprocessable()->assertJsonValidationErrors(['fluido1', 'altura1', 'altura2']);
        $this->assertSame(0, $room->fresh()->revision);
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
