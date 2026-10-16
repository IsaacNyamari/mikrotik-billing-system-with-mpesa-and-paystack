<?php

use App\Enums\ServiceAccountStatus;
use App\Enums\ServiceType;
use App\Http\Middleware\ResolveTenant;
use App\Models\Router;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The platform's list of ISPs, and the one place an administrator opens an ISP from.
 *
 * Opening one is impersonation: from then on every screen is that ISP's, exactly as its owner
 * sees it, until the banner's Exit is pressed. It is recorded on the audit trail at both ends,
 * because it is a person outside the ISP looking at the ISP's customers.
 */
new #[Title('ISPs')] class extends Component {
    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);
    }

    /**
     * @return Collection<int, Tenant>
     */
    #[Computed]
    public function tenants(): Collection
    {
        return Tenant::query()
            ->with('subscription')
            ->when($this->search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('contact_email', 'like', '%'.$this->search.'%')
                ->orWhere('contact_phone', 'like', '%'.$this->search.'%')))
            ->orderBy('name')
            ->get();
    }

    /**
     * Counts per ISP, gathered across every ISP in two queries rather than two per row.
     *
     * @return array{routers: array<int, int>, online: array<int, int>, pppoe: array<int, int>}
     */
    #[Computed]
    public function counts(): array
    {
        return Tenancy::withoutScope(fn (): array => [
            'routers' => Router::query()->withoutGlobalScopes()->select('tenant_id', DB::raw('count(*) as n'))->groupBy('tenant_id')->pluck('n', 'tenant_id')->map(fn ($n) => (int) $n)->all(),
            'online' => Router::query()->withoutGlobalScopes()->where('status', 'online')->select('tenant_id', DB::raw('count(*) as n'))->groupBy('tenant_id')->pluck('n', 'tenant_id')->map(fn ($n) => (int) $n)->all(),
            'pppoe' => ServiceAccount::query()->withoutGlobalScopes()->where('type', ServiceType::Pppoe)->where('status', ServiceAccountStatus::Active)->select('tenant_id', DB::raw('count(*) as n'))->groupBy('tenant_id')->pluck('n', 'tenant_id')->map(fn ($n) => (int) $n)->all(),
        ]);
    }

    public function choose(int $tenantId, AuditLogger $audit): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);

        $tenant = Tenant::query()->findOrFail($tenantId);

        Session::put(ResolveTenant::IMPERSONATION_KEY, $tenant->id);

        // Recorded as the ISP, so it appears on that ISP's own trail.
        Tenancy::forTenant($tenant, fn () => $audit->record(
            event: 'platform.impersonation_started',
            auditable: $tenant,
            description: sprintf('%s opened %s as a platform administrator', Auth::user()?->email, $tenant->name),
        ));

        $this->redirectRoute('dashboard', navigate: true);
    }

    /**
     * Step back out to the platform's own view (the banner's Exit does the same over POST).
     */
    public function clear(): void
    {
        Session::forget(ResolveTenant::IMPERSONATION_KEY);
        Tenancy::forget();

        unset($this->currentTenantId);
    }

    /**
     * The ISP currently open, if any.
     */
    #[Computed]
    public function currentTenantId(): ?int
    {
        $id = Session::get(ResolveTenant::IMPERSONATION_KEY);

        return is_numeric($id) ? (int) $id : null;
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6 p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('ISPs') }}</flux:heading>
                <flux:subheading>{{ __('Every ISP on the platform. Opening one shows you exactly what its owner sees, until you exit; both are recorded on its audit trail.') }}</flux:subheading>
            </div>
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search name, email or phone')" class="sm:max-w-xs" />
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('ISP') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Subscription') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Routers') }}</flux:table.column>
                <flux:table.column align="end">{{ __('PPPoE lines') }}</flux:table.column>
                <flux:table.column>{{ __('Joined') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->tenants as $tenant)
                    @php($routers = $this->counts['routers'][$tenant->id] ?? 0)
                    @php($online = $this->counts['online'][$tenant->id] ?? 0)
                    <flux:table.row :key="$tenant->id">
                        <flux:table.cell>
                            <span class="font-semibold text-zinc-900 dark:text-white">{{ $tenant->name }}</span>
                            <flux:text size="sm" class="opacity-70">{{ $tenant->contact_email ?? $tenant->slug }}{{ $tenant->contact_phone ? ' · '.$tenant->contact_phone : '' }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" inset="top bottom" :color="$tenant->status->isOperational() ? 'green' : 'orange'">{{ $tenant->status->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($tenant->subscription)
                                <flux:badge size="sm" inset="top bottom" :color="$tenant->subscription->status->color()">{{ $tenant->subscription->status->label() }}</flux:badge>
                            @else
                                <flux:text size="sm">—</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            {{ $routers }}
                            @if ($routers > 0 && $online < $routers)
                                <flux:text size="sm" class="text-[#d03b3b]">{{ __(':n down', ['n' => $routers - $online]) }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ $this->counts['pppoe'][$tenant->id] ?? 0 }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $tenant->created_at?->format('j M Y') }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" icon="arrow-right-end-on-rectangle" wire:click="choose({{ $tenant->id }})">{{ __('Open as this ISP') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7" class="py-10 text-center">
                            <flux:heading>{{ $search === '' ? __('No ISPs exist yet') : __('No ISP matches that search') }}</flux:heading>
                            @if ($search === '')
                                <flux:text class="mt-1">
                                    {{ __('An ISP signs up and starts selling; there is nothing to approve. Send them to') }}
                                    <a href="{{ route('register') }}" class="font-mono underline">{{ route('register') }}</a>
                                </flux:text>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
