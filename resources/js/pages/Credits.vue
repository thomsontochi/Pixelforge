<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { index as creditsIndex, store as buyCredits } from '@/routes/credits';

type Entry = {
    id: number;
    type: 'purchase' | 'hold' | 'release' | 'charge';
    amount: string;
    amount_exact: string;
    is_credit: boolean;
    prompt: string | null;
    created_at: string;
};

defineProps<{
    entries: {
        data: Entry[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    balance: string;
    creditPacks: number[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credit activity', href: creditsIndex() }],
    },
});

const labels: Record<Entry['type'], { title: string; help: string }> = {
    purchase: {
        title: 'Credits added',
        help: 'Test purchase, no payment taken',
    },
    hold: {
        title: 'Held',
        help: 'Most this image could cost, reserved while it runs',
    },
    release: {
        title: 'Hold returned',
        help: 'The reservation, given back once the image finished',
    },
    charge: {
        title: 'Charged',
        help: 'Exact final price: Pixelforge cost + 25%',
    },
};

const date = (iso: string) =>
    new Date(iso).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
</script>

<template>
    <Head title="Credit activity" />

    <div class="mx-auto w-full max-w-3xl space-y-4 p-4">
        <div
            class="flex flex-wrap items-end justify-between gap-4 rounded-2xl border bg-card p-5"
        >
            <div>
                <div class="text-xs text-muted-foreground">Balance</div>
                <div class="text-2xl font-semibold tabular-nums">
                    {{ balance }}
                </div>
                <p class="mt-1 max-w-md text-xs text-muted-foreground">
                    Every change to your balance is listed below, so every cent
                    can be traced. A generation holds its maximum price, then
                    the hold is returned and the exact price is charged.
                </p>
            </div>
            <div class="flex gap-1.5">
                <Button
                    v-for="amount in creditPacks"
                    :key="amount"
                    variant="outline"
                    size="sm"
                    @click="
                        router.post(
                            buyCredits.url(),
                            { amount },
                            { preserveScroll: true },
                        )
                    "
                >
                    +${{ amount }}
                </Button>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border">
            <p
                v-if="entries.data.length === 0"
                class="p-8 text-center text-sm text-muted-foreground"
            >
                No activity yet. Add credits to get started.
            </p>
            <ul v-else class="divide-y">
                <li
                    v-for="entry in entries.data"
                    :key="entry.id"
                    class="flex items-center justify-between gap-4 px-4 py-3"
                >
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ labels[entry.type].title }}
                        </div>
                        <div class="truncate text-xs text-muted-foreground">
                            {{
                                entry.prompt
                                    ? `“${entry.prompt}”`
                                    : labels[entry.type].help
                            }}
                            · {{ date(entry.created_at) }}
                        </div>
                    </div>
                    <div
                        class="text-right text-sm font-medium tabular-nums"
                        :class="
                            entry.is_credit
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : ''
                        "
                        :title="'Exact: $' + entry.amount_exact"
                    >
                        {{ entry.is_credit ? '+' : '' }}{{ entry.amount }}
                    </div>
                </li>
            </ul>
        </div>

        <nav
            v-if="entries.links.length > 3"
            class="flex flex-wrap justify-center gap-1"
        >
            <template v-for="link in entries.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md px-3 py-1 text-sm"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-muted'
                    "
                    ><span v-html="link.label"
                /></Link>
            </template>
        </nav>
    </div>
</template>
