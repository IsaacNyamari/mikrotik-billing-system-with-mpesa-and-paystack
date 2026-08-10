<?php

use App\Models\Router;
use App\Services\RouterOs\RouterHealthProbe;
use App\Support\Audit\AuditLogger;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Router')] class extends Component {
    public ?Router $router = null;

    public string $name = '';
    public string $host = '';
    public int $api_port = 8728;
    public string $api_username = '';
    public string $api_password = '';
    public bool $uses_tls = false;
    public string $location = '';
    public string $notes = '';

    public function mount(?Router $router = null): void
    {
        $this->router = $router?->exists ? $router : null;

        if ($this->router === null) {
            Gate::authorize('create', Router::class);

            return;
        }

        Gate::authorize('update', $this->router);

        $this->fill([
            'name' => $this->router->name,
            'host' => $this->router->host,
            'api_port' => $this->router->api_port,
            'api_username' => $this->router->api_username,
            'uses_tls' => $this->router->uses_tls,
            'location' => (string) $this->router->location,
            'notes' => (string) $this->router->notes,
        ]);

        // The stored password is never sent to the browser. Leaving the field blank on an
        // edit keeps whatever is already saved.
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'host' => ['required', 'string', 'max:255'],
            'api_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'api_username' => ['required', 'string', 'max:255'],
            'api_password' => [$this->router === null ? 'required' : 'nullable', 'string', 'max:255'],
            'uses_tls' => ['boolean'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function save(RouterHealthProbe $probe, AuditLogger $audit): void
    {
        $validated = $this->validate();

        $router = $this->router ?? new Router;
        $isNew = $this->router === null;

        Gate::authorize($isNew ? 'create' : 'update', $isNew ? Router::class : $router);

        $router->fill([
            'name' => $validated['name'],
            'host' => $validated['host'],
            'api_port' => $validated['api_port'],
            'api_username' => $validated['api_username'],
            'uses_tls' => $validated['uses_tls'],
            'location' => $validated['location'] !== '' ? $validated['location'] : null,
            'notes' => $validated['notes'] !== '' ? $validated['notes'] : null,
        ]);

        if ($validated['api_password'] !== '' && $validated['api_password'] !== null) {
            $router->api_password = $validated['api_password'];
        }

        if ($isNew) {
            $router->slug = $this->uniqueSlug($validated['name']);
        }

        $router->save();

        $audit->record(
            event: $isNew ? 'router.created' : 'router.updated',
            auditable: $router,
            description: sprintf('%s router %s', $isNew ? 'Added' : 'Updated', $router->name),
        );

        // Contact the device straight away: an operator who has just typed credentials wants
        // to know there and then whether they work.
        $probe->probe($router);

        $router->status->isReachable()
            ? Flux::toast(variant: 'success', text: __('Saved and connected to :name.', ['name' => $router->identity ?? $router->name]))
            : Flux::toast(variant: 'warning', text: __('Saved, but the router could not be reached: :error', ['error' => $router->last_error]));

        $this->redirectRoute('routers.show', $router, navigate: true);
    }

    /**
     * Slugs are unique per ISP, so a second "Nairobi 01" gets a suffix rather than an error.
     */
    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Router::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}; ?>

<section class="w-full">
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6">
        <div>
            <flux:heading size="xl">
                {{ $router === null ? __('Add a router') : __('Edit :name', ['name' => $router->name]) }}
            </flux:heading>
            <flux:subheading>
                {{ __('The platform connects over the RouterOS API to read the device and provision users onto it.') }}
            </flux:subheading>
        </div>

        @if ($router === null)
            <flux:callout icon="information-circle">
                <flux:callout.heading>{{ __('On the MikroTik first') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Enable the API service under /ip/service, and create a dedicated user for this platform rather than reusing admin. The user needs the api, read, write and policy permissions.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:input wire:model="name" :label="__('Name')" required autofocus :placeholder="__('Nairobi 01')" />

            <div class="grid gap-6 sm:grid-cols-3">
                <flux:input
                    wire:model="host"
                    class="sm:col-span-2"
                    :label="__('Address')"
                    required
                    :description="__('IP or hostname the platform can reach.')"
                />
                <flux:input wire:model="api_port" type="number" :label="__('API port')" required />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="api_username" :label="__('API username')" required autocomplete="off" />
                <flux:input
                    wire:model="api_password"
                    type="password"
                    :label="__('API password')"
                    :required="$router === null"
                    autocomplete="new-password"
                    :description="$router === null ? null : __('Leave blank to keep the stored password.')"
                />
            </div>

            <flux:switch
                wire:model="uses_tls"
                :label="__('Use TLS')"
                :description="__('Connect on the encrypted API port. The router needs api-ssl enabled with a certificate.')"
            />

            <flux:input wire:model="location" :label="__('Location')" :description="__('Optional, e.g. “Kilimani, Block C rooftop”.')" />
            <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit" data-test="save-router-button">
                    <span wire:loading.remove wire:target="save">{{ __('Save and test connection') }}</span>
                    <span wire:loading wire:target="save">{{ __('Connecting…') }}</span>
                </flux:button>

                <flux:button
                    variant="ghost"
                    :href="$router === null ? route('routers.index') : route('routers.show', $router)"
                    wire:navigate
                >
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </form>
    </div>
</section>
