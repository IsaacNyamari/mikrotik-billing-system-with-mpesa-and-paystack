<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * One platform-wide setting edited in the dashboard, keyed by the config path it overrides
 * (`services.hostpinnacle.api_key`, `mail.mailers.smtp.host`…). Not tenant-owned: there is one
 * set for the whole installation. Every value is encrypted, secret or not, so nobody has to
 * decide which ones matter.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 */
#[Fillable(['key', 'value'])]
#[Hidden(['value'])]
class PlatformSetting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }
}
