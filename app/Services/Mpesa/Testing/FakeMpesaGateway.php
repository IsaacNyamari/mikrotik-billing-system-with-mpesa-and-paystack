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

    /**
     * The configuration each push was signed with, in order.
     *
     * @var array<int, MpesaConfiguration>
     */
    public array $configurations = [];

    protected ?string $rejectWith = null;

    /** @var array<string, mixed>|null */
    protected ?array $queryAnswer = null;

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

        // Kept so a test can ask *which* Daraja app signed this: the platform now signs on
        // behalf of ISPs who have only a till, and getting that wrong sends somebody else's
        // money to the wrong number.
        $this->configurations[] = $configuration;

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
     * What Safaricom says when asked about a prompt.
     *
     * Defaults to what a real query returns while the customer is still deciding — an error
     * rather than a status. Answering "paid" by default would quietly settle every payment in
     * every test that happens to poll.
     *
     * @return array<string, mixed>
     */
    public function query(MpesaConfiguration $configuration, string $checkoutRequestId): array
    {
        return $this->queryAnswer ?? [
            'errorCode' => '500.001.1001',
            'errorMessage' => 'The transaction is being processed',
        ];
    }

    /**
     * Make the next query report an outcome.
     *
     * @param  array<string, mixed>|null  $answer
     */
    public function answerQueryWith(?array $answer): void
    {
        $this->queryAnswer = $answer;
    }

    /**
     * As Safaricom reports a completed prompt: 0 for paid, 1032 for a customer who cancelled.
     */
    public function answerQueryWithCode(int $code, string $description = 'The service request is processed successfully.'): void
    {
        $this->answerQueryWith(['ResultCode' => (string) $code, 'ResultDesc' => $description]);
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

    /**
     * Which Daraja app signed the last push, and who it named as the payee.
     */
    public function lastConfiguration(): ?MpesaConfiguration
    {
        return $this->configurations[count($this->configurations) - 1] ?? null;
    }
}
