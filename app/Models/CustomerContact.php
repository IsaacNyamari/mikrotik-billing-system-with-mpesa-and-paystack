<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An additional person to reach at a customer — a site contact, an accounts payable clerk,
 * a next of kin.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property string $name
 * @property string|null $title
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_primary
 * @property string|null $notes
 */
#[Fillable(['customer_id', 'name', 'title', 'email', 'phone', 'is_primary', 'notes'])]
class CustomerContact extends Model
{
    /** @use HasFactory<CustomerContactFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_primary' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
