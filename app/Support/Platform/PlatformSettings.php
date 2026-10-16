<?php

namespace App\Support\Platform;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Platform settings edited in the dashboard rather than in .env.
 *
 * .env stays the starting point — it is what a fresh server boots with — and anything saved
 * here is laid over it at boot, key by key. Clearing a field here falls back to .env again.
 * The web server cannot write .env (it is root's, 640) and should not: a form that rewrote it
 * could take the database password with it.
 *
 * Applied on every request and every queue job, from a cache of the encrypted rows, so the
 * cost is one cache read and a few decryptions. Saving clears that cache and restarts the
 * queue workers, which otherwise keep the configuration they booted with.
 */
class PlatformSettings
{
    public const CACHE_KEY = 'platform-settings:rows';

    /**
     * Every editable setting: group => [config key => definition].
     *
     * @return array<string, array{title: string, description: string, fields: array<string, array{label: string, type: string, secret?: bool, rules: list<string>, help?: string, options?: array<string, string>}>}>
     */
    public static function groups(): array
    {
        return [
            'general' => [
                'title' => __('General'),
                'description' => __('How the platform names itself to ISPs and their customers.'),
                'fields' => [
                    'app.name' => ['label' => __('Platform name'), 'type' => 'text', 'rules' => ['nullable', 'string', 'max:60'], 'help' => __('Page titles, emails and the footer.')],
                ],
            ],
            'sms' => [
                'title' => __('SMS (HostPinnacle)'),
                'description' => __('The platform\'s own account. Used for ISPs that have not entered their own, and as the backup when an ISP\'s account refuses a router alert.'),
                'fields' => [
                    'services.hostpinnacle.username' => ['label' => __('Username'), 'type' => 'text', 'rules' => ['nullable', 'string', 'max:120']],
                    'services.hostpinnacle.password' => ['label' => __('Password'), 'type' => 'password', 'secret' => true, 'rules' => ['nullable', 'string', 'max:255']],
                    'services.hostpinnacle.api_key' => ['label' => __('API key'), 'type' => 'password', 'secret' => true, 'rules' => ['nullable', 'string', 'max:255']],
                    'services.hostpinnacle.sender_id' => ['label' => __('Sender ID'), 'type' => 'text', 'rules' => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z0-9 ._-]+$/'], 'help' => __('Exactly as approved by HostPinnacle, up to 11 characters.')],
                ],
            ],
            'mail' => [
                'title' => __('Email (SMTP)'),
                'description' => __('Invoices, receipts and router alert copies go out through this.'),
                'fields' => [
                    'mail.default' => ['label' => __('Sending'), 'type' => 'select', 'rules' => ['nullable', 'in:smtp,log'], 'options' => ['smtp' => __('Send through SMTP'), 'log' => __('Off: write to the log only')]],
                    'mail.mailers.smtp.host' => ['label' => __('SMTP host'), 'type' => 'text', 'rules' => ['nullable', 'string', 'max:190']],
                    'mail.mailers.smtp.port' => ['label' => __('Port'), 'type' => 'number', 'rules' => ['nullable', 'integer', 'between:1,65535'], 'help' => __('587 for STARTTLS, 465 for SSL.')],
                    'mail.mailers.smtp.scheme' => ['label' => __('Encryption'), 'type' => 'select', 'rules' => ['nullable', 'in:smtp,smtps'], 'options' => ['smtp' => __('STARTTLS (port 587 / 25)'), 'smtps' => __('SSL (port 465)')]],
                    'mail.mailers.smtp.username' => ['label' => __('Username'), 'type' => 'text', 'rules' => ['nullable', 'string', 'max:190']],
                    'mail.mailers.smtp.password' => ['label' => __('Password'), 'type' => 'password', 'secret' => true, 'rules' => ['nullable', 'string', 'max:255']],
                    'mail.from.address' => ['label' => __('From address'), 'type' => 'email', 'rules' => ['nullable', 'email', 'max:190']],
                    'mail.from.name' => ['label' => __('From name'), 'type' => 'text', 'rules' => ['nullable', 'string', 'max:60']],
                ],
            ],
            'pricing' => [
                'title' => __('Pricing & terms'),
                'description' => __('What new ISPs are charged. An ISP keeps the rates copied onto their subscription when they signed up; changing these does not re-price anyone.'),
                'fields' => [
                    'platform.pricing.onboarding_fee' => ['label' => __('Setup fee (KES)'), 'type' => 'number', 'rules' => ['nullable', 'numeric', 'min:0']],
                    'platform.pricing.hotspot_commission_rate' => ['label' => __('HotSpot commission (%)'), 'type' => 'number', 'rules' => ['nullable', 'numeric', 'between:0,100'], 'help' => __('Default for new ISPs, held between the published 3–5%.')],
                    'platform.pricing.pppoe_monthly_fee' => ['label' => __('Per PPPoE line a month (KES)'), 'type' => 'number', 'rules' => ['nullable', 'numeric', 'min:0']],
                    'platform.terms.due_after_days' => ['label' => __('Invoice due after (days)'), 'type' => 'number', 'rules' => ['nullable', 'integer', 'between:1,60']],
                    'platform.terms.grace_days' => ['label' => __('Grace before lock-out (days)'), 'type' => 'number', 'rules' => ['nullable', 'integer', 'between:0,60']],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, type: string, secret?: bool, rules: list<string>, help?: string, options?: array<string, string>}>
     */
    public static function fields(): array
    {
        return array_merge(...array_values(array_map(fn (array $group): array => $group['fields'], self::groups())));
    }

    /**
     * Lay saved settings over the configuration. Quiet on a database that is not there yet —
     * a fresh install, a migration in progress — because .env alone must still boot.
     */
    public static function apply(): void
    {
        try {
            $rows = Cache::rememberForever(self::CACHE_KEY, function (): array {
                if (! Schema::hasTable('platform_settings')) {
                    return [];
                }

                // The raw, still-encrypted values: secrets are never written to the cache in
                // the clear.
                return PlatformSetting::query()->get()->mapWithKeys(
                    fn (PlatformSetting $setting): array => [$setting->key => $setting->getRawOriginal('value')],
                )->all();
            });
        } catch (Throwable) {
            return;
        }

        $known = self::fields();

        foreach ($rows as $key => $encrypted) {
            if (! isset($known[$key]) || $encrypted === null || $encrypted === '') {
                continue;
            }

            try {
                $value = Crypt::decryptString($encrypted);
            } catch (Throwable) {
                continue;
            }

            if ($value === '') {
                continue;
            }

            config([$key => $known[$key]['type'] === 'number' && is_numeric($value)
                ? (str_contains($value, '.') ? (float) $value : (int) $value)
                : $value]);
        }
    }

    /**
     * The value saved in the dashboard, or null when .env is in charge.
     */
    public static function saved(string $key): ?string
    {
        return PlatformSetting::query()->where('key', $key)->first()?->value;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
