<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\Sequence;
use App\Support\Numbering\SequenceGenerator;
use Illuminate\Support\Facades\DB;

class CreateCustomer
{
    public function __construct(
        protected SequenceGenerator $sequence,
        protected AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): Customer
    {
        return DB::transaction(function () use ($attributes): Customer {
            $customer = new Customer($attributes);

            $customer->customer_number = $attributes['customer_number']
                ?? $this->sequence->next(Sequence::Customer);

            // tenant_id is stamped by BelongsToTenant; it is never taken from input.
            $customer->save();

            $this->audit->record(
                event: 'customer.created',
                auditable: $customer,
                description: sprintf('Created customer %s', $customer->customer_number),
                newValues: $customer->only(['customer_number', 'name', 'phone', 'status']),
            );

            return $customer;
        });
    }
}
