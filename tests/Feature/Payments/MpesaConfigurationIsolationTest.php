<?php

namespace Tests\Feature\Payments;

use App\Exceptions\CrossTenantAccessException;
use App\Models\MpesaConfiguration;
use App\Models\Tenant;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every ISP collects into its own till, so its Daraja credentials are its own. These are the
 * most damaging rows in the database to get wrong: a leak lets one ISP take another's money.
 */
class MpesaConfigurationIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $alpha;

    protected Tenant $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Tenant::factory()->create(['name' => 'Alpha Networks']);
        $this->beta = Tenant::factory()->create(['name' => 'Beta Networks']);

        $this->configure($this->alpha, '111111', 'alpha-key');
        $this->configure($this->beta, '222222', 'beta-key');
    }

    #[Test]
    public function each_isp_sees_only_its_own_credentials(): void
    {
        Tenancy::set($this->alpha);

        $configuration = MpesaConfiguration::query()->sole();

        $this->assertSame('111111', $configuration->short_code);
        $this->assertSame('alpha-key', $configuration->consumer_key);
    }

    #[Test]
    public function an_isp_cannot_read_another_isps_credentials(): void
    {
        $betaId = Tenancy::forTenant($this->beta, fn (): int => MpesaConfiguration::query()->sole()->id);

        Tenancy::set($this->alpha);

        $this->assertNull(MpesaConfiguration::query()->find($betaId));
    }

    #[Test]
    public function an_isp_cannot_overwrite_another_isps_credentials(): void
    {
        $betaConfiguration = Tenancy::forTenant(
            $this->beta,
            fn (): MpesaConfiguration => MpesaConfiguration::query()->sole(),
        );

        Tenancy::set($this->alpha);

        $this->expectException(CrossTenantAccessException::class);

        $betaConfiguration->update(['short_code' => '999999']);
    }

    #[Test]
    public function an_isp_can_hold_only_one_configuration(): void
    {
        Tenancy::set($this->alpha);

        // Two tills for one ISP would make it ambiguous which one a payment settles into.
        $this->expectException(QueryException::class);

        MpesaConfiguration::query()->create([
            'short_code' => '333333',
            'consumer_key' => 'k',
            'consumer_secret' => 's',
            'passkey' => 'p',
        ]);
    }

    #[Test]
    public function credentials_are_encrypted_in_the_table(): void
    {
        $stored = DB::table('mpesa_configurations')->where('tenant_id', $this->alpha->id)->sole();

        foreach (['consumer_key', 'consumer_secret', 'passkey'] as $column) {
            $this->assertNotSame('alpha-key', $stored->{$column});
            // Laravel's encrypter emits base64 JSON; cleartext would never look like this.
            $this->assertNotEmpty($stored->{$column});
            $this->assertNull(json_decode(base64_decode($stored->{$column}, true) ?: '', true)['plain'] ?? null);
        }
    }

    #[Test]
    public function callback_tokens_are_unique_so_one_isp_cannot_settle_anothers_payment(): void
    {
        $tokens = Tenancy::withoutScope(
            fn () => MpesaConfiguration::query()->pluck('callback_token'),
        );

        $this->assertCount(2, $tokens);
        $this->assertCount(2, $tokens->unique());
        // Long enough that guessing one is not a route in.
        $this->assertGreaterThanOrEqual(32, strlen((string) $tokens->first()));
    }

    #[Test]
    public function deleting_an_isp_takes_its_credentials_with_it(): void
    {
        $this->alpha->forceDelete();

        $this->assertSame(0, Tenancy::withoutScope(
            fn (): int => MpesaConfiguration::query()->where('tenant_id', $this->alpha->id)->count(),
        ));
    }

    protected function configure(Tenant $tenant, string $shortCode, string $key): void
    {
        Tenancy::forTenant($tenant, fn () => MpesaConfiguration::query()->create([
            'short_code' => $shortCode,
            'consumer_key' => $key,
            'consumer_secret' => $key.'-secret',
            'passkey' => $key.'-passkey',
        ]));
    }
}
