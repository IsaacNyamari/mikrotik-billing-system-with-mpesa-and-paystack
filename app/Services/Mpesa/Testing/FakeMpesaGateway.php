<?php

namespace App\Services\Mpesa\Testing;

use App\Models\MpesaConfiguration;
use App\Services\Mpesa\Contracts\MpesaGateway;
use App\Services\Mpesa\StkPushResult;
use Illuminate\Support\Str;

/**
 * Stands in for Safaricom.
 *
 * Records the prompts that were sent and can be told to refuse the next one, so the payment
 * flow can be exercised without a sandbox account.
 */
class FakeMpesaGateway implements MpesaGateway
{
    /**
     * @var array<int, array{phone: string, amount: int, reference: string, callback: string}>
     */
    public array $pushes = [];

    protected ?string $rejectWith = null;

    public function stkPush(
        MpesaConfiguration $configuration,
        string $phone,
        int $amount,
        string $accountReference,
        string $description,
        string $callbackUrl,
    ): StkPushResult {
        $this->pushes[] = [
            'phone' => $phone,
            'amount' => $amount,
            'reference' => $accountReference,
            'callback' => $callbackUrl,
        ];

        if ($this->rejectWith !== null) {
            $message = $this->rejectWith;
            $this->rejectWith = null;

            return StkPushResult::rejected($message);
        }

        return StkPushResult::accepted(
            'ws_CO_'.Str::upper(Str::random(20)),
            Str::upper(Str::random(12)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function query(MpesaConfiguration $configuration, string $checkoutRequestId): array
    {
        return ['ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.'];
    }

    /**
     * Make the next prompt fail, as Safaricom does for an invalid number or a dead till.
     */
    public function rejectNext(string $message = 'Unable to lock subscriber, a transaction is already in process'): void
    {
        $this->rejectWith = $message;
    }

    /**
     * @return array{phone: string, amount: int, reference: string, callback: string}|null
     */
    public function lastPush(): ?array
    {
        return $this->pushes[count($this->pushes) - 1] ?? null;
    }
}
