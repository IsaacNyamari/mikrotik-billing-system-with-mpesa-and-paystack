<?php

use App\Enums\PlanType;
use App\Enums\ValidityUnit;
use App\Models\Plan;
use App\Services\RouterOs\PlanProfileSynchroniser;
use App\Support\Audit\AuditLogger;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Plan')] class extends Component {
    public ?Plan $plan = null;

    public string $type = 'hotspot';
    public string $name = '';
    public string $code = '';
    public string $description = '';
    public string $price = '0';
    public int $validity_amount = 1;
    public string $validity_unit = 'hours';
    public ?int $download_kbps = null;
    public ?int $upload_kbps = null;
    public ?int $data_cap_mb = null;
    public int $shared_users = 1;
    public ?int $idle_timeout_minutes = null;
    public bool $is_active = true;
    public bool $is_public = true;
    public bool $push_after_save = true;

    public function mount(?Plan $plan = null): void
    {
        $this->plan = $plan?->exists ? $plan : null;

        if ($this->plan === null) {
            Gate::authorize('create', Plan::class);

            $this->type = in_array(request()->query('type'), ['hotspot', 'pppoe'], true)
                ? request()->query('type')
                : 'hotspot';

            // A month is the natural unit for a PPPoE line, an hour for a HotSpot voucher.
            $this->validity_unit = $this->type === 'pppoe' ? 'months' : 'hours';

            return;
        }

        Gate::authorize('update', $this->plan);

        $this->fill([
            'type' => $this->plan->type->value,
            'name' => $this->plan->name,
            'code' => $this->plan->code,
            'description' => (string) $this->plan->description,
            'price' => (string) $this->plan->price,
            'validity_amount' => $this->plan->validity_amount,
            'validity_unit' => $this->plan->validity_unit->value,
            'download_kbps' => $this->plan->download_kbps,
            'upload_kbps' => $this->plan->upload_kbps,
            'data_cap_mb' => $this->plan->data_cap_mb,
            'shared_users' => $this->plan->shared_users,
            'idle_timeout_minutes' => $this->plan->idle_timeout_minutes,
            'is_active' => $this->plan->is_active,
            'is_public' => $this->plan->is_public,
        ]);
    }

    public function updatedName(string $value): void
    {
        if ($this->plan === null && $this->code === '') {
            $this->code = Str::slug($value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(PlanType::class)],
            'name' => ['required', 'string', 'max:255'],
            // The code becomes the RouterOS profile name, so it has to survive being written
            // into a device configuration.
            'code' => [
                'required',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                Rule::unique('plans', 'code')
                    ->where('tenant_id', $this->plan?->tenant_id ?? \App\Support\Tenancy\Facades\Tenancy::id())
                    ->ignore($this->plan?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'validity_amount' => ['required', 'integer', 'min:1'],
            'validity_unit' => ['required', Rule::enum(ValidityUnit::class)],
            'download_kbps' => ['nullable', 'integer', 'min:32'],
            'upload_kbps' => ['nullable', 'integer', 'min:32'],
            'data_cap_mb' => ['nullable', 'integer', 'min:1'],
            'shared_users' => ['required', 'integer', 'min:1', 'max:255'],
            'idle_timeout_minutes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'code.regex' => __('Use only letters, numbers, dots, dashes and underscores — this becomes the profile name on the router.'),
        ];
    }

    public function save(PlanProfileSynchroniser $sync, AuditLogger $audit): void
    {
        $validated = $this->validate();

        $plan = $this->plan ?? new Plan;
        $isNew = $this->plan === null;

        Gate::authorize($isNew ? 'create' : 'update', $isNew ? Plan::class : $plan);

        $plan->fill($validated);
        $plan->save();

        $audit->record(
            event: $isNew ? 'plan.created' : 'plan.updated',
            auditable: $plan,
            description: sprintf('%s plan %s', $isNew ? 'Created' : 'Updated', $plan->name),
        );

        Flux::toast(variant: 'success', text: __('Plan saved.'));

        if ($this->push_after_save && Gate::allows('push', $plan)) {
            $results = $sync->syncToAllRouters($plan);
            $failed = collect($results)->reject->isSynced();

            if ($failed->isNotEmpty()) {
                Flux::toast(variant: 'warning', text: __('Saved, but pushing to a router failed: :error', ['error' => $failed->first()->sync_error]));
            }
        }

        $this->redirectRoute('plans.index', ['type' => $plan->type->value], navigate: true);
    }
}; ?>

<section class="w-full">
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-6">
        <div>
            <flux:heading size="xl">
                {{ $plan === null ? __('New plan') : __('Edit :name', ['name' => $plan->name]) }}
            </flux:heading>
            <flux:subheading>
                {{ __('Speed and device limits are written to the router profile. Time and data allowance are applied to each customer when they buy.') }}
            </flux:subheading>
        </div>

        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:radio.group wire:model.live="type" :label="__('Plan type')" variant="segmented" :disabled="$plan !== null">
                @foreach (PlanType::cases() as $case)
                    <flux:radio value="{{ $case->value }}" label="{{ $case->label() }}" />
                @endforeach
            </flux:radio.group>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model.live.debounce="name" :label="__('Name')" required autofocus :placeholder="__('1 Hour')" />
                <flux:input
                    wire:model="code"
                    :label="__('Profile name on router')"
                    required
                    :description="__('Becomes the RouterOS profile name.')"
                />
            </div>

            <flux:input wire:model="description" :label="__('Description')" :description="__('Shown to customers on the portal.')" />

            <div class="grid gap-6 sm:grid-cols-3">
                <flux:input wire:model="price" :label="__('Price')" required type="number" step="0.01" min="0" />
                <flux:input wire:model="validity_amount" :label="__('Validity')" required type="number" min="1" />
                <flux:select wire:model="validity_unit" :label="__('Unit')">
                    @foreach (ValidityUnit::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:separator :text="__('Network limits')" />

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input
                    wire:model="download_kbps"
                    type="number"
                    :label="__('Download (kbps)')"
                    :placeholder="__('Blank for unshaped')"
                />
                <flux:input
                    wire:model="upload_kbps"
                    type="number"
                    :label="__('Upload (kbps)')"
                    :placeholder="__('Blank for unshaped')"
                />
                <flux:input
                    wire:model="data_cap_mb"
                    type="number"
                    :label="__('Data allowance (MB)')"
                    :placeholder="__('Blank for uncapped')"
                />
                <flux:input
                    wire:model="shared_users"
                    type="number"
                    min="1"
                    :label="__('Devices per login')"
                    :description="$type === 'pppoe' ? __('More than one allows simultaneous PPPoE sessions.') : __('How many devices may share one voucher.')"
                />
            </div>

            @if ($type === 'hotspot')
                <flux:input
                    wire:model="idle_timeout_minutes"
                    type="number"
                    :label="__('Idle timeout (minutes)')"
                    :description="__('Log a customer out after this long with no traffic. Blank for never.')"
                />
            @endif

            <flux:separator :text="__('Availability')" />

            <flux:switch wire:model="is_active" :label="__('Active')" :description="__('Inactive plans are not sold or pushed.')" />
            <flux:switch wire:model="is_public" :label="__('Sell on the captive portal')" :description="__('Turn off for plans an operator assigns by hand.')" />
            <flux:switch wire:model="push_after_save" :label="__('Push to routers after saving')" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit" data-test="save-plan-button">
                    {{ __('Save') }}
                </flux:button>

                <flux:button variant="ghost" :href="route('plans.index', ['type' => $type])" wire:navigate>
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </form>
    </div>
</section>
