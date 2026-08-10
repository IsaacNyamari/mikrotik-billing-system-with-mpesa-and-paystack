<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateCustomer
{
    public function __construct(protected AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Customer $customer, array $attributes): Customer
    {
        return DB::transaction(function () use ($customer, $attributes): Customer {
            $customer->fill($attributes);

            if (! $customer->isDirty()) {
                return $customer;
            }

            $customer->save();

            $this->audit->recordChange(
                event: 'customer.updated',
                model: $customer,
                description: sprintf('Updated customer %s', $customer->customer_number),
            );

            return $customer;
        });
    }
}
