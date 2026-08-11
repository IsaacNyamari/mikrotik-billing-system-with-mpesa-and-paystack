<?php

use App\Models\PortalSetting;
use App\Models\Router;
use App\Support\Audit\AuditLogger;
use App\Support\Authorization\Permissions;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Portal appearance')] class extends Component {
    use WithFileUploads;

    public string $brand_name = '';
    public string $headline = '';
    public string $intro_text = '';
    public string $success_text = '';
    public string $footer_text = '';
    public string $support_phone = '';
    public string $terms_url = '';
    public string $primary_color = PortalSetting::DEFAULT_PRIMARY;
    public string $color_scheme = 'auto';
    public bool $show_speed = true;
    public bool $show_data_cap = true;
    public string $custom_css = '';

    public $logo = null;

    public function mount(): void
    {
        Gate::authorize(Permissions::HOTSPOT_UPDATE_PORTAL);

        $setting = PortalSetting::current();

        $this->fill([
            'brand_name' => (string) $setting->brand_name,
            'headline' => (string) $setting->headline,
            'intro_text' => (string) $setting->intro_text,
            'success_text' => (string) $setting->success_text,
            'footer_text' => (string) $setting->footer_text,
            'support_phone' => (string) $setting->support_phone,
            'terms_url' => (string) $setting->terms_url,
            'primary_color' => $setting->primary(),
            'color_scheme' => $setting->color_scheme,
            'show_speed' => $setting->show_speed,
            'show_data_cap' => $setting->show_data_cap,
            'custom_css' => (string) $setting->custom_css,
        ]);
    }

    #[Computed(persist: false)]
    public function setting(): PortalSetting
    {
        return PortalSetting::current();
    }

    /**
     * Where this ISP's customers actually land, so an operator can open it and look.
     */
    #[Computed]
    public function portalUrl(): ?string
    {
        return Router::query()->whereNotNull('portal_key')->first()?->portalUrl();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'brand_name' => ['nullable', 'string', 'max:60'],
            'headline' => ['nullable', 'string', 'max:120'],
            'intro_text' => ['nullable', 'string', 'max:200'],
            'success_text' => ['nullable', 'string', 'max:200'],
            'footer_text' => ['nullable', 'string', 'max:200'],
            'support_phone' => ['nullable', 'string', 'max:32'],
            'terms_url' => ['nullable', 'url', 'max:255'],
            // Written straight into a stylesheet, so nothing but a hex colour is accepted.
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_scheme' => ['required', Rule::in(['auto', 'light', 'dark'])],
            'show_speed' => ['boolean'],
            'show_data_cap' => ['boolean'],
            'custom_css' => ['nullable', 'string', 'max:5000'],
            // Small, because it loads on a phone before the customer has any connectivity
            // to speak of.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:512'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'primary_color.regex' => __('Use a colour like #2563eb.'),
            'logo.max' => __('Keep the logo under 512 KB — it loads before the customer has internet.'),
        ];
    }

    public function save(AuditLogger $audit): void
    {
        Gate::authorize(Permissions::HOTSPOT_UPDATE_PORTAL);

        $validated = $this->validate();

        $setting = PortalSetting::current();

        $setting->fill([
            'brand_name' => $this->blankToNull($validated['brand_name'] ?? null),
            'headline' => $this->blankToNull($validated['headline'] ?? null),
            'intro_text' => $this->blankToNull($validated['intro_text'] ?? null),
            'success_text' => $this->blankToNull($validated['success_text'] ?? null),
            'footer_text' => $this->blankToNull($validated['footer_text'] ?? null),
            'support_phone' => $this->blankToNull($validated['support_phone'] ?? null),
            'terms_url' => $this->blankToNull($validated['terms_url'] ?? null),
            'primary_color' => strtolower($validated['primary_color']),
            'color_scheme' => $validated['color_scheme'],
            'show_speed' => $validated['show_speed'],
            'show_data_cap' => $validated['show_data_cap'],
            'custom_css' => $this->blankToNull($validated['custom_css'] ?? null),
        ]);

        if ($this->logo !== null) {
            // The old file is removed only once the new one is stored, so a failed upload
            // never leaves the portal with no logo at all.
            $previous = $setting->logo_path;

            $setting->logo_path = $this->logo->store('portal-logos', 'public');

            if ($previous !== null) {
                Storage::disk('public')->delete($previous);
            }
        }

        $setting->save();

        $this->reset('logo');
        unset($this->setting);

        $audit->record(
            event: 'portal.updated',
            auditable: $setting,
            description: __('Updated the captive portal appearance'),
        );

        Flux::toast(variant: 'success', text: __('Portal updated. Customers see this straight away.'));
    }

    public function removeLogo(): void
    {
        Gate::authorize(Permissions::HOTSPOT_UPDATE_PORTAL);

        $setting = PortalSetting::current();

        if ($setting->logo_path !== null) {
            Storage::disk('public')->delete($setting->logo_path);
            $setting->logo_path = null;
            $setting->save();
        }

        unset($this->setting);

        Flux::toast(text: __('Logo removed.'));
    }

    /**
     * Put everything back to how a brand-new ISP starts.
     */
    public function resetToDefaults(): void
    {
        Gate::authorize(Permissions::HOTSPOT_UPDATE_PORTAL);

        $this->fill([
            'brand_name' => '',
            'headline' => '',
            'intro_text' => '',
            'success_text' => '',
            'footer_text' => '',
            'terms_url' => '',
            'primary_color' => PortalSetting::DEFAULT_PRIMARY,
            'color_scheme' => 'auto',
            'show_speed' => true,
            'show_data_cap' => true,
            'custom_css' => '',
        ]);

        Flux::toast(text: __('Defaults restored — save to apply them.'));
    }

    /**
     * A preview needs a colour even while the operator is midway through typing one.
     */
    #[Computed]
    public function previewColor(): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $this->primary_color) === 1
            ? $this->primary_color
            : PortalSetting::DEFAULT_PRIMARY;
    }

    /**
     * Legible text for whatever colour is currently in the picker, not the saved one — the
     * preview is worthless if it lags a keystroke behind.
     */
    #[Computed]
    public function previewOnColor(): string
    {
        return (new PortalSetting(['primary_color' => $this->previewColor]))->onPrimary();
    }

    protected function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}; ?>

