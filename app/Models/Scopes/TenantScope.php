<?php

namespace App\Models\Scopes;

use App\Exceptions\TenantContextMissingException;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the current ISP.
 *
 * With strict tenancy enabled, a query issued with no tenant resolved and no deliberate bypass
 * fails loudly rather than quietly returning every ISP's rows.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenancy::shouldScope()) {
            $builder->where(
                $model->qualifyColumn(config('tenancy.foreign_key')),
                Tenancy::id(),
            );

            return;
        }

        if (Tenancy::isBypassed()) {
            return;
        }

        if (config('tenancy.strict')) {
            throw TenantContextMissingException::forModel($model::class, 'query');
        }
    }
}
