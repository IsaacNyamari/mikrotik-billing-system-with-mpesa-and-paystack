<?php

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Lets a platform super admin work inside one ISP.
 *
 * Super admins deliberately have no tenant of their own, so until they pick one there is
 * nothing for a tenant-scoped screen to show. The choice is kept in the session and read back
 * by ResolveTenant on every request.
 */
new #[Title('Choose an ISP')] class extends Component {
    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        // Only a super admin has an ISP to choose; everyone else already belongs to one.
        abort_unless(Auth::user()?->isSuperAdmin(), 403);
    }

    /**
     * @return Collection<int, Tenant>
     */
    #[Computed]
    public function tenants(): Collection
    {
        // Tenants are not themselves tenant-scoped, but the counts underneath are, so each
        // one is gathered as that ISP.
        return Tenant::query()
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function currentTenantId(): ?int
    {
        return Session::get(ResolveTenant::IMPERSONATION_KEY);
    }

    public function choose(int $tenantId): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);

        $tenant = Tenant::query()->findOrFail($tenantId);

        Session::put(ResolveTenant::IMPERSONATION_KEY, $tenant->id);

        $this->redirectRoute('dashboard', navigate: true);
    }

    /**
     * Step back out to the platform-wide view.
     */
    public function clear(): void
    {
        Session::forget(ResolveTenant::IMPERSONATION_KEY);
        Tenancy::forget();

        unset($this->currentTenantId);
    }
}; ?>

<section class="w-full">
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6">
        <div>
            <flux:heading size="xl">{{ __('Choose an ISP') }}</flux:heading>
            <flux:subheading>
                {{ __('You are a platform administrator, so you belong to no single ISP. Pick one to work inside it.') }}
            </flux:subheading>
        </div>

        @if ($this->tenants->count() > 8)
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search ISPs')" />
        @endif

        <div class="flex flex-col gap-3">
            @forelse ($this->tenants as $tenant)
                @php($current = $this->currentTenantId === $tenant->id)

                <flux:card
                    wire:key="tenant-{{ $tenant->id }}"
                    @class(['flex items-center justify-between gap-4', 'ring-2 ring-blue-600/30' => $current])
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ $tenant->name }}</flux:heading>
                            <flux:badge size="sm" :color="$tenant->status->isOperational() ? 'green' : 'orange'">
                                {{ $tenant->status->label() }}
                            </flux:badge>
                        </div>
                        <flux:text size="sm" class="opacity-60">
                            {{ $tenant->contact_email ?? $tenant->slug }}
                        </flux:text>
                    </div>

                    @if ($current)
                        <flux:button size="sm" variant="ghost" wire:click="clear">{{ __('Leave') }}</flux:button>
                    @else
                        <flux:button size="sm" variant="primary" wire:click="choose({{ $tenant->id }})">
                            {{ __('Work in this ISP') }}
                        </flux:button>
                    @endif
                </flux:card>
            @empty
                {{-- The first thing a freshly deployed platform shows its own administrator,
                     so it says how an ISP comes to exist rather than only that none does. --}}
                <flux:card class="flex flex-col items-center gap-3 py-10 text-center">
                    <flux:icon.building-office-2 class="size-8 text-zinc-400" />

                    <div>
                        <flux:heading size="lg">{{ __('No ISPs exist yet') }}</flux:heading>
                        <flux:subheading>
                            {{ __('There is no application to approve: an ISP signs up and starts selling. What gates them is the subscription, not permission.') }}
                        </flux:subheading>
                    </div>

                    <flux:text class="text-sm">
                        {{ __('Send them here to open an account:') }}
                        <a href="{{ route('register') }}" class="font-mono underline" rel="noopener">{{ route('register') }}</a>
                    </flux:text>
                </flux:card>
            @endforelse
        </div>
    </div>
</section>
