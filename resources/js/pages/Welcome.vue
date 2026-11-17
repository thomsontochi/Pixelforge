<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { login, register } from '@/routes';
import { index as generateIndex } from '@/routes/generations';

defineProps<{
    demoLogin: { email: string; password: string } | null;
}>();

const receipt = [
    { label: 'Image output', detail: '6,450 × $40 / 1M', cost: '$0.2580' },
    { label: 'Reasoning', detail: '3,184 × $12.50 / 1M', cost: '$0.0398' },
    { label: 'Reference image', detail: '765 × $10 / 1M', cost: '$0.0077' },
    { label: 'Prompt text', detail: '12 × $5 / 1M', cost: '$0.0001' },
];

const timeline = [
    { label: 'Price estimated', value: '$0.4025', done: true },
    { label: 'Credits held', value: '$0.4572', done: true },
    { label: 'Generated', value: '16s', done: true },
    { label: 'Charged exactly', value: '$0.3819', done: false },
];

const resilience = ['Rate limits', 'Overloads', 'Duplicate webhooks', 'Out-of-order events', 'Lost webhooks', 'Failed images are free'];

const steps = [
    { title: 'See the price first', body: 'Pick a size and quality and you see the estimate and its range before you spend a cent.' },
    { title: 'Pay only what it cost', body: 'We hold the maximum while it runs, charge the exact token cost plus 25%, and return the rest.' },
    { title: 'Check every cent', body: 'Each image gets an itemised receipt, and your credit activity lists every hold, refund and charge.' },
];
</script>

