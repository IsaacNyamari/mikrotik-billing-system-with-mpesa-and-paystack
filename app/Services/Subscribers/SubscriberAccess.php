<?php

namespace App\Services\Subscribers;

use App\Enums\ServiceAccountStatus;
use App\Enums\ServiceType;
use App\Models\Customer;
use App\Models\ServiceAccount;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Services\Sms\SmsSender;
use App\Support\PhoneNumber;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * How a PPPoE subscriber signs in to see their own account.
 *
 * Subscribers are not users of this system — staff are — so they get no password of their own
 * to forget. Two ways in, both something they already hold:
 *
 * - **A code texted to the phone number on their account.** Six digits, ten minutes, five
 *   tries, kept hashed in the cache.
 * - **Their PPPoE login and password**, the pair written on the sticker by their router. Works
 *   when SMS does not.
 *
 * Both are scoped to one ISP: phone numbers and PPPoE logins are only unique within an ISP,
 * which is why the sign-in page lives under the ISP's own address.
 *
 * Signed in, the session holds the tenant and customer ids and nothing else. Every page
 * re-reads the customer, so a line terminated or a customer removed mid-session takes effect
 * on the next click.
 */
class SubscriberAccess
{
    public const SESSION_KEY = 'subscriber';

    protected const CODE_MINUTES = 10;

    protected const CODE_TRIES = 5;

    public function __construct(protected SmsSender $sms) {}

    /**
     * Text a sign-in code to this number, if it belongs to a subscriber of this ISP.
     *
     * Returns false only when a code could not be sent at all, so the page can point to the
     * other way in. It never says whether the number is registered.
     */
    public function sendCode(Tenant $tenant, string $phone): bool
    {
        $normalised = PhoneNumber::normalise($phone);

        $customer = Tenancy::forTenant($tenant, fn (): ?Customer => $this->customerByPhone($normalised));

        if ($customer === null) {
            return true;
        }

        $code = (string) random_int(100000, 999999);

        Cache::put($this->codeKey($tenant, $normalised), [
            'hash' => Hash::make($code),
            'customer_id' => $customer->id,
            'tries' => 0,
        ], now()->addMinutes(self::CODE_MINUTES));

        $message = Tenancy::forTenant($tenant, fn () => $this->sms->send(
            $normalised,
            __(':code is your :isp sign-in code. It expires in :minutes minutes. Do not share it.', [
                'code' => $code,
                'isp' => $tenant->name,
                'minutes' => self::CODE_MINUTES,
            ]),
            'login_code',
            // A sign-in code is asked for, not a notice; it goes even if the ISP has switched
            // reminders off.
            operator: true,
        ));

        return $message->status !== SmsMessage::SKIPPED;
    }

    /**
     * The customer a code proves, or null.
     */
    public function verifyCode(Tenant $tenant, string $phone, string $code): ?Customer
    {
        $key = $this->codeKey($tenant, PhoneNumber::normalise($phone));
        $entry = Cache::get($key);

        if (! is_array($entry)) {
            return null;
        }

        if ($entry['tries'] >= self::CODE_TRIES) {
            Cache::forget($key);

            return null;
        }

        if (! Hash::check(trim($code), (string) $entry['hash'])) {
            $entry['tries']++;
            Cache::put($key, $entry, now()->addMinutes(self::CODE_MINUTES));

            return null;
        }

        // One use.
        Cache::forget($key);

        return Tenancy::forTenant($tenant, fn (): ?Customer => $this->eligible(Customer::query()->find((int) $entry['customer_id'])));
    }

    /**
     * The customer whose PPPoE login this is, if the password matches.
     */
    public function verifyPppoe(Tenant $tenant, string $username, string $password): ?Customer
    {
        return Tenancy::forTenant($tenant, function () use ($username, $password): ?Customer {
            $account = ServiceAccount::query()
                ->where('type', ServiceType::Pppoe)
                ->where('status', '!=', ServiceAccountStatus::Terminated)
                ->where('username', trim($username))
                ->first();

            if ($account === null || $account->secret === null || ! hash_equals((string) $account->secret, $password)) {
                return null;
            }

            return $this->eligible($account->customer);
        });
    }

    public function signIn(Tenant $tenant, Customer $customer): void
    {
        session()->regenerate();
        session()->put(self::SESSION_KEY, ['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);
    }

    public function signOut(): void
    {
        session()->forget(self::SESSION_KEY);
        session()->regenerate();
    }

    /**
     * The signed-in subscriber of this ISP, with the ISP bound, or null.
     */
    public function current(Tenant $tenant): ?Customer
    {
        $session = session(self::SESSION_KEY);

        if (! is_array($session) || ($session['tenant_id'] ?? null) !== $tenant->id) {
            return null;
        }

        Tenancy::set($tenant);

        return $this->eligible(Customer::query()->find((int) ($session['customer_id'] ?? 0)));
    }

    /**
     * Only someone with a PPPoE line has anything to see here.
     */
    protected function eligible(?Customer $customer): ?Customer
    {
        if ($customer === null) {
            return null;
        }

        $hasLine = $customer->serviceAccounts()
            ->where('type', ServiceType::Pppoe)
            ->where('status', '!=', ServiceAccountStatus::Terminated)
            ->exists();

        return $hasLine ? $customer : null;
    }

    protected function customerByPhone(string $normalised): ?Customer
    {
        // Stored however the operator typed it, so narrow by the last nine digits and compare
        // the normalised forms.
        $tail = substr($normalised, -9);

        return Customer::query()
            // Spaces, dashes and a leading plus are how people type numbers; strip them in the
            // comparison rather than trusting the stored form.
            ->where(fn ($query) => $query
                ->whereRaw("REPLACE(REPLACE(phone, ' ', ''), '-', '') LIKE ?", ['%'.$tail])
                ->orWhereRaw("REPLACE(REPLACE(alternate_phone, ' ', ''), '-', '') LIKE ?", ['%'.$tail]))
            ->get()
            ->first(fn (Customer $customer): bool => (
                PhoneNumber::normalise($customer->phone) === $normalised
                || ($customer->alternate_phone !== null && PhoneNumber::normalise($customer->alternate_phone) === $normalised)
            ) && $this->eligible($customer) !== null);
    }

    protected function codeKey(Tenant $tenant, string $phone): string
    {
        return 'subscriber-code:'.$tenant->id.':'.$phone;
    }
}
