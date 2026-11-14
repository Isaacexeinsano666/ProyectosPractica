<?php

namespace Tests\Feature;

use App\Models\ClassroomMember;
use App\Models\ClassroomSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoardTest extends TestCase
{
    use RefreshDatabase;

    private const PEN = ['type' => 'pen', 'color' => '#1f2a2e', 'points' => [[0.1, 0.1], [0.2, 0.25], [0.3, 0.2]]];

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

    private function asTeacher(ClassroomSession $room): self
    {
        return $this->withSession(['classroom_owners' => [$room->id => 'teacher-secret']]);
    }

    /** Un navegador de alumno distinto por cada $key. */
    private function asStudent(ClassroomSession $room, string $key): self
    {
        return $this->withSession([
            'classroom_owners' => [],
            'classroom_members' => [$room->id => true],
            'classroom_presence' => [$room->id => ['key' => $key, 'at' => 0]],
        ]);
    }

    private function member(string $key): ClassroomMember
    {
        return ClassroomMember::where('member_key', $key)->firstOrFail();
    }

    private function grant(ClassroomSession $room, string $key, bool $draw = true, bool $moderate = false)
    {
        return $this->asTeacher($room)->putJson(
            route('board.permissions', [$room->code, $this->member($key)->id]),
            ['can_draw' => $draw, 'can_moderate' => $moderate],
        );
    }

    public function test_teacher_draws_and_students_see_it(): void
    {
        $room = $this->room();
        $this->asTeacher($room)->postJson(route('board.strokes.store', $room->code), self::PEN)
            ->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('strokes.0.author', 'Profesor')
            ->assertJsonPath('strokes.0.mine', true);

        $this->asStudent($room, 'alumno-a')->getJson(route('board.show', $room->code))
            ->assertOk()->assertJsonCount(1, 'strokes')->assertJsonPath('strokes.0.mine', false)
            ->assertJsonPath('me.can_draw', false)->assertJsonMissingPath('students');
        $this->getJson(route('classroom.state', $room->code))->assertJsonPath('board_revision', 1);
    }

    public function test_student_needs_permission_to_draw(): void
    {
        $room = $this->room();
        $this->asStudent($room, 'alumno-a')->postJson(route('board.strokes.store', $room->code), self::PEN)
            ->assertForbidden();
        $this->assertSame(0, $room->strokes()->count());

        $this->grant($room, 'alumno-a')->assertOk();
        $this->asStudent($room, 'alumno-a')->postJson(route('board.strokes.store', $room->code), self::PEN)
            ->assertOk()->assertJsonPath('me.can_draw', true)->assertJsonPath('strokes.0.mine', true);

        $this->grant($room, 'alumno-a', false)->assertOk();
        $this->asStudent($room, 'alumno-a')->postJson(route('board.strokes.store', $room->code), self::PEN)
            ->assertForbidden();
    }

    public function test_free_board_lets_every_student_draw(): void
    {
        $room = $this->room();
        $this->asTeacher($room)->putJson(route('board.open', $room->code), ['open' => true])->assertOk();
        $this->asStudent($room, 'alumno-b')->postJson(route('board.strokes.store', $room->code), self::PEN)
            ->assertOk()->assertJsonPath('open', true);
    }

    public function test_students_only_erase_their_own_strokes_unless_they_can_moderate(): void
    {
        $room = $this->room();
        $teacherStroke = $this->asTeacher($room)->postJson(route('board.strokes.store', $room->code), self::PEN)
            ->json('strokes.0.id');
        $this->asStudent($room, 'alumno-a')->getJson(route('board.show', $room->code));
        $this->grant($room, 'alumno-a');
        $own = $this->asStudent($room, 'alumno-a')->postJson(route('board.strokes.store', $room->code), self::PEN)
            ->json('strokes.1.id');

        $this->deleteJson(route('board.strokes.destroy', [$room->code, $teacherStroke]))->assertForbidden();
        $this->deleteJson(route('board.clear', $room->code))->assertForbidden();
        $this->deleteJson(route('board.strokes.destroy', [$room->code, $own]))->assertOk()->assertJsonCount(1, 'strokes');

        $this->grant($room, 'alumno-a', true, true);
        $this->asStudent($room, 'alumno-a')->deleteJson(route('board.strokes.destroy', [$room->code, $teacherStroke]))
            ->assertOk()->assertJsonCount(0, 'strokes');
        $this->postJson(route('board.strokes.store', $room->code), self::PEN);
        $this->deleteJson(route('board.clear', $room->code))->assertOk()->assertJsonCount(0, 'strokes');
    }

    public function test_strokes_from_another_class_cannot_be_deleted(): void
    {
        $room = $this->room();
        $other = $this->room(['code' => 'OTHER123', 'owner_hash' => hash('sha256', 'other-secret')]);
        $this->withSession(['classroom_owners' => [$other->id => 'other-secret']]);
        $strokeId = $this->postJson(route('board.strokes.store', $other->code), self::PEN)->json('strokes.0.id');

        $this->asTeacher($room)->deleteJson(route('board.strokes.destroy', [$room->code, $strokeId]))->assertNotFound();
        $this->assertSame(1, $other->strokes()->count());
    }

    public function test_raised_hand_reaches_the_teacher_and_permission_lowers_it(): void
    {
        $room = $this->room();
        $this->post('/unirse', ['code' => $room->code, 'name' => '  Carla   Pérez ']);
        $this->postJson(route('board.hand', $room->code), ['raised' => true])
            ->assertOk()->assertJsonPath('me.hand_raised', true)->assertJsonPath('me.name', 'Carla Pérez');

        $this->asTeacher($room)->getJson(route('board.show', $room->code))
            ->assertJsonPath('students.0.name', 'Carla Pérez')->assertJsonPath('students.0.hand_raised', true)
            ->assertJsonPath('students.0.online', true);
        $member = ClassroomMember::firstOrFail();
        $this->putJson(route('board.permissions', [$room->code, $member->id]), ['can_draw' => true, 'can_moderate' => false])
            ->assertJsonPath('students.0.can_draw', true)->assertJsonPath('students.0.hand_raised', false);
    }

    public function test_only_the_teacher_manages_permissions(): void
    {
        $room = $this->room();
        $this->asStudent($room, 'alumno-a')->getJson(route('board.show', $room->code));
        $id = $this->member('alumno-a')->id;
        $this->putJson(route('board.permissions', [$room->code, $id]), ['can_draw' => true, 'can_moderate' => true])
            ->assertForbidden();
        $this->putJson(route('board.open', $room->code), ['open' => true])->assertForbidden();
        $this->assertFalse($this->member('alumno-a')->can_draw);
    }

    public function test_invalid_strokes_are_rejected(): void
    {
        $room = $this->room();
        $this->asTeacher($room);
        $this->postJson(route('board.strokes.store', $room->code), ['type' => 'pen', 'color' => '#123456', 'points' => [[0.1, 0.1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('color');
        $this->postJson(route('board.strokes.store', $room->code), ['type' => 'pen', 'color' => '#1f2a2e', 'points' => [[1.5, 0.1]]])
            ->assertUnprocessable();
        $this->postJson(route('board.strokes.store', $room->code), ['type' => 'arrow', 'color' => '#1f2a2e', 'points' => [[0.1, 0.1], [0.2, 0.2], [0.3, 0.3]]])
            ->assertUnprocessable()->assertJsonValidationErrors('points');
        $this->postJson(route('board.strokes.store', $room->code), ['type' => 'circle', 'color' => '#1f2a2e', 'points' => [[0.1, 0.1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertSame(0, $room->strokes()->count());
    }

    public function test_board_is_read_only_after_the_class_ends(): void
    {
        $room = $this->room(['ended_at' => now()]);
        $this->asTeacher($room)->postJson(route('board.strokes.store', $room->code), self::PEN)->assertGone();
        $this->getJson(route('board.show', $room->code))->assertOk()->assertJsonPath('active', false);
    }

    public function test_teacher_can_send_the_class_to_the_board(): void
    {
        $room = $this->room();
        $state = array_merge(config('physics.initial_state'), ['activity' => 'pizarra']);
        $this->asTeacher($room)->putJson(route('classroom.update', $room->code), $state)->assertOk();
        $this->asStudent($room, 'alumno-a')->getJson(route('classroom.state', $room->code))
            ->assertJsonPath('state.activity', 'pizarra');
        $this->get(route('classroom.show', $room->code))->assertOk()->assertSee('id="board"', false)
            ->assertDontSee('id="board-toggle"', false)->assertSee('id="board-hand"', false);
        $this->asTeacher($room)->get(route('classroom.show', $room->code))
            ->assertSee('id="board-toggle"', false)->assertSee('Permisos de la pizarra');
    }

    public function test_teacher_drawing_restarts_the_inactivity_timer(): void
    {
        $this->freezeSecond();
        $room = $this->room();
        $this->travel(50)->minutes();
        $this->asTeacher($room)->postJson(route('board.strokes.store', $room->code), self::PEN)->assertOk();
        $this->assertSame(3600, $room->fresh()->secondsUntilExpiry());
    }

    public function test_joining_requires_a_name(): void
    {
        $room = $this->room();
        $this->post('/unirse', ['code' => $room->code])->assertSessionHasErrors('name');
        $this->post('/unirse', ['code' => $room->code, 'name' => '   '])->assertSessionHasErrors('name');
        $this->assertSame(0, ClassroomMember::count());
    }

    public function test_deleting_a_class_deletes_its_board(): void
    {
        $room = $this->room(['ended_at' => now()->subDays(2)]);
        $room->strokes()->create(['author_name' => 'Profesor', 'type' => 'pen', 'color' => '#1f2a2e', 'points' => [[0.1, 0.1]]]);
        $this->artisan('model:prune', ['--model' => [ClassroomSession::class]])->assertSuccessful();
        $this->assertDatabaseCount('classroom_strokes', 0);
    }
}
