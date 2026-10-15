<?php

namespace App\Services\Billing;

use App\Models\ServiceAccount;
use App\Support\Tenancy\Facades\Tenancy;

/**
 * What a PPPoE subscriber is texted, in one place so every message reads the same way.
 *
 * Each starts with the ISP's name — the subscriber's relationship is with them, not with the
 * platform — and each that asks for money carries the link to pay it, because a reminder the
 * reader cannot act on from the message itself is one they put off.
 */
class BillingMessages
{
    public const BEFORE = 'pppoe.reminder_before';

    public const DUE = 'pppoe.reminder_due';

    public const SUSPENDED = 'pppoe.suspended';

    public const RENEWED = 'pppoe.renewed';

    public const MANUAL = 'pppoe.reminder_manual';

    public function before(ServiceAccount $account): string
    {
        return __(':isp: Hi :name, your internet (:plan) is due on :date. Pay KES :amount here to stay connected: :link', $this->facts($account));
    }

    public function due(ServiceAccount $account): string
    {
        return __(':isp: Hi :name, your internet (:plan) is due today. Pay KES :amount here to stay connected: :link', $this->facts($account));
    }

    public function suspended(ServiceAccount $account): string
    {
        return __(':isp: Hi :name, your internet has been paused because KES :amount was not received. Pay here and you will be reconnected straight away: :link', $this->facts($account));
    }

    public function renewed(ServiceAccount $account, string $amount, ?string $receipt): string
    {
        return __(':isp: Payment of KES :paid received:receipt. Your internet (:plan) is active until :date. Thank you.', array_merge($this->facts($account), [
            'paid' => number_format((float) $amount, 0),
            'receipt' => $receipt !== null ? ' ('.$receipt.')' : '',
        ]));
    }

    /**
     * Whichever is the right reminder for where this line stands, for a reminder sent by hand.
     */
    public function current(ServiceAccount $account): string
    {
        if ($account->suspended_for_nonpayment) {
            return $this->suspended($account);
        }

        return $account->daysLeft() === 0 ? $this->due($account) : $this->before($account);
    }

    /**
     * @return array<string, string>
     */
    protected function facts(ServiceAccount $account): array
    {
        $account->loadMissing(['plan', 'customer']);

        $name = trim((string) $account->customer?->name);

        return [
            'isp' => (string) (Tenancy::tenant()->name ?? config('app.name')),
            'name' => $name !== '' ? strtok($name, ' ') : __('there'),
            'plan' => (string) $account->plan?->name,
            'amount' => number_format((float) $account->plan?->price, 0),
            'date' => $account->expires_at?->format('j M Y') ?? __('today'),
            'link' => $account->payUrl(),
        ];
    }
}
