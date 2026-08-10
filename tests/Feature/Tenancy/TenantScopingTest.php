<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\CrossTenantAccessException;
use App\Exceptions\TenantContextMissingException;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AuditLog stands in for every tenant-owned model here: it is the first real user of the
 * BelongsToTenant trait, so proving isolation on it proves the mechanism itself.
 */
class TenantScopingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;

    protected Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
        $this->tenantB = Tenant::factory()->create(['name' => 'Tenant B']);

        Tenancy::withoutScope(function (): void {
            AuditLog::factory()->create(['tenant_id' => $this->tenantA->id, 'event' => 'a-one']);
            AuditLog::factory()->create(['tenant_id' => $this->tenantA->id, 'event' => 'a-two']);
            AuditLog::factory()->create(['tenant_id' => $this->tenantB->id, 'event' => 'b-one']);
        });
    }

    #[Test]
    public function it_returns_only_the_current_tenants_records(): void
    {
        Tenancy::set($this->tenantA);

        $events = AuditLog::query()->pluck('event')->all();

        $this->assertEqualsCanonicalizing(['a-one', 'a-two'], $events);
    }

    #[Test]
    public function it_hides_another_tenants_record_from_direct_lookup(): void
    {
        $foreignId = Tenancy::withoutScope(
            fn (): int => AuditLog::query()->where('event', 'b-one')->firstOrFail()->id,
        );

        Tenancy::set($this->tenantA);

        $this->assertNull(AuditLog::query()->find($foreignId));
    }

    #[Test]
    public function it_stamps_the_current_tenant_onto_new_records(): void
    {
        Tenancy::set($this->tenantB);

        $log = AuditLog::query()->create(['event' => 'b-two']);

        $this->assertSame($this->tenantB->id, $log->tenant_id);
    }

    #[Test]
    public function it_refuses_to_create_a_record_owned_by_another_tenant(): void
    {
        Tenancy::set($this->tenantA);

        $this->expectException(CrossTenantAccessException::class);

        AuditLog::query()->create([
            'tenant_id' => $this->tenantB->id,
            'event' => 'smuggled',
        ]);
    }

    #[Test]
    public function it_refuses_to_update_another_tenants_record(): void
    {
        $foreign = Tenancy::withoutScope(
            fn (): AuditLog => AuditLog::query()->where('event', 'b-one')->firstOrFail(),
        );

        Tenancy::set($this->tenantA);

        $this->expectException(CrossTenantAccessException::class);

        $foreign->update(['event' => 'tampered']);
    }

    #[Test]
    public function it_refuses_to_delete_another_tenants_record(): void
    {
        $foreign = Tenancy::withoutScope(
            fn (): AuditLog => AuditLog::query()->where('event', 'b-one')->firstOrFail(),
        );

        Tenancy::set($this->tenantA);

        $this->expectException(CrossTenantAccessException::class);

        $foreign->delete();
    }

    #[Test]
    public function it_refuses_to_hand_back_a_foreign_record_even_without_the_global_scope(): void
    {
        Tenancy::set($this->tenantA);

        $this->expectException(CrossTenantAccessException::class);

        AuditLog::query()->withoutGlobalScopes()->where('event', 'b-one')->first();
    }

    #[Test]
    public function it_throws_when_querying_with_no_tenant_resolved(): void
    {
        $this->assertFalse(Tenancy::has());

        $this->expectException(TenantContextMissingException::class);

        AuditLog::query()->get();
    }

    #[Test]
    public function it_throws_when_creating_with_no_tenant_resolved(): void
    {
        $this->expectException(TenantContextMissingException::class);

        AuditLog::query()->create(['event' => 'orphan']);
    }

    #[Test]
    public function it_spans_every_tenant_only_when_asked_explicitly(): void
    {
        $events = Tenancy::withoutScope(
            fn (): array => AuditLog::query()->pluck('event')->all(),
        );

        $this->assertEqualsCanonicalizing(['a-one', 'a-two', 'b-one'], $events);
    }

    #[Test]
    public function it_restores_the_previous_tenant_after_acting_as_another(): void
    {
        Tenancy::set($this->tenantA);

        $events = Tenancy::forTenant(
            $this->tenantB,
            fn (): array => AuditLog::query()->pluck('event')->all(),
        );

        $this->assertSame(['b-one'], $events);
        $this->assertSame($this->tenantA->id, Tenancy::id());
    }

    #[Test]
    public function acting_as_a_tenant_re_enables_scoping_inside_a_bypass(): void
    {
        $events = Tenancy::withoutScope(
            fn (): array => Tenancy::forTenant(
                $this->tenantB,
                fn (): array => AuditLog::query()->pluck('event')->all(),
            ),
        );

        $this->assertSame(['b-one'], $events);
        $this->assertTrue(Tenancy::isBypassed() === false);
    }

    #[Test]
    public function it_restores_the_bypass_state_when_the_callback_throws(): void
    {
        Tenancy::set($this->tenantA);

        try {
            Tenancy::withoutScope(function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertFalse(Tenancy::isBypassed());
        $this->assertTrue(Tenancy::shouldScope());
    }
}
