<?php

use App\Enums\PlanType;
use App\Models\Plan;
use App\Models\Router;
use App\Services\RouterOs\PlanProfileSynchroniser;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Plans')] class extends Component {
    #[Url(except: 'hotspot')]
    public string $type = 'hotspot';

    public function mount(): void
    {
        Gate::authorize('viewAny', Plan::class);
    }

    /**
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return Plan::query()
            ->ofType(PlanType::from($this->type))
            ->with('routerProfiles.router')
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get();
    }

    #[Computed]
    public function routerCount(): int
    {
        return Router::query()->count();
    }

    public function push(int $planId, PlanProfileSynchroniser $sync): void
    {
        $plan = Plan::query()->findOrFail($planId);

        Gate::authorize('push', $plan);

        $results = $sync->syncToAllRouters($plan);

        unset($this->plans);

        $failed = collect($results)->reject->isSynced();

        $failed->isEmpty()
            ? Flux::toast(variant: 'success', text: __('Pushed :plan to :count router(s).', ['plan' => $plan->name, 'count' => count($results)]))
            : Flux::toast(variant: 'danger', text: $failed->first()->sync_error);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6 p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Plans') }}</flux:heading>
                <flux:subheading>
                    {{ __('Packages you sell. Each one is copied onto your routers as a profile that shapes the traffic.') }}
                </flux:subheading>
            </div>

            @can('create', App\Models\Plan::class)
                <flux:button variant="primary" icon="plus" :href="route('plans.create', ['type' => $type])" wire:navigate>
                    {{ __('New plan') }}
                </flux:button>
            @endcan
        </div>

        <flux:radio.group wire:model.live="type" variant="segmented">
            @foreach (PlanType::cases() as $case)
                <flux:radio value="{{ $case->value }}" label="{{ $case->label() }}" />
            @endforeach
        </flux:radio.group>

        @if ($this->routerCount === 0)
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('No routers yet') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Plans only shape traffic once they are pushed to a router. Add one first.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Plan') }}</flux:table.column>
                <flux:table.column>{{ __('Price') }}</flux:table.column>
                <flux:table.column>{{ __('Validity') }}</flux:table.column>
                <flux:table.column>{{ __('Speed') }}</flux:table.column>
                <flux:table.column>{{ __('Data') }}</flux:table.column>
                <flux:table.column>{{ __('On routers') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->plans as $plan)
                    <flux:table.row wire:key="plan-{{ $plan->id }}">
                        <flux:table.cell>
                            <flux:link :href="route('plans.edit', $plan)" wire:navigate variant="ghost">
                                {{ $plan->name }}
                            </flux:link>
                            <flux:text size="sm" class="font-mono opacity-60">{{ $plan->code }}</flux:text>
                            @unless ($plan->is_active)
                                <flux:badge size="sm" color="zinc" inset="top bottom">{{ __('inactive') }}</flux:badge>
                            @endunless
                        </flux:table.cell>
                        <flux:table.cell variant="strong">{{ number_format((float) $plan->price, 2) }}</flux:table.cell>
                        <flux:table.cell>{{ $plan->validityForHumans() }}</flux:table.cell>
                        <flux:table.cell>{{ $plan->speedForHumans() }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $plan->data_cap_mb ? number_format($plan->data_cap_mb).' MB' : __('Uncapped') }}
                        </flux:table.cell>
                        <flux:table.cell>
                            @forelse ($plan->routerProfiles as $profile)
                                <flux:badge
                                    size="sm"
                                    :color="$profile->statusColor()"
                                    inset="top bottom"
                                    :title="$profile->sync_error ?? $profile->router?->name"
                                >
                                    {{ $profile->router?->name ?? '—' }}
                                </flux:badge>
                            @empty
                                <flux:text size="sm" class="opacity-60">{{ __('not pushed') }}</flux:text>
                            @endforelse
                        </flux:table.cell>
                        <flux:table.cell class="text-end">
                            @can('push', $plan)
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="arrow-up-tray"
                                    wire:click="push({{ $plan->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="push({{ $plan->id }})"
                                >
                                    {{ __('Push') }}
                                </flux:button>
                            @endcan
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7" class="text-center">
                            <flux:text>{{ __('No :type plans yet.', ['type' => PlanType::from($type)->label()]) }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
