<?php

use App\Enums\ServiceAccountStatus;
use App\Enums\ServiceType;
use App\Models\Router;
use App\Models\ServiceAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every PPPoE line this ISP runs, across all customers, by where it stands on payment.
 *
 * The customer page is where a line is created and managed; this is where an ISP sees all of
 * them at once — who is due this week, who has been cut off, who never paid at all — which is
 * the list a collections morning starts from.
 */
new #[Title('PPPoE subscribers')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** all, due (within the reminder window), cut (disconnected for non-payment), unpaid, suspended. */
    #[Url(as: 'view', except: 'all')]
    public string $show = 'all';

    #[Url(except: '')]
    public string $router = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', ServiceAccount::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'show', 'router'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return Builder<ServiceAccount>
     */
    protected function lines(): Builder
    {
        // Qualified: the monthly total joins plans, which has a type of its own.
        return ServiceAccount::query()
            ->select('service_accounts.*')
            ->where('service_accounts.type', ServiceType::Pppoe)
            ->where('service_accounts.status', '!=', ServiceAccountStatus::Terminated);
    }

    /**
     * @return array{active: int, due: int, cut: int, unpaid: int, monthly: float}
     */
    #[Computed]
    public function counts(): array
    {
        $soon = now()->addDays(3)->endOfDay();

        return [
            'active' => (clone $this->lines())->where('status', ServiceAccountStatus::Active)->count(),
            'due' => (clone $this->lines())->where('status', ServiceAccountStatus::Active)->whereBetween('expires_at', [now(), $soon])->count(),
            'cut' => (clone $this->lines())->where('suspended_for_nonpayment', true)->count(),
            'unpaid' => (clone $this->lines())->whereNotNull('plan_id')->whereNull('expires_at')->count(),
            // What the active lines bring in a month at their packages' prices: the number an
            // ISP is protecting when they chase the overdue ones.
            'monthly' => (float) (clone $this->lines())
                ->where('service_accounts.status', ServiceAccountStatus::Active)
                ->join('plans', 'plans.id', '=', 'service_accounts.plan_id')
                ->sum('plans.price'),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Router>
     */
    #[Computed]
    public function routers(): \Illuminate\Support\Collection
    {
        return Router::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function accounts(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return $this->lines()
            ->with(['customer', 'plan', 'router'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('account_number', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhereHas('customer', fn (Builder $customer) => $customer
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%"))))
            ->when($this->router !== '', fn (Builder $query) => $query->where('router_id', (int) $this->router))
            ->when($this->show === 'due', fn (Builder $query) => $query
                ->where('status', ServiceAccountStatus::Active)
                ->whereBetween('expires_at', [now(), now()->addDays(3)->endOfDay()]))
            ->when($this->show === 'cut', fn (Builder $query) => $query->where('suspended_for_nonpayment', true))
            ->when($this->show === 'unpaid', fn (Builder $query) => $query->whereNotNull('plan_id')->whereNull('expires_at'))
            ->when($this->show === 'suspended', fn (Builder $query) => $query
                ->where('status', ServiceAccountStatus::Suspended)
                ->where('suspended_for_nonpayment', false))
            // Soonest due first: the order a collections list is worked in.
            ->orderByRaw('expires_at IS NULL, expires_at asc')
            ->orderBy('account_number')
            ->paginate(20);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('PPPoE subscribers') }}</flux:heading>
                <flux:subheading>{{ __('Every fixed line, by where it stands on payment. Lines are added on a customer\'s page.') }}</flux:subheading>
            </div>

            <div class="flex items-center gap-2">
                @can(App\Support\Authorization\Permissions::ROUTERS_PROVISION)
                    <flux:button variant="ghost" icon="bolt" :href="route('routers.manage', ['section' => 'pppoe'])" wire:navigate>{{ __('PPPoE setup') }}</flux:button>
                @endcan
                @can('create', App\Models\Customer::class)
                    <flux:button variant="primary" icon="plus" :href="route('customers.create')" wire:navigate>{{ __('New subscriber') }}</flux:button>
                @endcan
            </div>
        </div>

        @php($counts = $this->counts)
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ([
                ['all', __('Active lines'), number_format($counts['active']), 'text-zinc-900 dark:text-white'],
                ['due', __('Due in 3 days'), number_format($counts['due']), 'text-amber-600'],
                ['cut', __('Cut for non-payment'), number_format($counts['cut']), 'text-[#d03b3b]'],
                ['unpaid', __('Never paid'), number_format($counts['unpaid']), 'text-zinc-900 dark:text-white'],
            ] as [$key, $label, $value, $tone])
                <button type="button" wire:click="$set('show', '{{ $key }}')" @class([
                    'rounded-xl border bg-white p-4 text-left shadow-xs transition hover:border-blue-300 dark:bg-zinc-900',
                    'border-blue-500 ring-2 ring-blue-500/20' => $show === $key,
                    'border-zinc-200 dark:border-zinc-700' => $show !== $key,
                ])>
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold {{ $tone }}">{{ $value }}</p>
                </button>
            @endforeach
            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Monthly value') }}</p>
                <p class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">KES {{ number_format($counts['monthly'], 0) }}</p>
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Name, phone, account or login…')" class="max-w-xs" />
            <flux:select wire:model.live="show" class="max-w-48">
                <flux:select.option value="all">{{ __('All lines') }}</flux:select.option>
                <flux:select.option value="due">{{ __('Due in 3 days') }}</flux:select.option>
                <flux:select.option value="cut">{{ __('Cut for non-payment') }}</flux:select.option>
                <flux:select.option value="unpaid">{{ __('Never paid') }}</flux:select.option>
                <flux:select.option value="suspended">{{ __('Suspended by hand') }}</flux:select.option>
            </flux:select>
            @if ($this->routers->count() > 1)
                <flux:select wire:model.live="router" class="max-w-48">
                    <flux:select.option value="">{{ __('All routers') }}</flux:select.option>
                    @foreach ($this->routers as $r)
                        <flux:select.option value="{{ $r->id }}">{{ $r->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </div>

        <flux:table :paginate="$this->accounts">
            <flux:table.columns>
                <flux:table.column>{{ __('Subscriber') }}</flux:table.column>
                <flux:table.column>{{ __('Login') }}</flux:table.column>
                <flux:table.column>{{ __('Package') }}</flux:table.column>
                <flux:table.column>{{ __('Router') }}</flux:table.column>
                <flux:table.column>{{ __('Paid until') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->accounts as $account)
                    @php($days = $account->daysLeft())
                    <flux:table.row :key="$account->id">
                        <flux:table.cell>
                            <a href="{{ route('customers.show', $account->customer) }}" wire:navigate class="font-medium text-zinc-900 hover:underline dark:text-white">{{ $account->customer?->name }}</a>
                            <flux:text size="sm" class="opacity-70">{{ $account->account_number }} · {{ $account->customer?->phone }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-sm">{{ $account->username }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $account->plan?->name ?? '—' }}
                            @if ($account->plan)
                                <flux:text size="sm" class="opacity-70">KES {{ number_format((float) $account->plan->price, 0) }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $account->router?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($days === null)
                                <flux:text size="sm" class="opacity-60">{{ $account->plan_id ? __('Not paid yet') : '—' }}</flux:text>
                            @else
                                <span class="text-sm">{{ $account->expires_at->format('j M Y') }}</span>
                                @if ($days < 0)
                                    <flux:badge size="sm" color="red" inset="top bottom">{{ __('Overdue') }}</flux:badge>
                                @elseif ($days === 0)
                                    <flux:badge size="sm" color="amber" inset="top bottom">{{ __('Due today') }}</flux:badge>
                                @elseif ($days <= 3)
                                    <flux:badge size="sm" color="amber" inset="top bottom">{{ trans_choice('Due in :count day|Due in :count days', $days) }}</flux:badge>
                                @endif
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($account->suspended_for_nonpayment)
                                <flux:badge size="sm" color="red" inset="top bottom">{{ __('Cut — unpaid') }}</flux:badge>
                            @else
                                <flux:badge size="sm" :color="$account->status->color()" inset="top bottom">{{ $account->status->label() }}</flux:badge>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="text-center">
                            <flux:text>{{ $search !== '' || $show !== 'all' ? __('No lines match.') : __('No PPPoE lines yet. Open a customer and add a PPPoE service to start.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
