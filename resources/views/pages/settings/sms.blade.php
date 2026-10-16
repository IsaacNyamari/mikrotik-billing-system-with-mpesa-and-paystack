<?php

use App\Models\SmsMessage;
use App\Models\SmsSetting;
use App\Services\Sms\SmsSender;
use App\Support\Audit\AuditLogger;
use App\Support\Authorization\Permissions;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('SMS')] class extends Component {
    public bool $is_enabled = true;
    public string $username = '';
    public string $sender_id = '';
    public int $remind_days_before = 3;
    public string $alert_phones = '';
    public bool $alert_outages = true;

    // Write-only: left blank means "keep what is stored", and stored values never reach the page.
    public string $password = '';
    public string $api_key = '';

    public string $testPhone = '';

    public function mount(): void
    {
        Gate::authorize(Permissions::SETTINGS_UPDATE);

        $settings = SmsSetting::current();

        $this->fill([
            'is_enabled' => $settings->is_enabled,
            'username' => (string) $settings->username,
            'sender_id' => (string) $settings->sender_id,
            'remind_days_before' => $settings->remind_days_before,
            'alert_phones' => (string) $settings->alert_phones,
            'alert_outages' => $settings->alert_outages,
        ]);
    }

    #[Computed]
    public function settings(): SmsSetting
    {
        return SmsSetting::current();
    }

    /**
     * Whether texts go out at all right now, through the ISP's account or the platform's.
     */
    #[Computed]
    public function canSend(): bool
    {
        return app(SmsSender::class)->canSend();
    }

    /**
     * @return Collection<int, SmsMessage>
     */
    #[Computed]
    public function recent(): Collection
    {
        return SmsMessage::query()->latest('id')->limit(20)->get();
    }

    public function save(AuditLogger $audit): void
    {
        Gate::authorize(Permissions::SETTINGS_UPDATE);

        $settings = $this->settings;
        $isNew = ! $settings->exists || blank($settings->api_key);

        $validated = $this->validate([
            'is_enabled' => ['boolean'],
            'username' => ['nullable', 'string', 'max:120', 'required_with:api_key'],
            'password' => ['nullable', 'string', 'max:255'],
            'api_key' => [$isNew && $this->username !== '' ? 'required' : 'nullable', 'string', 'max:255'],
            // Safaricom and Airtel take an alphanumeric sender ID of up to eleven characters.
            'sender_id' => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z0-9 ._-]+$/', 'required_with:username'],
            'remind_days_before' => ['required', 'integer', 'min:1', 'max:14'],
        ], [], [
            'api_key' => __('API key'),
            'sender_id' => __('sender ID'),
        ]);

        $settings->fill([
            'is_enabled' => $validated['is_enabled'],
            'username' => $validated['username'] ?: null,
            'sender_id' => $validated['sender_id'] ?: null,
            'remind_days_before' => $validated['remind_days_before'],
        ]);

        if ($validated['password'] !== '' && $validated['password'] !== null) {
            $settings->password = $validated['password'];
        }

        if ($validated['api_key'] !== '' && $validated['api_key'] !== null) {
            $settings->api_key = $validated['api_key'];
        }

        $settings->save();

        $this->reset(['password', 'api_key']);
        unset($this->settings, $this->canSend);

        $audit->record(event: 'sms.settings_updated', auditable: $settings, description: __('Saved SMS settings'));

        Flux::toast(variant: 'success', text: __('SMS settings saved.'));
    }

    /**
     * Who at the ISP is texted when a router goes down and comes back.
     */
    public function saveAlerts(AuditLogger $audit): void
    {
        Gate::authorize(Permissions::SETTINGS_UPDATE);

        $phones = collect(explode(',', $this->alert_phones))->map(fn (string $p): string => trim($p))->filter()->values();

        $this->validate([
            'alert_outages' => ['boolean'],
            'alert_phones' => ['nullable', 'string', 'max:120'],
        ]);

        foreach ($phones as $phone) {
            if (preg_match('/^(?:\+?254|0)?[17]\d{8}$/', str_replace(' ', '', $phone)) !== 1) {
                $this->addError('alert_phones', __(':phone is not a mobile number like 07XX XXX XXX.', ['phone' => $phone]));

                return;
            }
        }

        if ($phones->count() > 5) {
            $this->addError('alert_phones', __('Up to five numbers.'));

            return;
        }

        $settings = $this->settings;
        $settings->fill([
            'alert_outages' => $this->alert_outages,
            'alert_phones' => $phones->isEmpty() ? null : $phones->implode(', '),
        ]);
        $settings->save();

        unset($this->settings);

        $audit->record(event: 'sms.alerts_updated', auditable: $settings, description: __('Saved router alert numbers'));

        Flux::toast(variant: 'success', text: __('Router alerts saved.'));
    }

    public function sendTest(SmsSender $sms): void
    {
        Gate::authorize(Permissions::SETTINGS_UPDATE);

        $this->validate([
            'testPhone' => ['required', 'string', 'regex:/^(?:\+?254|0)?[17]\d{8}$/'],
        ], [
            'testPhone.regex' => __('Enter a mobile number like 07XX XXX XXX.'),
        ]);

        $message = $sms->send($this->testPhone, __(':isp: this is a test message. SMS reminders are working.', [
            'isp' => App\Support\Tenancy\Facades\Tenancy::tenant()?->name ?? config('app.name'),
        ]), 'test');

        unset($this->recent);

        match ($message->status) {
            SmsMessage::SENT => Flux::toast(variant: 'success', text: __('Test message sent.')),
            SmsMessage::SKIPPED => Flux::toast(variant: 'warning', text: __('Nothing to send with yet. Save your HostPinnacle details first.')),
            default => Flux::toast(variant: 'danger', text: __('Not sent: :reason', ['reason' => $message->error])),
        };
    }
}; ?>