<section class="w-full">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Portal appearance') }}</flux:heading>
                <flux:subheading>
                    {{ __('How your captive portal looks to a customer joining your wifi.') }}
                </flux:subheading>
            </div>

            @if ($this->portalUrl)
                <flux:button size="sm" icon="arrow-top-right-on-square" href="{{ $this->portalUrl }}" target="_blank">
                    {{ __('Open portal') }}
                </flux:button>
            @endif
        </div>

        <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
            <form wire:submit="save" class="flex flex-col gap-6">
                <flux:card class="flex flex-col gap-5">
                    <flux:heading size="lg">{{ __('Branding') }}</flux:heading>

                    <div class="flex items-center gap-4">
                        @if ($logo)
                            <img src="{{ $logo->temporaryUrl() }}" alt="" class="size-16 rounded-lg object-contain">
                        @elseif ($this->setting->logoUrl())
                            <img src="{{ $this->setting->logoUrl() }}" alt="" class="size-16 rounded-lg object-contain">
                        @else
                            <div class="flex size-16 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon.photo class="size-6 opacity-40" />
                            </div>
                        @endif

                        <div class="flex-1">
                            <flux:input
                                type="file"
                                wire:model="logo"
                                :label="__('Logo')"
                                accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                :description="__('PNG, JPG, WEBP or SVG, under 512 KB.')"
                            />
                            @if ($this->setting->logoUrl())
                                <flux:button size="xs" variant="ghost" class="mt-1" wire:click="removeLogo" type="button">
                                    {{ __('Remove logo') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>

                    <flux:input
                        wire:model.live.debounce.400ms="brand_name"
                        :label="__('Name shown to customers')"
                        :placeholder="$this->setting->displayName()"
                        :description="__('Leave blank to use your ISP name.')"
                    />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <flux:label>{{ __('Brand colour') }}</flux:label>
                            <div class="mt-1.5 flex items-center gap-2">
                                <input
                                    type="color"
                                    wire:model.live="primary_color"
                                    class="size-10 shrink-0 cursor-pointer rounded border border-zinc-300 bg-transparent dark:border-zinc-600"
                                    aria-label="{{ __('Brand colour') }}"
                                >
                                <flux:input wire:model.live.debounce.400ms="primary_color" class="font-mono" />
                            </div>
                            @error('primary_color')
                                <flux:text size="sm" class="mt-1 text-red-600">{{ $message }}</flux:text>
                            @enderror
                        </div>

                        <flux:select wire:model.live="color_scheme" :label="__('Colour scheme')">
                            <flux:select.option value="auto">{{ __('Follow the phone') }}</flux:select.option>
                            <flux:select.option value="light">{{ __('Always light') }}</flux:select.option>
                            <flux:select.option value="dark">{{ __('Always dark') }}</flux:select.option>
                        </flux:select>
                    </div>
                </flux:card>

                <flux:card class="flex flex-col gap-5">
                    <flux:heading size="lg">{{ __('Wording') }}</flux:heading>

                    <flux:input
                        wire:model.live.debounce.400ms="headline"
                        :label="__('Headline')"
                        :placeholder="__('Choose a package to get online')"
                    />
                    <flux:input
                        wire:model.live.debounce.400ms="intro_text"
                        :label="__('Extra line under the headline')"
                        :description="__('Optional. A welcome, or a note about your service.')"
                    />
                    <flux:input
                        wire:model="success_text"
                        :label="__('Message after paying')"
                        :placeholder="__('Your code is your username and password. Keep it to log back in.')"
                    />
                    <flux:input wire:model.live.debounce.400ms="footer_text" :label="__('Footer note')" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input
                            wire:model="support_phone"
                            :label="__('Support number')"
                            :placeholder="$this->setting->displaySupportPhone() ?? '07XX XXX XXX'"
                        />
                        <flux:input wire:model="terms_url" type="url" :label="__('Terms link')" placeholder="https://" />
                    </div>
                </flux:card>

                <flux:card class="flex flex-col gap-5">
                    <flux:heading size="lg">{{ __('Package details') }}</flux:heading>

                    <flux:switch
                        wire:model.live="show_speed"
                        :label="__('Show speeds')"
                        :description="__('Turn off if you would rather not advertise exact speeds.')"
                    />
                    <flux:switch wire:model.live="show_data_cap" :label="__('Show data allowance')" />
                </flux:card>

                <flux:card class="flex flex-col gap-4">
                    <div>
                        <flux:heading size="lg">{{ __('Custom CSS') }}</flux:heading>
                        <flux:subheading>
                            {{ __('For finer control. Added last, so it overrides everything above.') }}
                        </flux:subheading>
                    </div>

                    <flux:textarea
                        wire:model="custom_css"
                        rows="5"
                        class="font-mono text-xs"
                        placeholder=".portal__title { letter-spacing: 0; }"
                    />

                    <flux:text size="sm" class="opacity-70">
                        {{ __('Angle brackets are stripped, so this cannot inject anything into the page.') }}
                    </flux:text>
                </flux:card>

                <div class="flex items-center gap-3">
                    <flux:button variant="primary" type="submit" data-test="save-portal-button">
                        {{ __('Save appearance') }}
                    </flux:button>

                    <flux:button variant="ghost" type="button" wire:click="resetToDefaults">
                        {{ __('Reset to defaults') }}
                    </flux:button>
                </div>
            </form>

            {{-- A live preview, so an operator is not saving and reloading a phone to see what
                 a colour looks like. The same custom properties drive it as drive the portal. --}}
            <div class="lg:sticky lg:top-6 lg:self-start">
                <flux:heading size="lg" class="mb-3">{{ __('Preview') }}</flux:heading>

                <div
                    @class([
                        'overflow-hidden rounded-xl border p-4',
                        'border-zinc-800 bg-zinc-950' => $color_scheme === 'dark',
                        'border-zinc-200 bg-zinc-100' => $color_scheme !== 'dark',
                    ])
                    style="--brand: {{ $this->previewColor }}"
                >
                    <div class="text-center">
                        @if ($logo)
                            <img src="{{ $logo->temporaryUrl() }}" alt="" class="mx-auto mb-2 max-h-10">
                        @elseif ($this->setting->logoUrl())
                            <img src="{{ $this->setting->logoUrl() }}" alt="" class="mx-auto mb-2 max-h-10">
                        @endif

                        <p @class(['font-semibold', 'text-white' => $color_scheme === 'dark', 'text-zinc-900' => $color_scheme !== 'dark'])>
                            {{ $brand_name !== '' ? $brand_name : $this->setting->displayName() }}
                        </p>
                        <p class="text-xs text-zinc-500">
                            {{ $headline !== '' ? $headline : __('Choose a package to get online') }}
                        </p>
                        @if ($intro_text !== '')
                            <p class="text-xs text-zinc-500">{{ $intro_text }}</p>
                        @endif
                    </div>

                    <div
                        @class([
                            'mt-3 flex items-center gap-3 rounded-lg border-2 p-3',
                            'bg-zinc-900' => $color_scheme === 'dark',
                            'bg-white' => $color_scheme !== 'dark',
                        ])
                        style="border-color: {{ $this->previewColor }}"
                    >
                        <div class="size-4 shrink-0 rounded-full" style="background: {{ $this->previewColor }}"></div>
                        <div class="flex-1 text-xs">
                            <p @class(['font-semibold', 'text-white' => $color_scheme === 'dark', 'text-zinc-900' => $color_scheme !== 'dark'])>
                                {{ __('1 Hour') }}
                            </p>
                            <p class="text-zinc-500">
                                1 hour@if ($show_speed) &middot; 5 Mbps down@endif
                                @if ($show_data_cap) &middot; 500 MB @endif
                            </p>
                        </div>
                        <span @class(['text-sm font-bold', 'text-white' => $color_scheme === 'dark', 'text-zinc-900' => $color_scheme !== 'dark'])>20</span>
                    </div>

                    <div
                        class="mt-3 rounded-lg py-2.5 text-center text-sm font-semibold"
                        style="background: {{ $this->previewColor }}; color: {{ $this->previewOnColor }}"
                    >
                        {{ __('Pay with M-PESA') }}
                    </div>

                    @if ($footer_text !== '')
                        <p class="mt-3 text-center text-[11px] text-zinc-400">{{ $footer_text }}</p>
                    @endif
                </div>

                <flux:text size="sm" class="mt-3 opacity-70">
                    {{ __('Text colour on the button is chosen automatically so it stays readable on your brand colour.') }}
                </flux:text>
            </div>
        </div>
    </div>
</section>
