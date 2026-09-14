<?php

namespace App\Observers;

use App\Services\Support\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Base observer for audited models (§17.1). Opt a model in with
 * #[ObservedBy(AuditObserver::class)]. Events are named "<model>.<action>",
 * e.g. student.created.
 *
 * Runs synchronously so the entry joins the transaction of the save that fired it.
 * Business-significant actions (approvals, section changes) still call
 * AuditService::record() explicitly with their catalog event name and reason.
 */
class AuditObserver
{
    /**
     * Framework-managed columns whose change alone is not an auditable event.
     *
     * @var list<string>
     */
    private const IGNORED_ATTRIBUTES = ['created_at', 'updated_at'];

    public function __construct(private readonly AuditService $audit) {}

    public function created(Model $model): void
    {
        $this->audit->record(
            event: $this->eventName($model, 'created'),
            auditable: $model,
            new: $this->withoutIgnored($model->getAttributes()),
        );
    }

    public function updated(Model $model): void
    {
        $changes = $this->withoutIgnored($model->getChanges());

        if ($changes === []) {
            return;
        }

        $this->audit->record(
            event: $this->eventName($model, 'updated'),
            auditable: $model,
            old: array_intersect_key($model->getRawOriginal(), $changes),
            new: $changes,
        );
    }

    public function deleted(Model $model): void
    {
        $this->audit->record(
            event: $this->eventName($model, 'deleted'),
            auditable: $model,
            old: $this->withoutIgnored($model->getRawOriginal()),
        );
    }

    private function eventName(Model $model, string $action): string
    {
        return Str::snake(class_basename($model)).'.'.$action;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withoutIgnored(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(self::IGNORED_ATTRIBUTES));
    }
}
