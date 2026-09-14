<?php

use App\Exceptions\ImmutableAuditLogException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Support\AuditService;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->audit = app(AuditService::class);
});

it('records the event, acting user, target, and reason', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $this->actingAs($actor);

    $entry = $this->audit->record(
        event: 'user.status_changed',
        auditable: $target,
        old: ['status' => 'active'],
        new: ['status' => 'suspended'],
        reason: 'Repeated policy violations',
    );

    $entry->refresh();

    expect($entry->user_id)->toBe($actor->id)
        ->and($entry->event)->toBe('user.status_changed')
        ->and($entry->auditable_type)->toBe($target->getMorphClass())
        ->and($entry->auditable_id)->toBe($target->id)
        ->and($entry->old_values)->toBe(['status' => 'active'])
        ->and($entry->new_values)->toBe(['status' => 'suspended'])
        ->and($entry->reason)->toBe('Repeated policy violations')
        ->and($entry->created_at)->not->toBeNull();
});

it('stores only the attributes whose values changed', function () {
    $entry = $this->audit->record(
        event: 'student.updated',
        old: ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'lrn' => '123456789012'],
        new: ['first_name' => 'Juan', 'last_name' => 'De la Cruz', 'lrn' => '123456789012'],
    );

    expect($entry->refresh()->old_values)->toBe(['last_name' => 'Dela Cruz'])
        ->and($entry->new_values)->toBe(['last_name' => 'De la Cruz']);
});

it('keeps keys that exist on only one side of the change', function () {
    $entry = $this->audit->record(
        event: 'student.updated',
        old: ['middle_name' => 'Santos'],
        new: ['suffix' => 'Jr.'],
    );

    expect($entry->refresh()->old_values)->toBe(['middle_name' => 'Santos'])
        ->and($entry->new_values)->toBe(['suffix' => 'Jr.']);
});

it('redacts passwords, tokens, secrets, and session ids before writing', function () {
    $entry = $this->audit->record(
        event: 'user.updated',
        old: ['password' => 'old-hash', 'email' => 'old@example.com'],
        new: [
            'password' => 'new-hash',
            'email' => 'new@example.com',
            'remember_token' => 'abc123',
            'two_factor_secret' => 'JBSWY3DP',
            'two_factor_recovery_codes' => ['code-1'],
            'session_id' => 'sess-1',
            'profile' => ['api_token' => 'nested-token', 'nickname' => 'JD'],
        ],
    );

    $raw = DB::table('audit_logs')->where('id', $entry->id)->first();

    foreach (['old-hash', 'new-hash', 'abc123', 'JBSWY3DP', 'code-1', 'sess-1', 'nested-token'] as $secret) {
        expect($raw->old_values.$raw->new_values)->not->toContain($secret);
    }

    expect($entry->refresh()->new_values)->toMatchArray([
        'password' => AuditService::REDACTED,
        'email' => 'new@example.com',
        'remember_token' => AuditService::REDACTED,
        'two_factor_secret' => AuditService::REDACTED,
        'two_factor_recovery_codes' => AuditService::REDACTED,
        'session_id' => AuditService::REDACTED,
        'profile' => ['api_token' => AuditService::REDACTED, 'nickname' => 'JD'],
    ])->and($entry->old_values['password'])->toBe(AuditService::REDACTED);
});

it('redacts sensitive keys passed in context', function () {
    $entry = $this->audit->record(event: 'auth.password_reset_requested', context: ['token' => 'reset-token']);

    expect($entry->refresh()->context['token'])->toBe(AuditService::REDACTED);
});

it('records a system actor when no user is authenticated', function () {
    $entry = $this->audit->record(event: 'backup.completed');

    expect($entry->refresh()->user_id)->toBeNull()
        ->and($entry->context['actor'])->toBe('system')
        ->and($entry->old_values)->toBeNull()
        ->and($entry->new_values)->toBeNull();
});

it('captures ip address and user agent only when requested', function () {
    request()->server->set('REMOTE_ADDR', '203.0.113.7');
    request()->headers->set('User-Agent', 'PortalTest/1.0');

    $routine = $this->audit->record(event: 'student.updated');
    $sensitive = $this->audit->record(event: 'auth.login', withRequestMetadata: true);

    expect($routine->refresh()->ip_address)->toBeNull()
        ->and($routine->user_agent)->toBeNull()
        ->and($sensitive->refresh()->ip_address)->toBe('203.0.113.7')
        ->and($sensitive->user_agent)->toBe('PortalTest/1.0');
});

it('includes the request id from the log context when present', function () {
    Context::add('request_id', 'req-01J8X');

    $entry = $this->audit->record(event: 'export.generated', context: ['count' => 120]);

    expect($entry->refresh()->context)->toMatchArray(['request_id' => 'req-01J8X', 'count' => 120]);
});

it('rolls the audit entry back with the transaction it belongs to', function () {
    try {
        DB::transaction(function () {
            $this->audit->record(event: 'enrollment.created');

            throw new RuntimeException('enrollment failed');
        });
    } catch (RuntimeException) {
    }

    expect(AuditLog::count())->toBe(0);
});

it('refuses to update an audit entry', function () {
    $entry = $this->audit->record(event: 'grade.approved');

    $entry->reason = 'tampered';
    $entry->save();
})->throws(ImmutableAuditLogException::class);

it('refuses to delete an audit entry', function () {
    $entry = $this->audit->record(event: 'grade.approved');

    $entry->delete();
})->throws(ImmutableAuditLogException::class);

it('leaves the stored entry unchanged after a refused update', function () {
    $entry = $this->audit->record(event: 'grade.approved', reason: 'original');

    try {
        $entry->update(['reason' => 'tampered']);
    } catch (ImmutableAuditLogException) {
    }

    expect(AuditLog::find($entry->id)->reason)->toBe('original');
});
