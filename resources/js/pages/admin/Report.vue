<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { report } from '@/routes/admin';

type Totals = { charged: string; provider_cost: string; profit: string };
type UserRow = Totals & { name: string; email: string; generations: number };
type DayRow = Totals & {
    date: string;
    generations: number;
    pixelforge_generations: number | null;
    pixelforge_cost: string | null;
    reconciled: boolean | null;
    users: UserRow[];
};

defineProps<{
    days: number;
    rows: DayRow[];
    totals: Totals & { generations: number; pixelforge_cost: string | null };
    usageError: string | null;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin report', href: report() }] },
});

const open = ref<Record<string, boolean>>({});

function changeDays(event: Event) {
    router.get(
        report.url(),
        { days: (event.target as HTMLSelectElement).value },
        { preserveState: true },
    );
}
</script>

<template>
    <Head title="Admin report" />

    <div class="space-y-4 p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-lg font-semibold">
                    Credit spend, provider costs and profit
                </h1>
                <p class="text-sm text-muted-foreground">
                    Per UTC day, by the day each generation completed (the same
                    way Pixelforge bills). Provider costs are checked against
                    Pixelforge's GET /v1/usage.
                </p>
            </div>
            <select
                :value="days"
                class="rounded-md border bg-transparent px-3 py-2 text-sm"
                @change="changeDays"
            >
                <option v-for="d in [7, 14, 30, 92]" :key="d" :value="d">
                    Last {{ d }} days
                </option>
            </select>
        </div>

        <p
            v-if="usageError"
            class="rounded-md border border-destructive/40 p-3 text-sm text-destructive"
        >
            {{ usageError }}
        </p>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="rounded-xl border p-4">
                <div class="text-xs text-muted-foreground">
                    Users were charged
                </div>
                <div class="text-lg font-semibold tabular-nums">
                    {{ totals.charged }}
                </div>
            </div>
            <div class="rounded-xl border p-4">
                <div class="text-xs text-muted-foreground">
                    Provider cost (our records)
                </div>
                <div class="text-lg font-semibold tabular-nums">
                    {{ totals.provider_cost }}
                </div>
            </div>
            <div class="rounded-xl border p-4">
                <div class="text-xs text-muted-foreground">
                    Provider cost (Pixelforge)
                </div>
                <div class="text-lg font-semibold tabular-nums">
                    {{ totals.pixelforge_cost ?? '—' }}
                </div>
            </div>
            <div class="rounded-xl border p-4">
                <div class="text-xs text-muted-foreground">Profit</div>
                <div class="text-lg font-semibold tabular-nums">
                    {{ totals.profit }}
                </div>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead
                    class="bg-muted/60 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-3 py-2">Day (UTC)</th>
                        <th class="px-3 py-2 text-right">Generations</th>
                        <th class="px-3 py-2 text-right">Charged</th>
                        <th class="px-3 py-2 text-right">Provider cost</th>
                        <th class="px-3 py-2 text-right">Pixelforge says</th>
                        <th class="px-3 py-2 text-right">Profit</th>
                        <th class="px-3 py-2">Match</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="row in rows" :key="row.date">
                        <tr
                            class="cursor-pointer border-t hover:bg-muted/40"
                            @click="open[row.date] = !open[row.date]"
                        >
                            <td class="px-3 py-2 font-medium">
                                {{ row.date }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ row.generations }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ row.charged }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ row.provider_cost }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ row.pixelforge_cost ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ row.profit }}
                            </td>
                            <td class="px-3 py-2">
                                <Badge
                                    v-if="row.reconciled === true"
                                    variant="secondary"
                                    >Matches</Badge
                                >
                                <Badge
                                    v-else-if="row.reconciled === false"
                                    variant="destructive"
                                    >Differs</Badge
                                >
                            </td>
                        </tr>
                        <tr
                            v-for="user in open[row.date] ? row.users : []"
                            :key="row.date + user.email"
                            class="border-t bg-muted/20 text-xs"
                        >
                            <td class="px-3 py-2 pl-8">
                                {{ user.name }}
                                <span class="text-muted-foreground">{{
                                    user.email
                                }}</span>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ user.generations }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ user.charged }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ user.provider_cost }}
                            </td>
                            <td />
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ user.profit }}
                            </td>
                            <td />
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-muted-foreground">
            Click a day to see each user.
        </p>
    </div>
</template>
