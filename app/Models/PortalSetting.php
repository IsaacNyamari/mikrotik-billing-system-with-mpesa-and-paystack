<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * How one ISP's captive portal looks and reads.
 *
 * The portal has to render for an ISP that has configured nothing at all, so every accessor
 * here falls back to a working default. Nothing on the public page may depend on a row
 * existing.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $brand_name
 * @property string|null $logo_path
 * @property string|null $headline
 * @property string|null $intro_text
 * @property string|null $success_text
 * @property string|null $footer_text
 * @property string|null $support_phone
 * @property string|null $terms_url
 * @property string|null $primary_color
 * @property string|null $accent_color
 * @property string $color_scheme
 * @property string $layout
 * @property string $card_style
 * @property bool $show_banner
 * @property string|null $banner_text
 * @property string|null $buy_button_text
 * @property string|null $currency_label
 * @property bool $show_speed
 * @property bool $show_data_cap
 * @property string|null $custom_css
 */
#[Fillable([
    'brand_name',
    'logo_path',
    'headline',
    'intro_text',
    'success_text',
    'footer_text',
    'support_phone',
    'terms_url',
    'primary_color',
    'accent_color',
    'color_scheme',
    'layout',
    'card_style',
    'show_banner',
    'banner_text',
    'buy_button_text',
    'currency_label',
    'show_speed',
    'show_data_cap',
    'custom_css',
])]
class PortalSetting extends Model
{
    use BelongsToTenant;

    public const DEFAULT_PRIMARY = '#2563eb';

    /** Packages as tiles, two to a row on a phone. */
    public const LAYOUT_GRID = 'grid';

    /** Packages as stacked rows, for an ISP selling only a few. */
    public const LAYOUT_LIST = 'list';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'color_scheme' => 'auto',
        'layout' => self::LAYOUT_GRID,
        'card_style' => 'tinted',
        'show_banner' => true,
        'show_speed' => true,
        'show_data_cap' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_banner' => 'boolean',
            'show_speed' => 'boolean',
            'show_data_cap' => 'boolean',
        ];
    }

    /**
     * This ISP's settings, or an unsaved instance carrying the defaults.
     *
     * Returning a model either way means callers never branch on whether the ISP has got
     * round to customising anything.
     */
    public static function current(): self
    {
        return static::query()->first() ?? new self(['tenant_id' => Tenancy::id()]);
    }

    /**
     * Falls back to the bound tenant rather than the relation: the portal always has one
     * resolved, and reaching through the relation would cost a query to learn what the
     * request already knows.
     */
    public function displayName(?Tenant $tenant = null): string
    {
        if ($this->brand_name !== null && $this->brand_name !== '') {
            return $this->brand_name;
        }

        $tenant ??= Tenancy::tenant();

        return $tenant === null ? (string) config('app.name') : $tenant->name;
    }

    public function displayHeadline(): string
    {
        return $this->headline ?: __('Choose a package to get online');
    }

    public function displaySuccessText(): string
    {
        return $this->success_text ?: __('Your code is your username and password. Keep it to log back in.');
    }

    public function displaySupportPhone(?Tenant $tenant = null): ?string
    {
        return $this->support_phone ?: ($tenant ?? Tenancy::tenant())?->contact_phone;
    }

    /**
     * The strip across the top of the packages.
     *
     * Defaults to naming the network, which is what a customer who has just tapped a wifi name
     * is looking for confirmation of.
     */
    public function displayBannerText(?Tenant $tenant = null): string
    {
        return $this->banner_text ?: __(':name Login Page', ['name' => $this->displayName($tenant)]);
    }

    /**
     * What the button on each package says.
     *
     * Deliberately an instruction rather than a price: it is the one thing on the page a
     * customer has to understand without being told.
     */
    public function displayBuyLabel(): string
    {
        return $this->buy_button_text ?: __('Click Here To Connect');
    }

    /**
     * What to call the money.
     *
     * "Ksh" is what a Kenyan customer reads on every price they have ever seen; "KES" is what
     * a bank statement says. The ISP's currency code stands in when nothing is set.
     */
    public function displayCurrency(?Tenant $tenant = null): string
    {
        return $this->currency_label ?: (string) (($tenant ?? Tenancy::tenant())->currency ?? 'KES');
    }

    public function usesGrid(): bool
    {
        return $this->layout !== self::LAYOUT_LIST;
    }

    /**
     * Whether package tiles carry the brand colour or sit white with a brand button.
     */
    public function isTinted(): bool
    {
        return $this->card_style !== 'plain';
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path === null ? null : Storage::disk('public')->url($this->logo_path);
    }

    /**
     * The brand colour, guaranteed to be a usable #rrggbb.
     *
     * Validation happens on the way in, but this is written directly into a stylesheet, so it
     * refuses to hand back anything it has not checked itself.
     */
    public function primary(): string
    {
        $colour = (string) $this->primary_color;

        return preg_match('/^#[0-9a-fA-F]{6}$/', $colour) === 1 ? $colour : self::DEFAULT_PRIMARY;
    }

    /**
     * A darker step of the brand colour, for hover and active states.
     */
    public function primaryDark(): string
    {
        return $this->shade($this->primary(), -0.18);
    }

    /**
     * Black or white, whichever is legible on the brand colour.
     *
     * An ISP that picks a pale yellow would otherwise end up with white text on it, which no
     * customer can read.
     */
    public function onPrimary(): string
    {
        return $this->luminance($this->primary()) > 0.55 ? '#111827' : '#ffffff';
    }

    /**
     * The brand colour as "r, g, b", for translucent focus rings.
     */
    public function primaryChannels(): string
    {
        [$r, $g, $b] = $this->channels($this->primary());

        return "{$r}, {$g}, {$b}";
    }

    /**
     * The second colour, for badges and the banner.
     *
     * Falls back to a darker step of the brand colour rather than to a fixed one, so an ISP
     * that never picks an accent still gets a page that looks deliberate.
     */
    public function accent(): string
    {
        $colour = (string) $this->accent_color;

        return preg_match('/^#[0-9a-fA-F]{6}$/', $colour) === 1 ? $colour : $this->shade($this->primary(), -0.35);
    }

    public function accentChannels(): string
    {
        [$r, $g, $b] = $this->channels($this->accent());

        return "{$r}, {$g}, {$b}";
    }

    /**
     * Black or white, whichever is legible on the accent colour.
     */
    public function onAccent(): string
    {
        return $this->luminance($this->accent()) > 0.55 ? '#111827' : '#ffffff';
    }

    /**
     * Operator-supplied CSS, made safe to drop into a style element.
     *
     * The only real danger is closing the element early and opening a script, so angle
     * brackets are removed outright — CSS has no legitimate use for them.
     */
    public function safeCustomCss(): string
    {
        return str_replace(['<', '>'], '', (string) $this->custom_css);
    }

    /**
     * @return array{int, int, int}
     */
    protected function channels(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }

    /**
     * Relative luminance, 0 (black) to 1 (white).
     */
    protected function luminance(string $hex): float
    {
        [$r, $g, $b] = $this->channels($hex);

        // Rec. 709 weights: the eye is far more sensitive to green than to blue.
        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
    }

    protected function shade(string $hex, float $amount): string
    {
        $channels = array_map(
            fn (int $channel): int => (int) max(0, min(255, round($channel + ($channel * $amount)))),
            $this->channels($hex),
        );

        return sprintf('#%02x%02x%02x', ...$channels);
    }
}
