<?php

use App\Models\PlatformSetting;
use App\Support\Audit\AuditLogger;
use App\Support\PhoneNumber;
use App\Support\Platform\PlatformSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Itsmurumba\Hostpinnacle\Hostpinnacle;
use Itsmurumba\Hostpinnacle\HostpinnacleCredentials;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The platform's own settings, edited here instead of in .env.
 *
 * Each field shows whether its value comes from this page or from the server's .env. Saving
 * an empty field hands it back to .env. Secrets are write-only: the page says one is saved
 * and never sends it to the browser.
 */
new #[Title('System settings')] class extends Component {
    #[Url(as: 'tab', except: 'general')]
    public string $tab = 'general';

    /** Field values keyed with dots turned into "__" (Livewire paths use dots). */
    public array $values = [];

    /** Secrets the admin asked to remove, keyed the same way. */
    public array $clear = [];

    public string $testPhone = '';

    public string $testEmail = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);

        $saved = PlatformSetting::query()->get()->mapWithKeys(fn (PlatformSetting $s): array => [$s->key => $s->value]);

        foreach (PlatformSettings::fields() as $key => $field) {
            // Secrets never reach the page; everything else shows what is saved here.
            $this->values[$this->wire($key)] = ($field['secret'] ?? false) ? '' : (string) ($saved[$key] ?? '');
        }

        $this->testEmail = (string) Auth::user()?->email;

        if (! array_key_exists($this->tab, PlatformSettings::groups())) {
            $this->tab = 'general';
        }
    }

    public function wire(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /**
     * Where a field's value comes from right now: 'saved' here, 'env', or 'unset'.
     */
    public function source(string $key): string
    {
        if (PlatformSetting::query()->where('key', $key)->whereNotNull('value')->exists()) {
            return 'saved';
        }

        return filled(config($key)) ? 'env' : 'unset';
    }

    public function save(string $group, AuditLogger $audit): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);

        $fields = PlatformSettings::groups()[$group]['fields'] ?? null;
        abort_if($fields === null, 404);

        $rules = [];
        foreach ($fields as $key => $field) {
            $rules['values.'.$this->wire($key)] = $field['rules'];
        }

        $this->validate($rules, [], collect($fields)->mapWithKeys(fn ($f, $k) => ['values.'.$this->wire($k) => mb_strtolower($f['label'])])->all());

        $changed = [];

        foreach ($fields as $key => $field) {
            $value = trim((string) ($this->values[$this->wire($key)] ?? ''));
            $secret = $field['secret'] ?? false;

            if ($secret && $value === '' && empty($this->clear[$this->wire($key)])) {
                // Left blank means "keep what is saved".
                continue;
            }

            $row = PlatformSetting::query()->firstOrNew(['key' => $key]);
            $old = $row->exists ? (string) $row->value : '';

            if ($value === '') {
                if ($row->exists) {
                    $row->delete();
                    $changed[] = $key;
                }

                continue;
            }

            if ($old !== $value) {
                $row->value = $value;
                $row->save();
                $changed[] = $key;
            }
        }

        // Mail switches itself on the moment an SMTP host is saved, unless set otherwise.
        if ($group === 'mail' && filled($this->values[$this->wire('mail.mailers.smtp.host')] ?? null)
            && blank($this->values[$this->wire('mail.default')] ?? null)) {
            PlatformSetting::query()->updateOrCreate(['key' => 'mail.default'], ['value' => 'smtp']);
            $this->values[$this->wire('mail.default')] = 'smtp';
            $changed[] = 'mail.default';
        }

        foreach ($fields as $key => $field) {
            if ($field['secret'] ?? false) {
                $this->values[$this->wire($key)] = '';
            }
        }
        $this->clear = [];

        if ($changed === []) {
            Flux::toast(text: __('Nothing changed.'));

            return;
        }

        PlatformSettings::forget();
        PlatformSettings::apply();

        // Queue workers keep the configuration they started with; this tells them to restart
        // after their current job so texts and emails use the new settings.
        Artisan::call('queue:restart');

        // Names of what changed, never values.
        $audit->record(event: 'platform.settings_updated', description: 'Updated '.implode(', ', $changed));

        Flux::toast(variant: 'success', text: __('Saved. New settings are in use now.'));
    }

    public function sendTestSms(): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);

        $this->validate(['testPhone' => ['required', 'regex:/^(?:\+?254|0)?[17]\d{8}$/']], ['testPhone.regex' => __('Enter a mobile number like 07XX XXX XXX.')]);

        $config = config('services.hostpinnacle');

        if (blank($config['api_key'] ?? null) || blank($config['sender_id'] ?? null)) {
            Flux::toast(variant: 'warning', text: __('Save an API key and sender ID first.'));

            return;
        }

        try {
            $response = (new Hostpinnacle(new HostpinnacleCredentials(
                (string) $config['api_key'], (string) $config['sender_id'], (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''), (string) ($config['base_url'] ?? App\Services\Sms\SmsSender::BASE_URL),
            )))->sendQuickSMS(['mobile' => PhoneNumber::normalise($this->testPhone), 'msg' => __(':platform test message: platform SMS is working.', ['platform' => config('app.name')])]);

            $json = $response->json();
            $refused = is_array($json) && strtolower((string) ($json['status'] ?? '')) === 'error';

            $response->successful() && ! $refused
                ? Flux::toast(variant: 'success', text: __('HostPinnacle accepted the message.'))
                : Flux::toast(variant: 'danger', text: __('Refused: :reason', ['reason' => (string) ($json['reason'] ?? $json['message'] ?? 'HTTP '.$response->status())]));
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', text: __('Could not reach HostPinnacle: :reason', ['reason' => mb_strimwidth($e->getMessage(), 0, 120, '…')]));
        }
    }

    public function sendTestEmail(): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);

        $this->validate(['testEmail' => ['required', 'email']]);

        try {
            Mail::raw(__('This is a test from :platform. Email is working.', ['platform' => config('app.name')]), function ($message): void {
                $message->to($this->testEmail)->subject(__(':platform test email', ['platform' => config('app.name')]));
            });

            config('mail.default') === 'log'
                ? Flux::toast(variant: 'warning', text: __('Sending is set to "log only", so it was written to the log, not sent.'))
                : Flux::toast(variant: 'success', text: __('The mail server accepted it. Check :email.', ['email' => $this->testEmail]));
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', text: __('Not sent: :reason', ['reason' => mb_strimwidth($e->getMessage(), 0, 160, '…')]));
        }
    }
}; ?>

