<?php

namespace App\Models;

use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A single capability, such as "customers.update".
 *
 * The catalogue is platform-wide and fixed by the application, so permissions are never
 * tenant-owned — only the roles that bundle them can be.
 *
 * @property int $id
 * @property string $name
 * @property string $group
 * @property string $label
 */
#[Fillable(['name', 'group', 'label'])]
class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function getRouteKeyName(): string
    {
        return 'name';
    }
}
