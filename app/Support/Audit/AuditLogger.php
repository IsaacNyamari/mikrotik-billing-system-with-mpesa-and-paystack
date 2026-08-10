<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes the audit trail.
 *
 * Every network-changing and financial operation goes through here, so the trail is one
 * table rather than scattered logging calls.
 */
class AuditLogger
{
    /**
     * Record an event against a model.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        string $event,
        ?Model $auditable = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): AuditLog {
        $attributes = [
            'tenant_id' => Tenancy::id(),
            'user_id' => Auth::id(),
            'event' => $event,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'url' => Request::fullUrl(),
        ];

        if ($auditable !== null) {
            $attributes['auditable_type'] = $auditable->getMorphClass();
            $attributes['auditable_id'] = $auditable->getKey();
        }

        // Platform-level events legitimately carry no tenant, and strict scoping would
        // otherwise refuse the write.
        return Tenancy::isBypassed() || Tenancy::has()
            ? AuditLog::query()->create($attributes)
            : Tenancy::withoutScope(fn (): AuditLog => AuditLog::query()->create($attributes));
    }

    /**
     * Record a change, keeping only the attributes that actually moved.
     */
    public function recordChange(string $event, Model $model, ?string $description = null): AuditLog
    {
        $changes = $model->getChanges();
        unset($changes['updated_at']);

        $original = array_intersect_key($model->getRawOriginal(), $changes);

        return $this->record(
            event: $event,
            auditable: $model,
            description: $description,
            oldValues: $original === [] ? null : $original,
            newValues: $changes === [] ? null : $changes,
        );
    }
}
