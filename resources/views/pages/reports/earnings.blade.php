<?php

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Router;
use App\Support\Authorization\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Earnings')] class extends Component {
    use WithPagination;

    #[Url(except: '30')]
    public string $range = '30';

    #[Url(except: '')]
    public string $routerId = '';

    public function mount(): void
    {
        Gate::authorize(Permissions::REPORTS_VIEW_REVENUE);
    }

    public function updatedRange(): void
    {
        $this->resetPage();
    }

    public function updatedRouterId(): void
    {
        $this->resetPage();
    }

    protected function since(): CarbonImmutable
    {
        return now()->subDays(max(1, (int) $this->range) - 1)->startOfDay()->toImmutable();
    }

    /**
     * Confirmed money only. Pending and failed attempts are counted separately, because
     * mixing them into revenue is how a report ends up flattering.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Payment>
     */
    protected function paidQuery()
    {
        // Qualified throughout: the breakdowns join plans and routers, which share column
        // names with payments.
        return Payment::query()
            ->paid()
            ->where('payments.paid_at', '>=', $this->since())
            ->when($this->routerId !== '', fn ($query) => $query->where('payments.router_id', $this->routerId));
    }

    /**
     * @return array{total: string, count: int, average: string, customers: int}
     */
    #[Computed]
    public function totals(): array
    {
        $row = (clone $this->paidQuery())
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as count, COUNT(DISTINCT phone) as customers')
            ->first();

        $count = (int) $row->count;

        return [
            'total' => (string) $row->total,
            'count' => $count,
            'average' => $count > 0 ? number_format((float) $row->total / $count, 2) : '0.00',
            'customers' => (int) $row->customers,
        ];
    }

    /**
     * How many attempts actually turned into money — the number that says whether the
     * portal is working.
     */
    #[Computed]
    public function conversion(): array
    {
        $rows = Payment::query()
            ->where('created_at', '>=', $this->since())
            ->when($this->routerId !== '', fn ($query) => $query->where('router_id', $this->routerId))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $attempts = (int) $rows->sum();
        $paid = (int) ($rows[PaymentStatus::Paid->value] ?? 0);

        return [
            'attempts' => $attempts,
            'paid' => $paid,
            'rate' => $attempts > 0 ? round($paid / $attempts * 100) : 0,
        ];
    }

    /**
     * One entry per day in the range, including days that earned nothing — a gap in a
     * trend should read as a quiet day, not as missing data.
     *
     * @return Collection<int, array{date: CarbonImmutable, total: float}>
     */
    #[Computed]
    public function daily(): Collection
    {
        $earned = (clone $this->paidQuery())
            ->selectRaw('DATE(paid_at) as day, SUM(amount) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $days = (int) $this->range;

        return collect(range($days - 1, 0))->map(function (int $ago) use ($earned): array {
            $date = now()->subDays($ago)->startOfDay()->toImmutable();

            return [
                'date' => $date,
                'total' => (float) ($earned[$date->format('Y-m-d')] ?? 0),
            ];
        });
    }

    #[Computed]
    public function peakDay(): float
    {
        return (float) $this->daily->max('total') ?: 0.0;
    }

    /**
     * @return Collection<int, object>
     */
    #[Computed]
    public function byPlan(): Collection
    {
        return (clone $this->paidQuery())
            ->join('plans', 'plans.id', '=', 'payments.plan_id')
            ->selectRaw('plans.name as name, SUM(payments.amount) as total, COUNT(*) as count')
            ->groupBy('plans.id', 'plans.name')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    #[Computed]
    public function byRouter(): Collection
    {
        return (clone $this->paidQuery())
            ->join('routers', 'routers.id', '=', 'payments.router_id')
            ->selectRaw('routers.name as name, SUM(payments.amount) as total, COUNT(*) as count')
            ->groupBy('routers.id', 'routers.name')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, Payment>
     */
    #[Computed]
    public function transactions(): LengthAwarePaginator
    {
        return Payment::query()
            ->with(['plan', 'router'])
            ->where('created_at', '>=', $this->since())
            ->when($this->routerId !== '', fn ($query) => $query->where('router_id', $this->routerId))
            ->latest('created_at')
            ->paginate(15);
    }

    /**
     * @return Collection<int, Router>
     */
    #[Computed]
    public function routers(): Collection
    {
        return Router::query()->orderBy('name')->get();
    }

    #[Computed]
    public function currency(): string
    {
        return \App\Support\Tenancy\Facades\Tenancy::tenant()?->currency ?? 'KES';
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6 p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Earnings') }}</flux:heading>
                <flux:subheading>{{ __('Confirmed M-PESA payments. Pending and failed attempts are excluded from revenue.') }}</flux:subheading>
            </div>

            {{-- Filters sit in one row above the charts. --}}
            <div class="flex flex-wrap items-center gap-3">
                <flux:select wire:model.live="routerId" class="max-w-48">
                    <flux:select.option value="">{{ __('All routers') }}</flux:select.option>
                    @foreach ($this->routers as $router)
                        <flux:select.option value="{{ $router->id }}">{{ $router->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:radio.group wire:model.live="range" variant="segmented">
                    <flux:radio value="7" :label="__('7 days')" />
                    <flux:radio value="30" :label="__('30 days')" />
                    <flux:radio value="90" :label="__('90 days')" />
                </flux:radio.group>
            </div>
        </div>

        {{-- Hero number first: the one figure the ISP owner opens this page for. --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:card>
                <flux:text size="sm" class="opacity-60">{{ __('Collected') }}</flux:text>
                <p class="mt-1 text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">
                    {{ number_format((float) $this->totals['total'], 2) }}
                </p>
                <flux:text size="sm" class="opacity-60">{{ $this->currency }}</flux:text>
            </flux:card>

            <flux:card>
                <flux:text size="sm" class="opacity-60">{{ __('Payments') }}</flux:text>
                <p class="mt-1 text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">
                    {{ number_format($this->totals['count']) }}
                </p>
                <flux:text size="sm" class="opacity-60">
                    {{ __(':count buyers', ['count' => number_format($this->totals['customers'])]) }}
                </flux:text>
            </flux:card>

            <flux:card>
                <flux:text size="sm" class="opacity-60">{{ __('Average sale') }}</flux:text>
                <p class="mt-1 text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">
                    {{ $this->totals['average'] }}
                </p>
                <flux:text size="sm" class="opacity-60">{{ $this->currency }}</flux:text>
            </flux:card>

            <flux:card>
                <flux:text size="sm" class="opacity-60">{{ __('Completed') }}</flux:text>
                <p class="mt-1 text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">
                    {{ $this->conversion['rate'] }}%
                </p>
                <flux:text size="sm" class="opacity-60">
                    {{ __(':paid of :attempts attempts', ['paid' => $this->conversion['paid'], 'attempts' => $this->conversion['attempts']]) }}
                </flux:text>
            </flux:card>
        </div>

        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Daily revenue') }}</flux:heading>
                <flux:subheading>{{ __('Confirmed payments per day, in :currency', ['currency' => $this->currency]) }}</flux:subheading>
            </div>

            @if ($this->peakDay <= 0)
                <div class="flex h-48 items-center justify-center">
                    <flux:text>{{ __('No payments in this period yet.') }}</flux:text>
                </div>
            @else
                {{-- Single series, so no legend: the title names it. Bars are anchored to the
                     baseline with rounded data-ends, and a 2px gap keeps neighbours apart. --}}
                <div class="flex h-48 items-end gap-[2px]" role="img"
                     aria-label="{{ __('Daily revenue for the last :days days', ['days' => $this->range]) }}">
                    @foreach ($this->daily as $day)
                        @php($height = $this->peakDay > 0 ? max(2, round($day['total'] / $this->peakDay * 100)) : 2)
                        <div
                            class="group relative flex flex-1 items-end self-stretch"
                            title="{{ $day['date']->format('D j M') }} — {{ number_format($day['total'], 2) }}"
                        >
                            <div
                                @class([
                                    'w-full rounded-t transition-colors',
                                    'bg-blue-600 group-hover:bg-blue-700 dark:bg-blue-400 dark:group-hover:bg-blue-300' => $day['total'] > 0,
                                    'bg-zinc-200 dark:bg-zinc-700' => $day['total'] <= 0,
                                ])
                                style="height: {{ $height }}%"
                            ></div>

                            {{-- Hover layer rather than a number on every bar. --}}
                            <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-zinc-900 px-2 py-1 text-xs text-white group-hover:block dark:bg-zinc-100 dark:text-zinc-900">
                                <span class="font-medium">{{ number_format($day['total'], 2) }}</span>
                                <span class="opacity-70">{{ $day['date']->format('j M') }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-between">
                    <flux:text size="sm" class="opacity-60">{{ $this->daily->first()['date']->format('j M') }}</flux:text>
                    <flux:text size="sm" class="opacity-60">{{ $this->daily->last()['date']->format('j M') }}</flux:text>
                </div>
            @endif
        </flux:card>

        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ([__('By package') => $this->byPlan, __('By router') => $this->byRouter] as $heading => $rows)
                <flux:card class="flex flex-col gap-4" wire:key="breakdown-{{ $loop->index }}">
                    <flux:heading size="lg">{{ $heading }}</flux:heading>

                    @if ($rows->isEmpty())
                        <flux:text>{{ __('Nothing yet.') }}</flux:text>
                    @else
                        @php($max = (float) $rows->max('total'))
                        <div class="flex flex-col gap-3">
                            @foreach ($rows as $row)
                                <div wire:key="row-{{ $loop->parent->index }}-{{ $loop->index }}">
                                    <div class="flex items-baseline justify-between gap-4">
                                        <flux:text>{{ $row->name }}</flux:text>
                                        <div class="flex items-baseline gap-3">
                                            <flux:text size="sm" class="opacity-60">
                                                {{ number_format((int) $row->count) }}&times;
                                            </flux:text>
                                            <flux:text class="tabular-nums font-medium">
                                                {{ number_format((float) $row->total, 2) }}
                                            </flux:text>
                                        </div>
                                    </div>
                                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <div
                                            class="h-full rounded-full bg-blue-600 dark:bg-blue-400"
                                            style="width: {{ $max > 0 ? max(2, round((float) $row->total / $max * 100)) : 0 }}%"
                                        ></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </flux:card>
            @endforeach
        </div>

        <flux:card class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Transactions') }}</flux:heading>

            <flux:table :paginate="$this->transactions">
                <flux:table.columns>
                    <flux:table.column>{{ __('When') }}</flux:table.column>
                    <flux:table.column>{{ __('Phone') }}</flux:table.column>
                    <flux:table.column>{{ __('Package') }}</flux:table.column>
                    <flux:table.column>{{ __('Receipt') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->transactions as $payment)
                        <flux:table.row wire:key="txn-{{ $payment->id }}">
                            <flux:table.cell class="whitespace-nowrap">
                                {{ $payment->created_at->format('j M H:i') }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $payment->phone }}</flux:table.cell>
                            <flux:table.cell>{{ $payment->plan?->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell class="font-mono text-xs">{{ $payment->receipt ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                {{-- Status carries a label, never colour alone. --}}
                                <flux:badge size="sm" :color="$payment->status->color()" inset="top bottom">
                                    {{ $payment->status->label() }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums" variant="strong">
                                {{ number_format((float) $payment->amount, 2) }}
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="text-center">
                                <flux:text>{{ __('No payment attempts in this period.') }}</flux:text>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>
</section>
