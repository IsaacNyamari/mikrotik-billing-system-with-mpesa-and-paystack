<?php

namespace App\Support\Numbering;

use App\Exceptions\TenantContextMissingException;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Produces the per-ISP running numbers customers and service accounts are known by.
 *
 * Numbers restart per tenant, so two ISPs both having CUS-000001 is correct and expected.
 * Allocation locks the owning tenant row, which serialises concurrent callers within an ISP
 * without blocking any other ISP.
 */
class SequenceGenerator
{
    /**
     * Allocate the next number in a sequence, e.g. "CUS-000042".
     *
     * Must run inside a transaction so the lock is held until the new row is written;
     * otherwise two requests can read the same maximum and collide on the unique index.
     */
    public function next(Sequence $sequence, int $padding = 6): string
    {
        $tenantId = Tenancy::id();

        if ($tenantId === null) {
            throw TenantContextMissingException::forModel($sequence->table(), 'number');
        }

        if (DB::transactionLevel() === 0) {
            throw new LogicException(sprintf(
                'Allocating a [%s] number outside a transaction would let two requests take '.
                'the same number. Wrap the call in DB::transaction().',
                $sequence->table(),
            ));
        }

        // Locking the tenant row is what serialises numbering for this ISP.
        DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();

        $prefix = $sequence->prefix();

        // Soft-deleted rows are counted deliberately: a number that has been used once is
        // never handed out again.
        $latest = DB::table($sequence->table())
            ->where('tenant_id', $tenantId)
            ->where($sequence->column(), 'like', $prefix.'-%')
            ->orderByRaw($sequence->highestFirst())
            ->value($sequence->column());

        $next = $latest === null
            ? 1
            : ((int) substr((string) $latest, strlen($prefix) + 1)) + 1;

        return $prefix.'-'.str_pad((string) $next, $padding, '0', STR_PAD_LEFT);
    }
}
