<?php

namespace App\Actions\ServiceAccounts;

use App\Enums\ServiceType;
use App\Models\Customer;
use App\Models\ServiceAccount;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\Sequence;
use App\Support\Numbering\SequenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateServiceAccount
{
    public function __construct(
        protected SequenceGenerator $sequence,
        protected AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Customer $customer, array $attributes): ServiceAccount
    {
        return DB::transaction(function () use ($customer, $attributes): ServiceAccount {
            $account = new ServiceAccount($attributes);
            $account->customer_id = $customer->id;

            $account->account_number = $attributes['account_number']
                ?? $this->sequence->next(Sequence::ServiceAccount);

            $type = $account->type ?? ServiceType::Pppoe;

            // A static-IP service is authorised by address, so a secret would only be a
            // credential lying around with nothing to authenticate.
            $account->secret = $type->usesCredentials()
                ? ($attributes['secret'] ?? $this->generateSecret())
                : null;

            $account->save();

            $this->audit->record(
                event: 'service_account.created',
                auditable: $account,
                description: sprintf(
                    'Created %s service %s for customer %s',
                    $type->label(),
                    $account->account_number,
                    $customer->customer_number,
                ),
                newValues: $account->only(['account_number', 'type', 'username', 'status']),
            );

            return $account;
        });
    }

    /**
     * A network password an operator can read out over the phone without ambiguity.
     */
    protected function generateSecret(): string
    {
        return Str::password(12, symbols: false);
    }
}
