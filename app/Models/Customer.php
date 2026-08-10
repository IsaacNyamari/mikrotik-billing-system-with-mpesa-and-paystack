<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A subscriber of one ISP.
 *
 * A customer is a billing and support identity, not a network login — the logins live on
 * service accounts, and one customer may hold several.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $customer_number
 * @property CustomerType $type
 * @property string $name
 * @property string|null $company_name
 * @property string|null $email
 * @property string $phone
 * @property string|null $alternate_phone
 * @property string|null $national_id
 * @property string|null $address
 * @property string|null $city
 * @property CustomerStatus $status
 * @property string|null $notes
 */
#[Fillable([
    'customer_number',
    'type',
    'name',
    'company_name',
    'email',
    'phone',
    'alternate_phone',
    'national_id',
    'address',
    'city',
    'status',
    'notes',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'individual',
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'status' => CustomerStatus::class,
        ];
    }

    /**
     * @return HasMany<ServiceAccount, $this>
     */
    public function serviceAccounts(): HasMany
    {
        return $this->hasMany(ServiceAccount::class);
    }

    /**
     * @return HasMany<CustomerContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    /**
     * The name to show on screens and invoices.
     */
    public function displayName(): string
    {
        return $this->type === CustomerType::Company && $this->company_name !== null
            ? $this->company_name
            : $this->name;
    }

    /**
     * Match against the fields an operator actually types when looking someone up.
     *
     * @param  Builder<Customer>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $like = '%'.$term.'%';

            $query->where('name', 'like', $like)
                ->orWhere('company_name', 'like', $like)
                ->orWhere('customer_number', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('alternate_phone', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhereHas('serviceAccounts', fn (Builder $accounts) => $accounts->where('username', 'like', $like));
        });
    }
}
