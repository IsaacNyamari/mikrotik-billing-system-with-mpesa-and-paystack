<?php

namespace App\Models\Concerns;

use App\Exceptions\CrossTenantAccessException;
use App\Exceptions\TenantContextMissingException;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as owned by one ISP.
 *
 * Applying this trait does three things: every query is constrained to the current tenant,
 * new records take their tenant_id from the current tenant automatically, and any attempt to
 * read or write another tenant's row through a loophole throws.
 *
 * @property int|null $tenant_id
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            $model->applyTenantOnCreate();
        });

        static::saving(function (self $model): void {
            $model->guardAgainstCrossTenantWrite();
        });

        static::deleting(function (self $model): void {
            $model->guardAgainstCrossTenantWrite();
        });

        static::retrieved(function (self $model): void {
            $model->guardAgainstCrossTenantRead();
        });
    }

    /**
     * The ISP that owns this record.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Stamp ownership onto a new record.
     */
    protected function applyTenantOnCreate(): void
    {
        $key = config('tenancy.foreign_key');

        // Ownership was set by hand — honour it, but never let it point somewhere else.
        if ($this->getAttribute($key) !== null) {
            $this->guardAgainstCrossTenantWrite();

            return;
        }

        if (Tenancy::shouldScope()) {
            $this->setAttribute($key, Tenancy::id());

            return;
        }

        // A deliberate platform-wide write. Tables that require ownership still reject the
        // null at the database level, which is the backstop we want.
        if (Tenancy::isBypassed()) {
            return;
        }

        if (config('tenancy.strict')) {
            throw TenantContextMissingException::forModel(static::class, 'create');
        }
    }

    /**
     * Refuse to write a record belonging to another ISP.
     */
    protected function guardAgainstCrossTenantWrite(): void
    {
        $this->guardTenantOwnership();
    }

    /**
     * Refuse to hand back a record belonging to another ISP.
     *
     * The global scope already prevents this on ordinary queries. This catches the loopholes:
     * withoutGlobalScopes(), raw joins hydrated into models, and relations resolved from a
     * cached instance.
     */
    protected function guardAgainstCrossTenantRead(): void
    {
        $this->guardTenantOwnership();
    }

    protected function guardTenantOwnership(): void
    {
        if (! Tenancy::shouldScope()) {
            return;
        }

        $tenantId = $this->getAttribute(config('tenancy.foreign_key'));

        if ($tenantId !== null && (int) $tenantId !== Tenancy::id()) {
            throw CrossTenantAccessException::for(static::class, (int) $tenantId, Tenancy::id());
        }
    }
}
