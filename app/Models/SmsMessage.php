<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One text sent, or attempted, to a subscriber.
 *
 * Kept so an ISP can answer "nobody told me" with what was sent and when.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $service_account_id
 * @property string $phone
 * @property string $kind
 * @property string $body
 * @property string $status
 * @property string|null $error
 * @property array<string, mixed>|null $response
 */
#[Fillable(['service_account_id', 'phone', 'kind', 'body', 'status', 'error', 'response'])]
class SmsMessage extends Model
{
    use BelongsToTenant;

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ServiceAccount, $this>
     */
    public function serviceAccount(): BelongsTo
    {
        return $this->belongsTo(ServiceAccount::class);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::SENT => 'green',
            self::FAILED => 'red',
            default => 'zinc',
        };
    }
}