@php($groups = App\Support\Platform\PlatformSettings::groups())

<section class="w-full">
    <div class="flex flex-col gap-6 p-6">
        <div>
            <flux:heading size="xl">{{ __('System settings') }}</flux:heading>
            <flux:subheading>{{ __('Saved here, these take over from the server\'s .env. Clear a field to hand it back to .env.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800">
            @foreach ($groups as $id => $group)
                <button type="button" wire:click="$set('tab', '{{ $id }}')" @class([
                    'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                    'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' => $tab === $id,
                    'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $tab !== $id,
                ])>{{ $group['title'] }}</button>
            @endforeach
            <a href="{{ route('platform.payments') }}" wire:navigate class="rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">{{ __('Payments') }} &rarr;</a>
        </div>

        @php($group = $groups[$tab])
        <flux:card class="max-w-3xl">
            <form wire:submit="save('{{ $tab }}')" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ $group['title'] }}</flux:heading>
                    <flux:text size="sm" class="opacity-70">{{ $group['description'] }}</flux:text>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($group['fields'] as $key => $field)
                        @php($name = $this->wire($key))
                        @php($source = $this->source($key))
                        @php($secret = $field['secret'] ?? false)
                        <div wire:key="field-{{ $name }}">
                            <div class="mb-1.5 flex items-center justify-between gap-2">
                                <label for="{{ $name }}" class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $field['label'] }}</label>
                                <flux:badge size="sm" :color="$source === 'saved' ? 'blue' : ($source === 'env' ? 'zinc' : 'amber')">
                                    {{ $source === 'saved' ? __('Saved here') : ($source === 'env' ? __('From .env') : __('Not set')) }}
                                </flux:badge>
                            </div>

                            @if ($field['type'] === 'select')
                                <flux:select id="{{ $name }}" wire:model="values.{{ $name }}">
                                    <flux:select.option value="">{{ __('Use .env') }}</flux:select.option>
                                    @foreach ($field['options'] as $value => $label)
                                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @else
                                <flux:input id="{{ $name }}" wire:model="values.{{ $name }}"
                                    :type="$field['type'] === 'password' ? 'password' : $field['type']"
                                    autocomplete="off"
                                    :placeholder="$secret && $source === 'saved' ? __('Saved — leave blank to keep') : ($source === 'env' && ! $secret ? (string) config($key) : '')" />
                            @endif

                            @if ($secret && $source === 'saved')
                                <label class="mt-1.5 flex items-center gap-2 text-xs text-zinc-500">
                                    <input type="checkbox" wire:model="clear.{{ $name }}" class="rounded border-zinc-300"> {{ __('Remove the saved value') }}
                                </label>
                            @endif
                            @error('values.'.$name) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            @isset($field['help']) <p class="mt-1 text-xs text-zinc-500">{{ $field['help'] }}</p> @endisset
                        </div>
                    @endforeach
                </div>

                <div>
                    <flux:button variant="primary" type="submit">{{ __('Save :group', ['group' => mb_strtolower($group['title'])]) }}</flux:button>
                </div>
            </form>
        </flux:card>

        @if ($tab === 'sms')
            <flux:card class="max-w-3xl">
                <form wire:submit="sendTestSms" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <flux:input wire:model="testPhone" type="tel" :label="__('Send a test text through the platform account to')" placeholder="07XX XXX XXX" class="sm:max-w-xs" />
                    <flux:button type="submit" icon="paper-airplane">{{ __('Send test') }}</flux:button>
                </form>
            </flux:card>
        @elseif ($tab === 'mail')
            <flux:card class="max-w-3xl">
                <form wire:submit="sendTestEmail" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <flux:input wire:model="testEmail" type="email" :label="__('Send a test email to')" class="sm:max-w-xs" />
                    <flux:button type="submit" icon="paper-airplane">{{ __('Send test') }}</flux:button>
                </form>
            </flux:card>
        @endif
    </div>
</section>