<template>
    <Head title="Generate images, pay exactly what they cost" />

    <div class="min-h-screen overflow-x-hidden bg-background text-foreground">
        <!-- Announcement bar -->
        <div class="bg-[#111111] px-4 py-2 text-center text-xs text-[#f3eee3]">
            <span class="mr-2 rounded-full bg-white/10 px-2 py-0.5">New</span>
            Every image now comes with an itemised receipt, down to the token
            <span aria-hidden="true">→</span>
        </div>

        <header class="mx-auto flex max-w-6xl items-center justify-between px-6 py-5">
            <div class="flex items-center gap-8">
                <div class="flex items-center gap-2 font-semibold">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-[#111111] text-[#f3eee3]">
                        <AppLogoIcon class="size-5 fill-current" />
                    </span>
                    Pixelforge Studio
                </div>
                <nav class="hidden gap-6 text-sm text-muted-foreground md:flex">
                    <a href="#how" class="hover:text-foreground">How it works</a>
                    <a href="#pricing" class="hover:text-foreground">Pricing</a>
                </nav>
            </div>
            <nav class="flex items-center gap-2 text-sm">
                <Link
                    v-if="$page.props.auth.user"
                    :href="generateIndex()"
                    class="rounded-full bg-[#111111] px-4 py-2 font-medium text-[#f3eee3] hover:bg-black"
                    >Open studio</Link
                >
                <template v-else>
                    <Link :href="login()" class="rounded-full px-4 py-2 hover:bg-muted">Log in</Link>
                    <Link :href="register()" class="rounded-full bg-[#111111] px-4 py-2 font-medium text-[#f3eee3] hover:bg-black">Get started</Link>
                </template>
            </nav>
        </header>

        <main class="mx-auto max-w-6xl px-6">
            <!-- Hero -->
            <section class="grid items-center gap-14 py-14 lg:grid-cols-[1.05fr_1fr] lg:py-20">
                <div class="space-y-7">
                    <h1 class="text-5xl leading-[1.02] font-semibold tracking-tight text-balance sm:text-6xl">
                        Generate images.<br />
                        Pay
                        <span class="relative inline-block font-serif font-normal italic">
                            exactly what they cost.
                            <svg class="absolute -bottom-2 left-0 h-3 w-full text-primary" viewBox="0 0 300 12" preserveAspectRatio="none" aria-hidden="true">
                                <path d="M2 8 C 60 2, 120 2, 180 6 S 260 10, 298 4" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                            </svg>
                        </span>
                    </h1>

                    <p class="max-w-md text-lg text-muted-foreground">
                        See the price before you generate, watch each image go from queued to done, and get a receipt down to the token. Failed images never
                        cost a thing.
                    </p>

                    <div class="flex flex-wrap items-center gap-4">
                        <Link
                            :href="$page.props.auth.user ? generateIndex() : register()"
                            class="rounded-full bg-primary px-6 py-3 font-medium text-primary-foreground shadow-sm transition hover:brightness-110"
                        >
                            {{ $page.props.auth.user ? 'Open studio' : 'Start generating' }} →
                        </Link>
                        <a href="#pricing" class="flex items-center gap-2 text-sm font-medium">
                            <span class="flex size-9 items-center justify-center rounded-full border border-foreground/20">▸</span>
                            See how pricing works
                        </a>
                    </div>

                    <div class="flex items-center gap-3 text-sm text-muted-foreground">
                        <span class="font-serif text-3xl text-foreground italic">10<sup class="text-base">-12</sup></span>
                        <span>Every charge is exact to a picodollar.<br />Nothing is rounded, nothing is charged twice.</span>
                    </div>

                    <div v-if="demoLogin && !$page.props.auth.user" class="max-w-sm rounded-2xl border border-dashed border-foreground/20 p-4 text-sm">
                        <div class="font-medium">Reviewing? Use the demo operator account</div>
                        <div class="mt-1 font-mono text-xs">{{ demoLogin.email }} · {{ demoLogin.password }}</div>
                        <div class="mt-1 text-xs text-muted-foreground">$10 of credits and access to the admin report.</div>
                    </div>
                </div>

                <!-- Visual: image card with an orange block behind and floating cards -->
                <div class="relative mx-auto w-full max-w-md pb-10 lg:mr-0">
                    <div class="absolute top-8 -right-4 bottom-0 left-8 rounded-3xl bg-primary" aria-hidden="true" />

                    <div class="relative aspect-[4/5] overflow-hidden rounded-3xl bg-[#1b1a18] shadow-xl">
                        <!-- Abstract placeholder "generation", like Pixelforge's own output -->
                        <div
                            class="absolute inset-0"
                            style="
                                background:
                                    radial-gradient(circle at 30% 30%, #f3eee3 0 12%, transparent 13%), radial-gradient(circle at 70% 65%, #d9541e55 0 22%, transparent 23%),
                                    linear-gradient(160deg, #3a3631 0%, #111111 70%);
                            "
                        />
                        <div class="absolute inset-x-0 bottom-0 p-5 text-xs text-[#f3eee3]/70">“A lighthouse on a cliff at dawn, oil painting”</div>
                        <span class="absolute top-4 left-4 rounded-full bg-black/50 px-3 py-1 text-[11px] text-[#f3eee3]">Landscape · high</span>
                    </div>

                    <!-- Floating: price card -->
                    <div class="absolute top-10 -right-6 w-44 rounded-2xl bg-card p-4 shadow-lg ring-1 ring-black/5">
                        <div class="text-[11px] text-muted-foreground">You paid</div>
                        <div class="text-2xl font-semibold tabular-nums">$0.3819</div>
                        <div class="text-[11px] text-muted-foreground">estimated $0.4025</div>
                    </div>

                    <!-- Floating: lifecycle checklist -->
                    <div class="absolute bottom-0 -left-6 w-64 rounded-2xl bg-card p-4 shadow-lg ring-1 ring-black/5">
                        <div class="mb-2 flex items-center justify-between text-xs">
                            <span class="font-medium">This image</span>
                            <span class="text-muted-foreground">just now</span>
                        </div>
                        <ul class="space-y-1.5 text-xs">
                            <li v-for="item in timeline" :key="item.label" class="flex items-center justify-between gap-2">
                                <span class="flex items-center gap-2">
                                    <span
                                        class="flex size-4 items-center justify-center rounded-full text-[9px]"
                                        :class="item.done ? 'bg-[#111111] text-[#f3eee3]' : 'border-2 border-primary'"
                                        >{{ item.done ? '✓' : '' }}</span
                                    >
                                    {{ item.label }}
                                </span>
                                <span class="tabular-nums" :class="item.done ? 'text-muted-foreground' : 'font-medium text-primary'">{{ item.value }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- Resilience strip (in place of a logo wall) -->
            <section class="flex flex-wrap items-center gap-x-8 gap-y-3 border-y border-foreground/10 py-6 text-sm">
                <span class="text-xs text-muted-foreground">Built for what real<br />AI providers do</span>
                <span v-for="item in resilience" :key="item" class="font-serif text-lg italic text-foreground/70">{{ item }}</span>
            </section>

            <!-- How it works -->
            <section id="how" class="grid gap-4 py-16 md:grid-cols-3">
                <div v-for="(step, i) in steps" :key="step.title" class="rounded-3xl bg-card p-6 ring-1 ring-foreground/5">
                    <div class="mb-4 font-serif text-4xl text-primary italic">0{{ i + 1 }}</div>
                    <h2 class="font-semibold">{{ step.title }}</h2>
                    <p class="mt-2 text-sm text-muted-foreground">{{ step.body }}</p>
                </div>
            </section>

            <!-- Pricing receipt -->
            <section id="pricing" class="grid items-center gap-10 pb-24 lg:grid-cols-2">
                <div class="space-y-4">
                    <h2 class="text-3xl font-semibold tracking-tight">
                        A receipt for <span class="font-serif font-normal italic">every</span> image.
                    </h2>
                    <p class="text-muted-foreground">
                        Pixelforge prices each kind of token differently and only reports some counts when the image is done. We show you the range up
                        front, then the exact bill: tokens × rate, Pixelforge's cost, and our 25%.
                    </p>
                </div>
                <div class="rounded-3xl bg-card p-6 shadow-sm ring-1 ring-foreground/5">
                    <table class="w-full text-sm tabular-nums">
                        <tbody>
                            <tr v-for="line in receipt" :key="line.label">
                                <td class="py-1">{{ line.label }}</td>
                                <td class="py-1 text-xs text-muted-foreground">{{ line.detail }}</td>
                                <td class="py-1 text-right">{{ line.cost }}</td>
                            </tr>
                            <tr class="border-t border-foreground/10 text-muted-foreground">
                                <td class="pt-2" colspan="2">Pixelforge cost</td>
                                <td class="pt-2 text-right">$0.3055</td>
                            </tr>
                            <tr class="text-muted-foreground">
                                <td colspan="2">25% service fee</td>
                                <td class="text-right">$0.0764</td>
                            </tr>
                            <tr class="text-base font-semibold">
                                <td class="pt-1" colspan="2">You paid</td>
                                <td class="pt-1 text-right">$0.3819</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-4 rounded-xl bg-muted px-3 py-2 text-xs text-muted-foreground">Estimated $0.4025 · held $0.4572 · $0.0753 returned to your balance</p>
                </div>
            </section>
        </main>

        <footer class="border-t border-foreground/10 py-6 text-center text-xs text-muted-foreground">
            Images by Pixelforge, a simulated provider. Credits are test credits; no real payments.
            <span class="mx-1.5">·</span>
            Pixelforge Studio&trade; — built by
            <a href="https://github.com/thomsontochi" target="_blank" rel="noopener" class="underline-offset-4 hover:text-foreground hover:underline">Austin Opia</a>
        </footer>
    </div>
</template>
