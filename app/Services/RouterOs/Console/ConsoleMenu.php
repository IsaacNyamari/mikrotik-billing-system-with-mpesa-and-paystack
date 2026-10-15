<?php

namespace App\Services\RouterOs\Console;

use App\Models\Router;

/**
 * One RouterOS menu, described well enough for the console to show and edit it.
 *
 * Winbox is, underneath, a generic browser over RouterOS menus: a table of rows, a form per
 * row, and enable/disable/remove buttons. The console works the same way, so giving an ISP one
 * more of Winbox's screens is a short class rather than another page — and every one of them
 * inherits the same authorization, audit trail and protection of what the platform depends on.
 */
abstract class ConsoleMenu
{
    /**
     * The URL segment, e.g. "interfaces".
     */
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    /**
     * The RouterOS menu path, e.g. "/ip/dhcp-server/lease".
     */
    abstract public function path(): string;

    /**
     * What the table shows, as heading => RouterOS property.
     *
     * @return array<string, string>
     */
    abstract public function columns(): array;

    /**
     * Heroicon name for the console's own menu.
     */
    public function icon(): string
    {
        return 'squares-2x2';
    }

    /**
     * The group it sits under in the console's menu, as Winbox groups them.
     */
    public function group(): string
    {
        return 'General';
    }

    /**
     * What an operator may write, as RouterOS property => field definition.
     *
     * Only these properties ever reach the device. A field the form did not offer cannot be
     * smuggled in through the wire, because the console filters to this list before sending.
     *
     * @return array<string, array{label: string, rules: array<int, mixed>, type?: string, options?: array<string, string>, placeholder?: string, description?: string, create_only?: bool}>
     */
    public function fields(): array
    {
        return [];
    }

    public function canAdd(): bool
    {
        return false;
    }

    public function canEdit(): bool
    {
        return $this->fields() !== [];
    }

    public function canRemove(): bool
    {
        return false;
    }

    /**
     * Whether rows can be enabled and disabled, which most RouterOS menus support.
     */
    public function canToggle(): bool
    {
        return false;
    }

    /**
     * Commands that act on one row and are not an edit, as key => definition.
     *
     * @return array<string, array{label: string, command: string, icon?: string, confirm?: string}>
     */
    public function rowCommands(): array
    {
        return [];
    }

    /**
     * Whether a row command applies to this particular row — "make static" means nothing to a
     * lease that already is.
     *
     * @param  array<string, string>  $row
     */
    public function commandApplies(string $command, array $row): bool
    {
        return true;
    }

    /**
     * Why this row must not be changed from here, or null when it may be.
     *
     * The console gives an ISP the whole of their router, and the platform's own foothold on
     * it is part of that router. Disabling the tunnel interface, the uplink or the API user
     * from a web page would cut the very connection the page is using — and the only way back
     * is a person standing next to the device.
     *
     * @param  array<string, string>  $row
     */
    public function protects(array $row, Router $router): ?string
    {
        $comment = (string) ($row['comment'] ?? '');

        foreach (['Managed by the platform', 'Management address', 'Platform management server', 'Platform. Do not remove.'] as $marker) {
            if (str_contains($comment, $marker)) {
                return __('The platform reaches this router through it.');
            }
        }

        if (str_starts_with($comment, 'platform-')) {
            return __('Written by the platform\'s setup script. Regenerate the script to change it.');
        }

        return null;
    }

    /**
     * Shape the rows for display — order, and how many. Most menus show what the device
     * returned, in its order.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, string>>
     */
    public function present(array $rows): array
    {
        return $rows;
    }

    /**
     * Validation rules for the form, keyed for Livewire's `form.` prefix.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(bool $creating): array
    {
        $rules = [];

        foreach ($this->editableFields($creating) as $property => $field) {
            $rules['form.'.self::formKey($property)] = $field['rules'];
        }

        return $rules;
    }

    /**
     * The key a RouterOS property is held under in a form.
     *
     * RouterOS names are hyphenated ("mac-address"), and a hyphen inside a wire:model path or
     * a validation key is one more thing to go subtly wrong. Underscores never are.
     */
    public static function formKey(string $property): string
    {
        return str_replace('-', '_', $property);
    }

    /**
     * Form values back under the RouterOS property names they stand for.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public function fromForm(array $form, bool $creating): array
    {
        $values = [];

        foreach (array_keys($this->editableFields($creating)) as $property) {
            $values[$property] = $form[self::formKey($property)] ?? null;
        }

        return $values;
    }

    /**
     * The fields offered when adding a row, or when editing one.
     *
     * Some properties can only be chosen at creation — RouterOS refuses to change them
     * afterwards, and offering a field that always fails is worse than not offering it.
     *
     * @return array<string, array{label: string, rules: array<int, mixed>, type?: string, options?: array<string, string>, placeholder?: string, description?: string, create_only?: bool}>
     */
    public function editableFields(bool $creating): array
    {
        return array_filter(
            $this->fields(),
            fn (array $field): bool => $creating || ! ($field['create_only'] ?? false),
        );
    }

    /**
     * The values to send for a form submission: only offered fields, and an empty optional
     * field left out of an add rather than written as an empty string.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public function payload(array $values, bool $creating): array
    {
        $payload = [];

        foreach (array_keys($this->editableFields($creating)) as $property) {
            $value = trim((string) ($values[$property] ?? ''));

            if ($value === '' && $creating) {
                continue;
            }

            $payload[$property] = $value;
        }

        return $payload;
    }
}
