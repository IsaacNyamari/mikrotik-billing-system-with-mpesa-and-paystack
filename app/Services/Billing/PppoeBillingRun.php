<?php

namespace App\Services\Billing;

use App\Actions\ServiceAccounts\ChangeServiceAccountStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceAccountStatus;
use App\Enums\ServiceType;
use App\Models\Payment;
use App\Models\ServiceAccount;
use App\Models\SmsMessage;
use App\Models\SmsSetting;
use App\Services\Sms\SmsSender;
use Carbon\CarbonImmutable;

/**
 * One pass over an ISP's PPPoE lines: remind, cut, and catch up on renewals.
 *
 * Runs every few minutes, so each step is written to be harmless when repeated. A reminder is
 * remembered against the expiry date it was for — a new paid period has a new date, so each
 * goes once per cycle. Texts wait for the day (08:00–20:00 Nairobi time); cutting a line does
 * not, because a line that is not paid for should not keep working overnight.
 *
 * Runs inside `Tenancy::forTenant()` — everything here reads the current ISP.
 *
 * @phpstan-type Tally array{invoiced: int, before: int, due: int, suspended: int, renewed: int}
 */
class PppoeBillingRun
{
    public function __construct(
        protected SmsSender $sms,
        protected BillingMessages $messages,
        protected ChangeServiceAccountStatus $status,
        protected ServiceAccountRenewal $renewal,
        protected CustomerInvoices $invoices,
    ) {}

    /**
     * @return Tally
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $tally = ['invoiced' => 0, 'before' => 0, 'due' => 0, 'suspended' => 0, 'renewed' => 0];

        $tally['renewed'] = $this->catchUpRenewals();
        $this->invoices->markOverdue();

        $daysBefore = max(1, SmsSetting::current()->remind_days_before);
        $daytime = $this->isDaytime($now);

        ServiceAccount::query()
            ->with(['plan', 'customer', 'router'])
            ->where('type', ServiceType::Pppoe)
            ->whereNotNull('plan_id')
            ->whereNotNull('router_id')
            ->whereNotNull('expires_at')
            ->whereIn('status', [ServiceAccountStatus::Active, ServiceAccountStatus::Suspended])
            // Far-future lines have nothing to do yet.
            ->where('expires_at', '<=', $now->addDays($daysBefore + 1))
            ->orderBy('id')
            ->each(function (ServiceAccount $account) use ($now, $daysBefore, $daytime, &$tally): void {
                // The bill first, so every reminder below has an invoice to point at.
                if ($this->invoices->raiseFor($account)?->wasRecentlyCreated) {
                    $tally['invoiced']++;
                }

                if ($account->status === ServiceAccountStatus::Suspended) {
                    // Only lines this run cut are its business; one an operator suspended is not.
                    if ($account->suspended_for_nonpayment && $daytime && ! $this->toldOfSuspension($account)) {
                        $this->text($account, $this->messages->suspended($account), BillingMessages::SUSPENDED);
                    }

                    return;
                }

                if ($account->expires_at !== null && $account->expires_at->lessThanOrEqualTo($now)) {
                    $this->suspend($account);
                    $tally['suspended']++;

                    if ($daytime) {
                        $this->text($account, $this->messages->suspended($account), BillingMessages::SUSPENDED);
                    }

                    return;
                }

                if (! $daytime || $account->expires_at === null) {
                    return;
                }

                $dueDate = $account->expires_at->toDateString();
                $daysLeft = (int) $now->startOfDay()->diffInDays($account->expires_at->startOfDay(), false);

                if ($daysLeft === 0 && $account->reminded_due_for?->toDateString() !== $dueDate) {
                    $this->text($account, $this->messages->due($account), BillingMessages::DUE);
                    $account->reminded_due_for = $account->expires_at;
                    $account->save();
                    $tally['due']++;

                    return;
                }

                if ($daysLeft > 0 && $daysLeft <= $daysBefore && $account->reminded_before_for?->toDateString() !== $dueDate) {
                    $this->text($account, $this->messages->before($account), BillingMessages::BEFORE);
                    $account->reminded_before_for = $account->expires_at;
                    $account->save();
                    $tally['before']++;
                }
            });

        return $tally;
    }

    /**
     * Renewals that were paid but never applied — the line was not reachable, or the process
     * that settled the money stopped before it got this far.
     */
    protected function catchUpRenewals(): int
    {
        $count = 0;

        Payment::query()
            ->whereNotNull('service_account_id')
            ->whereNull('applied_at')
            ->where('status', PaymentStatus::Paid)
            ->orderBy('id')
            ->each(function (Payment $payment) use (&$count): void {
                if ($this->renewal->renew($payment)->applied_at !== null) {
                    $count++;
                }
            });

        return $count;
    }

    protected function suspend(ServiceAccount $account): void
    {
        $account->suspended_for_nonpayment = true;

        $this->status->handle($account, ServiceAccountStatus::Suspended, sprintf(
            'Cut %s: paid until %s and not renewed',
            $account->account_number,
            $account->expires_at?->toDateTimeString(),
        ));
    }

    protected function toldOfSuspension(ServiceAccount $account): bool
    {
        return SmsMessage::query()
            ->where('service_account_id', $account->id)
            ->where('kind', BillingMessages::SUSPENDED)
            ->when($account->suspended_at !== null, fn ($query) => $query->where('created_at', '>=', $account->suspended_at))
            ->exists();
    }

    protected function text(ServiceAccount $account, string $body, string $kind): void
    {
        $phone = (string) $account->customer?->phone;

        if ($phone !== '') {
            $this->sms->send($phone, $body, $kind, $account);
        }
    }

    protected function isDaytime(CarbonImmutable $now): bool
    {
        $hour = (int) $now->setTimezone('Africa/Nairobi')->format('G');

        return $hour >= 8 && $hour < 20;
    }
}
