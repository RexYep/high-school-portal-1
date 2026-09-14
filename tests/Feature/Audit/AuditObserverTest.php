<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Support\AuditService;
use Illuminate\Support\Facades\DB;

// No production model is audited yet (users are wired in Phase 2), so the
// skeleton User model is observed here purely as a test subject.
beforeEach(function () {
    User::observe(AuditObserver::class);
});

it('audits model creation with sensitive attributes redacted', function () {
    $user = User::factory()->create(['email' => 'teacher@example.com']);

    $entry = AuditLog::where('event', 'user.created')->sole();

    expect($entry->auditable_id)->toBe($user->id)
        ->and($entry->old_values)->toBeNull()
        ->and($entry->new_values['email'])->toBe('teacher@example.com')
        ->and($entry->new_values['password'])->toBe(AuditService::REDACTED)
        ->and($entry->new_values['remember_token'])->toBe(AuditService::REDACTED)
        ->and($entry->new_values)->not->toHaveKeys(['created_at', 'updated_at']);
});

it('audits only the changed attributes on update', function () {
    $user = User::factory()->create(['name' => 'Maria Santos', 'email' => 'maria@example.com']);

    $user->update(['name' => 'Maria Reyes']);

    $entry = AuditLog::where('event', 'user.updated')->sole();

    expect($entry->old_values)->toBe(['name' => 'Maria Santos'])
        ->and($entry->new_values)->toBe(['name' => 'Maria Reyes']);
});

it('skips updates that only touch timestamps', function () {
    $user = User::factory()->create();
    $this->travel(1)->minutes();

    $user->touch();

    expect(AuditLog::where('event', 'user.updated')->count())->toBe(0);
});

it('audits deletion with the previous attribute values', function () {
    $user = User::factory()->create(['email' => 'leaving@example.com']);

    $user->delete();

    $entry = AuditLog::where('event', 'user.deleted')->sole();

    expect($entry->auditable_id)->toBe($user->id)
        ->and($entry->old_values['email'])->toBe('leaving@example.com')
        ->and($entry->old_values['password'])->toBe(AuditService::REDACTED)
        ->and($entry->new_values)->toBeNull();
});

it('attributes the change to the authenticated user', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $target = User::factory()->create();

    expect(AuditLog::where('event', 'user.created')->where('auditable_id', $target->id)->sole()->user_id)
        ->toBe($actor->id);
});

it('rolls back the audit entry when the observed save is rolled back', function () {
    try {
        DB::transaction(function () {
            User::factory()->create();

            throw new RuntimeException('later step failed');
        });
    } catch (RuntimeException) {
    }

    expect(User::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});
