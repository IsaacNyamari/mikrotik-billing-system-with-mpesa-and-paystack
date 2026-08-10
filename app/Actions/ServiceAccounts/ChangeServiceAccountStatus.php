<?php

namespace App\Actions\ServiceAccounts;

use App\Enums\ServiceAccountStatus;
use App\Models\ServiceAccount;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Moves a service account through its lifecycle.
 *
 * From Phase 7 onwards this is also where the RADIUS authorization is refreshed and any live
 * session is disconnected, so status changes must funnel through here rather than being
 * assigned directly on the model.
 */
class ChangeServiceAccountStatus
{
    public function __construct(protected AuditLogger $audit) {}

    public function handle(
        ServiceAccount $account,
        ServiceAccountStatus $status,
        ?string $reason = null,
    ): ServiceAccount {
        if ($account->status === $status) {
            return $account;
        }

        if ($account->status->isFinal()) {
            throw new LogicException(sprintf(
                'Service account %s is terminated and cannot be moved to %s.',
                $account->account_number,
                $status->value,
            ));
        }

        return DB::transaction(function () use ($account, $status, $reason): ServiceAccount {
            $previous = $account->status;

            $account->status = $status;

            match ($status) {
                ServiceAccountStatus::Active => $account->activated_at ??= now(),
                ServiceAccountStatus::Suspended => $account->suspended_at = now(),
                ServiceAccountStatus::Terminated => $account->terminated_at = now(),
                ServiceAccountStatus::Pending => null,
            };

            $account->save();

            $this->audit->record(
                event: 'service_account.status_changed',
                auditable: $account,
                description: $reason ?? sprintf(
                    'Service %s moved from %s to %s',
                    $account->account_number,
                    $previous->value,
                    $status->value,
                ),
                oldValues: ['status' => $previous->value],
                newValues: ['status' => $status->value],
            );

            return $account;
        });
    }
}
