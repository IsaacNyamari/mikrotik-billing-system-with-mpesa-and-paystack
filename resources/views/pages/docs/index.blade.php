<?php

use App\Models\Router;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The manual, for the people who run an ISP on this platform.
 *
 * Deliberately inside the application rather than on a documentation site somewhere. Almost
 * every question an operator has is asked while they are looking at the screen the answer is
 * about, and half of them are asked while a router is down — which is exactly when a link to
 * somebody else's website is least useful.
 *
 * Also deliberately outside the subscription gate, for the same reason the billing screen is:
 * an ISP whose invoice has lapsed still needs to read how to pay it.
 */
new #[Title('Documentation')] class extends Component {
    /**
     * Which chapter is open. In the URL so a colleague can be sent straight to it.
     */
    #[Url(except: 'start')]
    public string $section = 'start';

    public string $search = '';

    /**
     * The chapters, in the order somebody setting up an ISP meets them.
     *
     * @return array<int, array{id: string, title: string, icon: string, summary: string}>
     */
    #[Computed]
    public function chapters(): array
    {
        return [
            [
                'id' => 'start',
                'title' => __('Getting started'),
                'icon' => 'rocket-launch',
                'summary' => __('What this platform is, and the order to set it up in.'),
            ],
            [
                'id' => 'routers',
                'title' => __('Adding a router'),
                'icon' => 'server',
                'summary' => __('The one paste that turns a stock MikroTik into a router this platform can run.'),
            ],
            [
                'id' => 'tunnel',
                'title' => __('The management tunnel'),
                'icon' => 'lock-closed',
                'summary' => __('How the platform reaches routers nobody can dial, and how to keep it quick.'),
            ],
            [
                'id' => 'plans',
                'title' => __('Plans and packages'),
                'icon' => 'wifi',
                'summary' => __('What you sell, and how it reaches the device.'),
            ],
            [
                'id' => 'portal',
                'title' => __('The captive portal'),
                'icon' => 'device-phone-mobile',
                'summary' => __('The page your customers meet, and how to make it yours.'),
            ],
            [
                'id' => 'payments',
                'title' => __('Taking payment'),
                'icon' => 'credit-card',
                'summary' => __('M-PESA and Paystack, what settles a payment, and what customers are told.'),
            ],
            [
                'id' => 'recovery',
                'title' => __('Paid but not online'),
                'icon' => 'lifebuoy',
                'summary' => __('Every way a customer gets back in without paying twice.'),
            ],
            [
                'id' => 'pppoe',
                'title' => __('PPPoE subscribers'),
                'icon' => 'home-modern',
                'summary' => __('Fixed lines, where you create the credentials rather than selling them.'),
            ],
            [
                'id' => 'operations',
                'title' => __('Keeping it running'),
                'icon' => 'clock',
                'summary' => __('The scheduled work everything depends on.'),
            ],
            [
                'id' => 'troubleshooting',
                'title' => __('When something is wrong'),
                'icon' => 'wrench-screwdriver',
                'summary' => __('Symptoms, what causes them, and what to do.'),
            ],
            [
                'id' => 'billing',
                'title' => __('What you pay us'),
                'icon' => 'receipt-percent',
                'summary' => __('The platform\'s own charges, and what happens if a bill lapses.'),
            ],
        ];
    }

    /**
     * The chapters matching what has been typed, so a long manual stays searchable.
     *
     * @return array<int, array{id: string, title: string, icon: string, summary: string}>
     */
    #[Computed]
    public function visibleChapters(): array
    {
        $needle = trim(mb_strtolower($this->search));

        if ($needle === '') {
            return $this->chapters;
        }

        return array_values(array_filter(
            $this->chapters,
            fn (array $chapter): bool => str_contains(mb_strtolower($chapter['title'].' '.$chapter['summary']), $needle),
        ));
    }

    #[Computed]
    public function current(): array
    {
        foreach ($this->chapters as $chapter) {
            if ($chapter['id'] === $this->section) {
                return $chapter;
            }
        }

        return $this->chapters[0];
    }

    public function show(string $section): void
    {
        $this->section = $section;
    }

    /**
     * A router of this ISP's own, so the worked examples name a real device rather than
     * "your-router". Nothing here is a secret: it is this operator's own equipment.
     */
    #[Computed]
    public function exampleRouter(): ?Router
    {
        return Tenancy::has() ? Router::query()->orderBy('id')->first() : null;
    }

    /**
     * @return Collection<int, Router>
     */
    #[Computed]
    public function routers(): Collection
    {
        return Tenancy::has() ? Router::query()->orderBy('name')->get() : collect();
    }
}; ?>

<div class="flex flex-col gap-6 p-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Documentation') }}</flux:heading>
            <flux:subheading>
                {{ __('How this platform works, and what to do when it does not.') }}
            </flux:subheading>
        </div>

        <flux:input
            wire:model.live.debounce.200ms="search"
            icon="magnifying-glass"
            placeholder="{{ __('Search the manual') }}"
            class="w-full sm:w-64"
            size="sm"
        />
    </div>

    <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
        {{-- Contents. Sticky on a wide screen, because the reader is usually comparing a
             chapter against the screen it describes and should not lose their place. --}}
        <nav class="lg:sticky lg:top-6 lg:w-72 lg:shrink-0">
            <flux:card class="p-2">
                @forelse ($this->visibleChapters as $chapter)
                    <button
                        type="button"
                        wire:key="chapter-{{ $chapter['id'] }}"
                        wire:click="show('{{ $chapter['id'] }}')"
                        @class([
                            'flex w-full items-start gap-3 rounded-lg px-3 py-2 text-start transition',
                            'bg-zinc-100 dark:bg-zinc-700' => $this->section === $chapter['id'],
                            'hover:bg-zinc-50 dark:hover:bg-zinc-700/50' => $this->section !== $chapter['id'],
                        ])
                    >
                        <flux:icon :icon="$chapter['icon']" variant="micro" class="mt-1 shrink-0 text-zinc-400" />

                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-zinc-800 dark:text-zinc-100">
                                {{ $chapter['title'] }}
                            </span>
                            <span class="block text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $chapter['summary'] }}
                            </span>
                        </span>
                    </button>
                @empty
                    <flux:text class="p-3">{{ __('Nothing in the manual matches that.') }}</flux:text>
                @endforelse
            </flux:card>
        </nav>

        <div class="min-w-0 flex-1">
            <flux:card class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ $this->current['title'] }}</flux:heading>
                    <flux:subheading>{{ $this->current['summary'] }}</flux:subheading>
                </div>

                <flux:separator />

                {{-- The id comes from current(), never from $section directly. $section is a
                     URL parameter, and a URL parameter interpolated into a view name is a path
                     the reader chooses — current() falls back to the first chapter for anything
                     not in the list, so only names that exist here can ever be reached. --}}
                @include('docs.sections.'.$this->current['id'])
            </flux:card>
        </div>
    </div>
</div>
