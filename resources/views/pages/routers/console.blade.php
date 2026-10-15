<?php

use App\Models\Router;
use App\Services\RouterOs\Console\ConsoleMenu;
use App\Services\RouterOs\Console\ConsoleMenus;
use App\Services\RouterOs\Console\ConsoleRefusal;
use App\Services\RouterOs\Console\RouterConsole;
use App\Services\RouterOs\Exceptions\RouterOsCommandException;
use App\Services\RouterOs\Exceptions\RouterOsConnectionException;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The router's configuration, from the dashboard — the screens an ISP would otherwise open
 * Winbox for.
 *
 * One page for every menu: what differs between Interfaces and DHCP leases is described by
 * a ConsoleMenu, and everything that must be the same for all of them — who may change what,
 * what the platform itself depends on, the audit trail — lives in RouterConsole.
 *
 * Reading needs `view` on the router; changing anything needs `command`, the same line the
 * router screen already draws between looking at a device and reconfiguring it.
 */
new #[Title('Router console')] class extends Component {
    public Router $router;

    public string $menuKey = '';

    public string $search = '';

    /**
     * The row being edited: its RouterOS id, "new" while adding, or null with the form shut.
     */
    public ?string $editing = null;

    /**
     * @var array<string, string>
     */
    public array $form = [];

    public function mount(Router $router, ?string $menu = null): void
    {
        Gate::authorize('view', $router);

        $menus = app(ConsoleMenus::class);
        $found = $menu === null ? $menus->first() : $menus->find($menu);

        abort_if($found === null, 404);

        $this->router = $router;
        $this->menuKey = $found->key();
    }

    #[Computed]
    public function menu(): ConsoleMenu
    {
        // Resolved again on every request rather than trusted from the wire: menuKey is a
        // public property, and an unknown one must not quietly become some other menu.
        return app(ConsoleMenus::class)->find($this->menuKey) ?? abort(404);
    }

    /**
     * @return array<string, array<string, ConsoleMenu>>
     */
    #[Computed]
    public function menus(): array
    {
        return app(ConsoleMenus::class)->grouped();
    }

    /**
     * What the device holds right now, or why it could not be read.
     *
     * @return array{rows: array<int, array<string, string>>, error: string|null}
     */
    #[Computed]
    public function result(): array
    {
        try {
            return ['rows' => app(RouterConsole::class)->rows($this->router, $this->menu), 'error' => null];
        } catch (RouterOsConnectionException $e) {
            return ['rows' => [], 'error' => __('Could not reach the router: :reason', ['reason' => $e->getMessage()])];
        } catch (RouterOsCommandException $e) {
            // Most often the platform's API user lacks the policy this menu needs.
            return ['rows' => [], 'error' => __('The router refused to show this: :reason', ['reason' => $e->getMessage()])];
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    #[Computed]
    public function rows(): array
    {
        $rows = $this->result['rows'];
        $needle = mb_strtolower(trim($this->search));

        if ($needle === '') {
            return $rows;
        }

        $columns = array_values($this->menu->columns());

        return array_values(array_filter($rows, function (array $row) use ($columns, $needle): bool {
            foreach ($columns as $property) {
                if (str_contains(mb_strtolower((string) ($row[$property] ?? '')), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function refresh(): void
    {
        unset($this->result, $this->rows);
    }

    public function create(): void
    {
        Gate::authorize('command', $this->router);

        $this->resetValidation();
        $this->editing = 'new';
        $this->form = [];

        foreach (array_keys($this->menu->editableFields(creating: true)) as $property) {
            $this->form[ConsoleMenu::formKey($property)] = '';
        }

        Flux::modal('console-row')->show();
    }

    public function edit(string $id): void
    {
        Gate::authorize('command', $this->router);

        $row = $this->menu->isSingleton()
            ? ($this->result['rows'][0] ?? null)
            : collect($this->result['rows'])->firstWhere('.id', $id);

        if ($row === null) {
            Flux::toast(variant: 'danger', text: __('That row is no longer on the router. Refresh to see what is.'));

            return;
        }

        $this->resetValidation();
        $this->editing = $id;
        $this->form = [];

        foreach (array_keys($this->menu->editableFields(creating: false)) as $property) {
            $this->form[ConsoleMenu::formKey($property)] = (string) ($row[$property] ?? '');
        }

        Flux::modal('console-row')->show();
    }

    public function save(RouterConsole $console): void
    {
        Gate::authorize('command', $this->router);

        if ($this->editing === null) {
            return;
        }

        $creating = $this->editing === 'new';

        $this->validate($this->menu->rules($creating));

        $values = $this->menu->fromForm($this->form, $creating);

        $done = $this->attempt(fn () => $creating
            ? $console->add($this->router, $this->menu, $values)
            : $console->update($this->router, $this->menu, (string) $this->editing, $values));

        if ($done) {
            Flux::modal('console-row')->close();
            $this->editing = null;
            Flux::toast(variant: 'success', text: $creating ? __('Added.') : __('Saved.'));
        }
    }

    public function toggle(string $id, bool $disable, RouterConsole $console): void
    {
        Gate::authorize('command', $this->router);

        if ($this->attempt(fn () => $console->toggle($this->router, $this->menu, $id, $disable))) {
            Flux::toast(variant: 'success', text: $disable ? __('Disabled.') : __('Enabled.'));
        }
    }

    public function remove(string $id, RouterConsole $console): void
    {
        Gate::authorize('command', $this->router);

        if ($this->attempt(fn () => $console->remove($this->router, $this->menu, $id))) {
            Flux::toast(variant: 'success', text: __('Removed.'));
        }
    }

    /**
     * Restart the router, from the page an operator is already on when it misbehaves.
     *
     * Customers lose their sessions for the minute it takes; the outage watcher notices, and
     * logs back in whoever was online, the same as after a power cut.
     */
    public function reboot(RouterConsole $console): void
    {
        Gate::authorize('command', $this->router);

        if ($this->attempt(fn () => $console->reboot($this->router))) {
            Flux::toast(variant: 'warning', text: __('Rebooting. The router will be back in a minute or two.'));
        }
    }

    public function run(string $id, string $command, RouterConsole $console): void
    {
        Gate::authorize('command', $this->router);

        if ($this->attempt(fn () => $console->command($this->router, $this->menu, $id, $command))) {
            Flux::toast(variant: 'success', text: __('Done.'));
        }
    }

    /**
     * Run one change, turning every way it can fail into something the operator can read.
     *
     * The table is re-read afterwards whatever happened: after a refusal the device is the
     * only reliable account of what it now holds.
     */
    protected function attempt(callable $change): bool
    {
        try {
            $change();

            return true;
        } catch (ConsoleRefusal $e) {
            Flux::toast(variant: 'warning', text: $e->getMessage());
        } catch (RouterOsCommandException $e) {
            Flux::toast(variant: 'danger', text: __('The router refused: :reason', ['reason' => $e->getMessage()]));
        } catch (RouterOsConnectionException $e) {
            Flux::toast(variant: 'danger', text: __('Could not reach the router: :reason', ['reason' => $e->getMessage()]));
        } finally {
            $this->refresh();
        }

        return false;
    }

    /**
     * How a RouterOS value reads in the table.
     */
    public function display(string $value): string
    {
        return match ($value) {
            'true' => __('yes'),
            'false' => __('no'),
            default => $value,
        };
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <flux:heading size="xl">{{ $router->name }}</flux:heading>
                    <flux:badge size="sm" :color="$router->status->color()">{{ $router->status->label() }}</flux:badge>
                </div>
                <flux:subheading>{{ __('Router console') }}</flux:subheading>
            </div>

            <div class="flex items-center gap-2">
                @can('command', $router)
                    <flux:button
                        icon="power"
                        variant="danger"
                        wire:click="reboot"
                        wire:confirm="{{ __('Reboot :name? Everyone on it goes offline for a minute or two. Customers who were online are logged back in automatically.', ['name' => $router->name]) }}"
                    >
                        {{ __('Reboot') }}
                    </flux:button>
                @endcan

                <flux:button variant="ghost" icon="arrow-left" :href="route('routers.show', $router)" wire:navigate>
                    {{ __('Back to router') }}
                </flux:button>
            </div>
        </div>

        <div class="flex items-start gap-6 max-md:flex-col">
            <nav class="w-full shrink-0 md:w-56">
                <flux:navlist aria-label="{{ __('Router console') }}">
                    @foreach ($this->menus as $group => $items)
                        <flux:navlist.group :heading="$group" class="mb-2">
                            @foreach ($items as $key => $item)
                                <flux:navlist.item
                                    :icon="$item->icon()"
                                    :href="route('routers.console', ['router' => $router, 'menu' => $key])"
                                    :current="$key === $menuKey"
                                    wire:navigate
                                >
                                    {{ $item->label() }}
                                </flux:navlist.item>
                            @endforeach
                        </flux:navlist.group>
                    @endforeach
                </flux:navlist>
            </nav>

            <div class="flex min-w-0 flex-1 flex-col gap-4">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <flux:heading size="lg">{{ $this->menu->label() }}</flux:heading>
                        <flux:text class="mt-1">{{ $this->menu->description() }}</flux:text>
                        <flux:text size="sm" class="mt-1 font-mono opacity-60">{{ $this->menu->path() }}</flux:text>
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" size="sm" :placeholder="__('Filter')" class="w-48" />

                        <flux:button size="sm" icon="arrow-path" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh">
                            {{ __('Refresh') }}
                        </flux:button>

                        @if ($this->menu->canAdd())
                            @can('command', $router)
                                <flux:button size="sm" variant="primary" icon="plus" wire:click="create">
                                    {{ __('Add') }}
                                </flux:button>
                            @endcan
                        @endif
                    </div>
                </div>

                @if ($this->result['error'] !== null)
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.heading>{{ __('Cannot read this from the router') }}</flux:callout.heading>
                        <flux:callout.text>{{ $this->result['error'] }}</flux:callout.text>
                    </flux:callout>
                @else
                    @php
                        $menu = $this->menu;
                        $canChange = auth()->user()->can('command', $router);
                        $hasActions = $canChange && ($menu->canEdit() || $menu->canToggle() || $menu->canRemove() || $menu->rowCommands() !== []);
                    @endphp

                    <flux:card class="overflow-x-auto p-0">
                        <flux:table>
                            <flux:table.columns>
                                @foreach ($menu->columns() as $heading => $property)
                                    <flux:table.column>{{ $heading }}</flux:table.column>
                                @endforeach
                                @if ($hasActions)
                                    <flux:table.column />
                                @endif
                            </flux:table.columns>

                            <flux:table.rows>
                                @forelse ($this->rows as $row)
                                    @php
                                        $id = (string) ($row['.id'] ?? ($menu->isSingleton() ? 'singleton' : ''));
                                        $disabled = ($row['disabled'] ?? 'false') === 'true';
                                        $protected = $menu->protects($row, $router);
                                    @endphp

                                    <flux:table.row :key="$menuKey.$id.$loop->index" @class(['opacity-50' => $disabled])>
                                        @foreach ($menu->columns() as $property)
                                            <flux:table.cell @class(['font-mono text-xs' => in_array($property, ['mac-address', 'address', 'target', 'time'], true), 'whitespace-normal' => $property === 'message'])>
                                                @if ($loop->first)
                                                    <span class="inline-flex items-center gap-2">
                                                        {{ $this->display((string) ($row[$property] ?? '')) ?: '—' }}
                                                        @if ($disabled)
                                                            <flux:badge size="sm" color="zinc">{{ __('disabled') }}</flux:badge>
                                                        @endif
                                                        @if ($protected !== null)
                                                            <flux:tooltip :content="$protected">
                                                                <flux:icon.shield-check variant="micro" class="text-blue-500" />
                                                            </flux:tooltip>
                                                        @endif
                                                    </span>
                                                @elseif (in_array($row[$property] ?? null, ['true', 'false'], true))
                                                    <flux:badge size="sm" :color="$row[$property] === 'true' ? 'green' : 'zinc'">
                                                        {{ $this->display($row[$property]) }}
                                                    </flux:badge>
                                                @else
                                                    {{ ($row[$property] ?? '') !== '' ? $row[$property] : '—' }}
                                                @endif
                                            </flux:table.cell>
                                        @endforeach

                                        @if ($hasActions)
                                            <flux:table.cell class="text-end">
                                                @if ($protected === null && $id !== '')
                                                    <flux:dropdown position="bottom" align="end">
                                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />

                                                        <flux:menu>
                                                            @if ($menu->canEdit())
                                                                <flux:menu.item icon="pencil-square" wire:click="edit('{{ $id }}')">{{ __('Edit') }}</flux:menu.item>
                                                            @endif

                                                            @if ($menu->canToggle())
                                                                <flux:menu.item icon="power" wire:click="toggle('{{ $id }}', {{ $disabled ? 'false' : 'true' }})">
                                                                    {{ $disabled ? __('Enable') : __('Disable') }}
                                                                </flux:menu.item>
                                                            @endif

                                                            @foreach ($menu->rowCommands() as $command => $definition)
                                                                @if ($menu->commandApplies($command, $row))
                                                                    <flux:menu.item :icon="$definition['icon'] ?? 'wrench-screwdriver'" wire:click="run('{{ $id }}', '{{ $command }}')">
                                                                        {{ $definition['label'] }}
                                                                    </flux:menu.item>
                                                                @endif
                                                            @endforeach

                                                            @if ($menu->canRemove())
                                                                <flux:menu.separator />
                                                                <flux:menu.item icon="trash" variant="danger" wire:click="remove('{{ $id }}')" wire:confirm="{{ __(':action this on the router?', ['action' => $menu->removeLabel()]) }}">
                                                                    {{ $menu->removeLabel() }}
                                                                </flux:menu.item>
                                                            @endif
                                                        </flux:menu>
                                                    </flux:dropdown>
                                                @endif
                                            </flux:table.cell>
                                        @endif
                                    </flux:table.row>
                                @empty
                                    <flux:table.row>
                                        <flux:table.cell :colspan="count($menu->columns()) + ($hasActions ? 1 : 0)" class="py-8 text-center opacity-60">
                                            {{ $search !== '' ? __('Nothing matches that filter.') : __('Nothing here on the router.') }}
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforelse
                            </flux:table.rows>
                        </flux:table>
                    </flux:card>
                @endif
            </div>
        </div>
    </div>

    <flux:modal name="console-row" class="md:w-120" wire:close="$set('editing', null)">
        <form wire:submit="save" class="flex flex-col gap-5">
            <flux:heading size="lg">
                {{ $editing === 'new' ? __('Add to :menu', ['menu' => $this->menu->label()]) : __('Edit :menu', ['menu' => $this->menu->label()]) }}
            </flux:heading>

            @if ($editing !== null)
                @foreach ($this->menu->editableFields($editing === 'new') as $property => $field)
                    @php($key = \App\Services\RouterOs\Console\ConsoleMenu::formKey($property))

                    @if (($field['type'] ?? 'text') === 'select')
                        <flux:select wire:model="form.{{ $key }}" :label="$field['label']" :description="$field['description'] ?? null">
                            @foreach ($field['options'] ?? [] as $value => $label)
                                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @elseif (($field['type'] ?? 'text') === 'password')
                        <flux:input
                            wire:model="form.{{ $key }}"
                            type="password"
                            viewable
                            :label="$field['label']"
                            :description="$field['description'] ?? null"
                        />
                    @else
                        <flux:input
                            wire:model="form.{{ $key }}"
                            :label="$field['label']"
                            :placeholder="$field['placeholder'] ?? null"
                            :description="$field['description'] ?? null"
                        />
                    @endif
                @endforeach
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                    {{ __('Save to router') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