<section class="w-full">
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6">
        <div>
            <flux:heading size="xl">{{ __('SMS') }}</flux:heading>
            <flux:subheading>{{ __('Texts to PPPoE subscribers: a reminder before and on their due date, a notice when their line is cut, and a receipt when they pay.') }}</flux:subheading>
        </div>

        @if (! $this->canSend)
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('No texts are going out yet') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Enter your HostPinnacle account below. Lines are still cut and reconnected on time without it; subscribers just are not told.') }}</flux:callout.text>
            </flux:callout>
        @endif

        <flux:card>
            <form wire:submit="save" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('HostPinnacle account') }}</flux:heading>
                    <flux:text size="sm" class="opacity-70">{{ __('From your HostPinnacle portal. Messages go out under your own sender ID.') }}</flux:text>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="username" :label="__('Username')" autocomplete="off" />
                    <flux:input wire:model="password" type="password" :label="__('Password')" autocomplete="new-password"
                        :placeholder="filled($this->settings->password) ? __('Saved — leave blank to keep') : ''" />
                    <flux:input wire:model="api_key" type="password" :label="__('API key')" autocomplete="off"
                        :placeholder="filled($this->settings->api_key) ? __('Saved — leave blank to keep') : ''" />
                    <flux:input wire:model="sender_id" :label="__('Sender ID')" maxlength="11" :description="__('As approved by HostPinnacle, e.g. MYISP.')" />
                </div>

                <flux:input type="number" min="1" max="14" wire:model="remind_days_before" :label="__('Remind this many days before the due date')"
                    :description="__('A second reminder always goes on the due date itself.')" />

                <flux:switch wire:model="is_enabled" :label="__('Send texts to subscribers')" />

                <div>
                    <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:card>

        <flux:card>
            <form wire:submit="saveAlerts" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Router alerts') }}</flux:heading>
                    <flux:text size="sm" class="opacity-70">{{ __('A text the minute a router stops responding (power or internet lost at the site) or stops accepting management, and another when it is back, with the cause and how long it was out. Each text is retried every minute until your SMS provider accepts it, through the platform\'s account if yours refuses, and a copy goes to your contact email. Sent at any hour, even with subscriber texts switched off.') }}</flux:text>
                </div>

                <flux:input wire:model="alert_phones" :label="__('Numbers to text')" placeholder="0712 345 678, 0733 000 111"
                    :description="__('Up to five, separated by commas. Left empty, your contact phone (:phone) is used.', ['phone' => App\Support\Tenancy\Facades\Tenancy::tenant()?->contact_phone ?: __('none set')])" />

                <flux:switch wire:model="alert_outages" :label="__('Text me when a router goes down')" />

                <div>
                    <flux:button variant="primary" type="submit">{{ __('Save alerts') }}</flux:button>
                </div>
            </form>
        </flux:card>

        <flux:card>
            <form wire:submit="sendTest" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <flux:input wire:model="testPhone" type="tel" :label="__('Send a test message to')" placeholder="07XX XXX XXX" class="sm:max-w-xs" />
                <flux:button type="submit" icon="paper-airplane">{{ __('Send test') }}</flux:button>
            </form>
        </flux:card>

        <div>
            <flux:heading size="lg">{{ __('Recent messages') }}</flux:heading>
            <flux:table class="mt-3">
                <flux:table.columns>
                    <flux:table.column>{{ __('When') }}</flux:table.column>
                    <flux:table.column>{{ __('To') }}</flux:table.column>
                    <flux:table.column>{{ __('Message') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->recent as $message)
                        <flux:table.row :key="$message->id">
                            <flux:table.cell class="whitespace-nowrap">{{ $message->created_at?->format('j M, H:i') }}</flux:table.cell>
                            <flux:table.cell class="font-mono text-sm">{{ $message->phone }}</flux:table.cell>
                            <flux:table.cell class="max-w-xs"><span class="line-clamp-2 text-sm" title="{{ $message->body }}">{{ $message->body }}</span></flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$message->statusColor()" inset="top bottom" :title="$message->error">{{ ucfirst($message->status) }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-center"><flux:text>{{ __('Nothing sent yet.') }}</flux:text></flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>
</section>
