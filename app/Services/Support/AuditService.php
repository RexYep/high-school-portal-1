<?php

namespace App\Services\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * The single write path for audit_logs (architecture §17).
 *
 * record() does not open its own transaction: call it inside the caller's
 * DB::transaction() so the audit entry and the change it describes commit or
 * roll back together.
 */
class AuditService
{
    public const REDACTED = '[REDACTED]';

    /**
     * Attribute keys containing any of these fragments are never written (§17.4).
     *
     * @var list<string>
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'token',
        'secret',
        'session_id',
        'recovery_code',
    ];

    /**
     * @param  array<string, mixed>  $old  attribute values before the change
     * @param  array<string, mixed>  $new  attribute values after the change
     * @param  array<string, mixed>  $context  extra structured detail, e.g. ['count' => 120] for bulk actions
     * @param  bool  $withRequestMetadata  capture IP and user agent (authentication and sensitive-access events only)
     */
    public function record(
        string $event,
        ?Model $auditable = null,
        array $old = [],
        array $new = [],
        ?string $reason = null,
        array $context = [],
        bool $withRequestMetadata = false,
    ): AuditLog {
        [$old, $new] = $this->changedOnly($old, $new);

        $userId = Auth::id();
        $request = $this->currentRequest();

        return AuditLog::create([
            'user_id' => $userId,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'event' => $event,
            'old_values' => $old === [] ? null : $this->redact($old),
            'new_values' => $new === [] ? null : $this->redact($new),
            'reason' => $reason,
            'ip_address' => $withRequestMetadata ? $request->ip() : null,
            'user_agent' => $withRequestMetadata && $request->userAgent() !== null
                ? substr($request->userAgent(), 0, 512)
                : null,
            'context' => $this->buildContext($context, $userId === null, $request),
        ]);
    }

    /**
     * Resolved per call, never injected: observers hold this service across requests.
     */
    private function currentRequest(): Request
    {
        return request();
    }

    /**
     * Keep only the keys whose values differ between $old and $new.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function changedOnly(array $old, array $new): array
    {
        $changedKeys = [];

        foreach (array_keys($old + $new) as $key) {
            $inOld = array_key_exists($key, $old);
            $inNew = array_key_exists($key, $new);

            if ($inOld !== $inNew || $old[$key] !== $new[$key]) {
                $changedKeys[$key] = true;
            }
        }

        return [
            array_intersect_key($old, $changedKeys),
            array_intersect_key($new, $changedKeys),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $values[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function buildContext(array $context, bool $isSystemActor, Request $request): ?array
    {
        $base = array_filter([
            'actor' => $isSystemActor ? 'system' : null,
            'request_id' => Context::get('request_id'),
            'route' => $request->route()?->getName(),
        ], fn ($value) => $value !== null);

        $merged = $this->redact($base + $context);

        return $merged === [] ? null : $merged;
    }
}
