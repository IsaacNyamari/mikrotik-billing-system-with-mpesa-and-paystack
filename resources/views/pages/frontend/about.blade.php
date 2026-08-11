<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.frontend')] #[Title('About us')] class extends Component {
    //
}; ?>

<div>
    <x-frontend.hero
        compact
        :eyebrow="__('About')"
        :heading="__('Built for how ISPs here actually sell')"
        :lead="__('Not a foreign billing suite with M-PESA bolted on afterwards. Every decision in this platform starts from a customer standing in a shop with a phone in one hand.')"
    />

    <x-frontend.section align="left">
        <div class="mx-auto max-w-3xl space-y-6 text-zinc-600 dark:text-zinc-400">
            <p class="text-lg">
                {{ __('Most small ISPs run on a MikroTik, a spreadsheet and a phone full of M-PESA messages. The router works fine. The accounting is what falls over — vouchers written on paper, customers who paid but never got online, and no honest answer to how much last month made.') }}
            </p>

            <p>
                {{ __('This platform closes that gap. It sits between your routers and your customers: it takes the payment, writes the login onto the device, removes it when the time is up, and keeps the record of every shilling that moved. The router keeps doing what it is good at, and nobody has to be trusted to remember a step.') }}
            </p>

            <h2 class="pt-4 text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                {{ __('What we believe') }}
            </h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-frontend.feature icon="cash" :title="__('Nobody pays twice')">
                    {{ __('If a customer\'s money moved, they get what they bought — even if a router was unreachable at that moment, even if a confirmation never arrived. The system chases it rather than shrugging.') }}
                </x-frontend.feature>

                <x-frontend.feature icon="check" :title="__('The bill shows its working')">
                    {{ __('Every invoice we send you carries the arithmetic behind it. You should never have to take a number on trust.') }}
                </x-frontend.feature>

                <x-frontend.feature icon="shield" :title="__('Your data is walled off')">
                    {{ __('Isolation between ISPs is enforced at the database layer, not by developers remembering to add a filter. Router credentials are encrypted at rest and never shown twice.') }}
                </x-frontend.feature>

                <x-frontend.feature icon="phone" :title="__('The customer sees two taps')">
                    {{ __('Pick a package, enter a number, done. Anything more is a sale lost to someone holding a phone in one hand on a slow connection.') }}
                </x-frontend.feature>
            </div>

            <h2 class="pt-4 text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                {{ __('How we make money') }}
            </h2>

            <p>
                {{ __('Plainly, and only when you do. There is a one-off setup fee of KSh 2,000, then a share of what your HotSpot actually collects and a small monthly amount for each PPPoE line you are running. No licence, no charge per router, nothing for a quiet month.') }}
            </p>

            <p>
                {{ __('Your customers\' money never passes through us. It goes to your own M-PESA till or Paystack account, and we invoice you at the start of the following month.') }}
            </p>

            <p class="pt-2">
                <a href="{{ route('services') }}#pricing" class="font-medium text-zinc-900 underline underline-offset-4 dark:text-white">
                    {{ __('See the full pricing') }}
                </a>
            </p>
        </div>
    </x-frontend.section>

    <x-frontend.section tint :heading="__('Where we are')" align="center"
        :lead="__('Built and run in Kenya, for networks in Kenya. The people who answer your questions are the people who wrote it.')">
        <div class="mx-auto grid max-w-3xl gap-4 sm:grid-cols-3">
            <x-frontend.stat :value="__('RouterOS 7')" :label="__('Tested on real hardware')" />
            <x-frontend.stat :value="__('M-PESA')" :label="__('Daraja and Paystack')" />
            <x-frontend.stat :value="__('Multi-router')" :label="__('One account, every site')" />
        </div>
    </x-frontend.section>

    <x-frontend.cta />
</div>
