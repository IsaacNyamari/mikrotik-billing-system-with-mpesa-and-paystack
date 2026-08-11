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
    public string $accent_color = '';
    public string $color_scheme = 'auto';
    public string $layout = PortalSetting::LAYOUT_GRID;
    public string $card_style = 'tinted';
    public bool $show_banner = true;
    public string $banner_text = '';
    public string $buy_button_text = '';
    public string $currency_label = '';
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
            // Blank rather than the derived shade: an empty field is what "follows the brand
            // colour" looks like, and pre-filling it would freeze today's derived value.
            'accent_color' => (string) $setting->accent_color,
            'color_scheme' => $setting->color_scheme,
            'layout' => $setting->layout,
            'card_style' => $setting->card_style,
            'show_banner' => $setting->show_banner,
            'banner_text' => (string) $setting->banner_text,
            'buy_button_text' => (string) $setting->buy_button_text,
            'currency_label' => (string) $setting->currency_label,
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
            'accent_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_scheme' => ['required', Rule::in(['auto', 'light', 'dark'])],
            'layout' => ['required', Rule::in([PortalSetting::LAYOUT_GRID, PortalSetting::LAYOUT_LIST])],
            'card_style' => ['required', Rule::in(['tinted', 'plain'])],
            'show_banner' => ['boolean'],
            'banner_text' => ['nullable', 'string', 'max:80'],
            'buy_button_text' => ['nullable', 'string', 'max:40'],
            'currency_label' => ['nullable', 'string', 'max:8'],
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
            'accent_color' => $this->blankToNull($validated['accent_color'] ?? null) === null
                ? null
                : strtolower((string) $validated['accent_color']),
            'color_scheme' => $validated['color_scheme'],
            'layout' => $validated['layout'],
            'card_style' => $validated['card_style'],
            'show_banner' => $validated['show_banner'],
            'banner_text' => $this->blankToNull($validated['banner_text'] ?? null),
            'buy_button_text' => $this->blankToNull($validated['buy_button_text'] ?? null),
            'currency_label' => $this->blankToNull($validated['currency_label'] ?? null),
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
            'accent_color' => '',
            'color_scheme' => 'auto',
            'layout' => PortalSetting::LAYOUT_GRID,
            'card_style' => 'tinted',
            'show_banner' => true,
            'banner_text' => '',
            'buy_button_text' => '',
            'currency_label' => '',
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

    /**
     * The accent as the portal would work it out — the picked colour, or one derived from the
     * brand colour when the operator has left it blank.
     */
    #[Computed]
    public function previewAccent(): string
    {
        return $this->previewSetting()->accent();
    }

    #[Computed]
    public function previewOnAccent(): string
    {
        return $this->previewSetting()->onAccent();
    }

    protected function previewSetting(): PortalSetting
    {
        return new PortalSetting([
            'primary_color' => $this->previewColor,
            'accent_color' => preg_match('/^#[0-9a-fA-F]{6}$/', $this->accent_color) === 1 ? $this->accent_color : null,
        ]);
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

                    <div>
                        <flux:label>{{ __('Accent colour') }}</flux:label>
                        <flux:text size="sm" class="opacity-70">
                            {{ __('Used on the banner and the badge over each package. Leave blank to follow your brand colour.') }}
                        </flux:text>
                        <div class="mt-1.5 flex items-center gap-2">
                            <input
                                type="color"
                                wire:model.live="accent_color"
                                class="size-10 shrink-0 cursor-pointer rounded border border-zinc-300 bg-transparent dark:border-zinc-600"
                                aria-label="{{ __('Accent colour') }}"
                            >
                            <flux:input wire:model.live.debounce.400ms="accent_color" class="font-mono" placeholder="{{ __('Automatic') }}" />
                            @if ($accent_color !== '')
                                <flux:button size="sm" variant="ghost" type="button" wire:click="$set('accent_color', '')">
                                    {{ __('Clear') }}
                                </flux:button>
                            @endif
                        </div>
                        @error('accent_color')
                            <flux:text size="sm" class="mt-1 text-red-600">{{ $message }}</flux:text>
                        @enderror
                    </div>
                </flux:card>

                <flux:card class="flex flex-col gap-5">
                    <div>
                        <flux:heading size="lg">{{ __('Layout') }}</flux:heading>
                        <flux:subheading>
                            {{ __('How the packages are arranged, and what the buttons on them say.') }}
                        </flux:subheading>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:select wire:model.live="layout" :label="__('Packages')">
                            <flux:select.option value="grid">{{ __('Tiles, two to a row') }}</flux:select.option>
                            <flux:select.option value="list">{{ __('One per row') }}</flux:select.option>
                        </flux:select>

                        <flux:select wire:model.live="card_style" :label="__('Tile style')">
                            <flux:select.option value="tinted">{{ __('Coloured') }}</flux:select.option>
                            <flux:select.option value="plain">{{ __('White with a coloured button') }}</flux:select.option>
                        </flux:select>
                    </div>

                    <flux:input
                        wire:model.live.debounce.400ms="buy_button_text"
                        :label="__('Button on each package')"
                        :placeholder="__('Click Here To Connect')"
                        :description="__('An instruction reads better here than a price — it is the one thing a customer has to understand unaided.')"
                    />

                    <flux:input
                        wire:model.live.debounce.400ms="currency_label"
                        :label="__('What to call the money')"
                        :placeholder="$this->setting->displayCurrency()"
                        :description="__('“Ksh” reads as local where a currency code reads as a bank statement.')"
                    />

                    <flux:switch
                        wire:model.live="show_banner"
                        :label="__('Show the banner')"
                        :description="__('The coloured strip above the packages.')"
                    />

                    @if ($show_banner)
                        <flux:input
                            wire:model.live.debounce.400ms="banner_text"
                            :label="__('Banner wording')"
                            :placeholder="$this->setting->displayBannerText()"
                        />
                    @endif
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

                    @if ($show_banner)
                        <div
                            class="mt-3 flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold"
                            style="background: linear-gradient(100deg, {{ $this->previewAccent }}, {{ $this->previewColor }}); color: {{ $this->previewOnAccent }}"
                        >
                            {{ $banner_text !== '' ? $banner_text : $this->setting->displayBannerText() }}
                        </div>
                    @endif

                    {{-- Two packages, laid out exactly as the portal lays them out, so an
                         operator is choosing between things they can see rather than words. --}}
                    <div @class(['mt-4 grid gap-2', 'grid-cols-2' => $layout === 'grid'])>
                        @foreach ([['1 hour', 20], ['1 week', 130]] as [$validity, $price])
                            <div
                                @class([
                                    'relative rounded-xl border px-2 pb-2 pt-4 text-center',
                                    'bg-zinc-900' => $color_scheme === 'dark' && $card_style === 'plain',
                                    'bg-white' => $color_scheme !== 'dark' && $card_style === 'plain',
                                ])
                                @style([
                                    'border-color: '.$this->previewColor.'44',
                                    'background: linear-gradient(180deg, '.$this->previewColor.'2b, '.$this->previewColor.'0a)' => $card_style === 'tinted',
                                ])
                            >
                                <span
                                    class="absolute -top-2 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide"
                                    style="background: {{ $this->previewAccent }}; color: {{ $this->previewOnAccent }}"
                                >{{ $validity }}</span>

                                <p @class(['text-xl font-extrabold', 'text-white' => $color_scheme === 'dark', 'text-zinc-900' => $color_scheme !== 'dark'])>
                                    <span class="text-[10px] font-semibold opacity-70">
                                        {{ $currency_label !== '' ? $currency_label : $this->setting->displayCurrency() }}
                                    </span>{{ $price }}
                                </p>

                                <p class="text-[11px] text-zinc-500">
                                    {{ $validity }}@if ($show_speed) &middot; 5 Mbps @endif
                                    @if ($show_data_cap) &middot; 500 MB @endif
                                </p>

                                <div
                                    class="mt-2 truncate rounded-full px-2 py-1 text-[10px] font-bold"
                                    @style([
                                        'background: #fff; color: '.$this->previewColor => $card_style === 'tinted',
                                        'background: '.$this->previewColor.'; color: '.$this->previewOnColor => $card_style !== 'tinted',
                                    ])
                                >
                                    {{ $buy_button_text !== '' ? $buy_button_text : $this->setting->displayBuyLabel() }}
                                </div>
                            </div>
                        @endforeach
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
