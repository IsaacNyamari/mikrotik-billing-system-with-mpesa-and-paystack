<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named bundle of permissions.
 *
 * Roles are deliberately not globally scoped by tenant: a role with a null tenant_id is a
 * platform-defined role shared by every ISP, and those have to stay readable while resolving
 * the current tenant. Tenant-defined roles are filtered through availableTo() instead.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string $label
 * @property string|null $description
 * @property bool $is_system
 */
#[Fillable(['tenant_id', 'name', 'label', 'description', 'is_system'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Platform-defined roles plus the roles this ISP defined for itself.
     *
     * @param  Builder<Role>  $query
     */
    public function scopeAvailableTo(Builder $query, ?int $tenantId): void
    {
        $query->where(function (Builder $query) use ($tenantId): void {
            $query->whereNull('tenant_id')
                ->when($tenantId !== null, fn (Builder $query) => $query->orWhere('tenant_id', $tenantId));
        });
    }

    /**
     * Grant a permission to this role, ignoring a grant that already exists.
     */
    public function grant(Permission|string $permission): void
    {
        $permission = $permission instanceof Permission
            ? $permission
            : Permission::query()->where('name', $permission)->firstOrFail();

        $this->permissions()->syncWithoutDetaching([$permission->id]);
    }

    public function getRouteKeyName(): string
    {
        return 'name';
    }
}
