<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * An ISP's HostPinnacle account, for texting their own subscribers under their own sender ID.
 *
 * One row per ISP. Without one, the platform's own account (from the environment) is used when
 * there is one, so an ISP can start reminding subscribers before they have an SMS account.
 *
 * @property int $id
 * @property int $tenant_id
 * @property bool $is_enabled
 * @property string|null $username
 * @property string|null $password
 * @property string|null $api_key
 * @property string|null $sender_id
 * @property int $remind_days_before
 */
#[Fillable(['is_enabled', 'username', 'sender_id', 'remind_days_before'])]
#[Hidden(['password', 'api_key'])]
class SmsSetting extends Model
{
    use BelongsToTenant;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_enabled' => true,
        'remind_days_before' => 3,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'remind_days_before' => 'integer',
            'password' => 'encrypted',
            'api_key' => 'encrypted',
        ];
    }

    /**
     * This ISP's row, or an unsaved default so callers never have to ask whether one exists.
     */
    public static function current(): self
    {
        return self::query()->first() ?? new self;
    }

    /**
     * Whether this ISP has given us everything HostPinnacle needs.
     */
    public function isComplete(): bool
    {
        return filled($this->username) && filled($this->password) && filled($this->api_key) && filled($this->sender_id);
    }
}
