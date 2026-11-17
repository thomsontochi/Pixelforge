<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as creditsIndex, store as buyCredits } from '@/routes/credits';
import { estimate as estimateRoute, index as generateIndex, store as storeGeneration } from '@/routes/generations';

type Status = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';

type Receipt = {
    lines: { label: string; tokens: number; rate: string; cost: string }[];
    provider_cost: string;
    markup: string;
    charged: string;
    estimated: string;
    held: string;
    returned: string;
    billed_after_we_gave_up: boolean;
};

type Generation = {
    id: number;
    status: Status;
    is_final: boolean;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_cost: string;
    max_cost: string;
    charged: string | null;
    charged_exact: string | null;
    image_url: string | null;
    image_expired: boolean;
    error_code: string | null;
    error_message: string | null;
    notice: string | null;
    receipt: Receipt | null;
    created_at: string;
    started_at: string | null;
    completed_at: string | null;
};

type Estimate = { min: string; expected: string; max: string; can_afford: boolean };

const props = defineProps<{
    generations: { data: Generation[]; links: { url: string | null; label: string; active: boolean }[] };
    balance: string;
    sizes: string[];
    qualities: string[];
    creditPacks: number[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Generate', href: generateIndex() }] },
});

const sizeLabels: Record<string, string> = { '1024x1024': 'Square', '1536x1024': 'Landscape', '1024x1536': 'Portrait' };

const draft = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

// Pixelforge counts Unicode characters, not bytes or UTF-16 units.
const promptLength = computed(() => Array.from(draft.prompt).length);
const filledReferenceImages = computed(() => draft.reference_images.filter((url) => url.trim() !== ''));
const referenceImageErrors = computed(() =>
    Object.entries(draft.errors)
        .filter(([key]) => key.startsWith('reference_images'))
        .map(([, message]) => message),
);

// ---- Live price estimate -------------------------------------------------
const estimate = ref<Estimate | null>(null);
const estimateFailed = ref(false);
let estimateTimer: ReturnType<typeof setTimeout> | undefined;
let estimateRequest = 0;

async function refreshEstimate() {
    const requestId = ++estimateRequest;

    try {
        const response = await fetch(
            estimateRoute.url({
                query: {
                    prompt_length: promptLength.value,
                    size: draft.size,
                    quality: draft.quality,
                    reference_images: filledReferenceImages.value.length,
                },
            }),
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const data = (await response.json()) as Estimate;

        // Ignore a slow, older response that arrives after a newer one.
        if (requestId === estimateRequest) {
            estimate.value = data;
            estimateFailed.value = false;
        }
    } catch {
        if (requestId === estimateRequest) {
            estimateFailed.value = true;
        }
    }
}

watch(
    () => [promptLength.value, draft.size, draft.quality, filledReferenceImages.value.length, props.balance],
    () => {
        clearTimeout(estimateTimer);
        estimateTimer = setTimeout(refreshEstimate, 250);
    },
    { immediate: true },
);

const submitLabel = computed(() => {
    if (draft.processing) {
        return 'Submitting…';
    }

    return estimate.value ? `Generate · ~${estimate.value.expected}` : 'Generate';
});

// ---- Actions ---------------------------------------------------------------
// Pixelforge's test keywords, so anyone can trigger failures on purpose.
const testModes = [
    { keyword: '[fail]', hint: 'Accepted, then fails. Should cost $0.' },
    { keyword: '[duplicate]', hint: 'Every webhook arrives twice. Should be charged once.' },
    { keyword: '[slow]', hint: 'Takes 60–90 seconds.' },
    { keyword: '[overloaded]', hint: 'Pixelforge answers 503. Watch the retries.' },
    { keyword: '[blocked]', hint: 'Rejected by content policy. Nothing charged.' },
];

function toggleKeyword(keyword: string) {
    draft.prompt = draft.prompt.includes(keyword)
        ? draft.prompt.replace(keyword, '').replace(/\s{2,}/g, ' ').trim()
        : `${draft.prompt.trim()} ${keyword}`.trim();
}

function addReferenceImage() {
    if (draft.reference_images.length < 4) {
        draft.reference_images.push('');
    }
}

function submit() {
    draft.post(storeGeneration.url(), {
        preserveScroll: true,
        onSuccess: () => draft.reset('prompt', 'reference_images'),
    });
}

function buy(amount: number) {
    router.post(buyCredits.url(), { amount }, { preserveScroll: true });
}

// ---- Live status -----------------------------------------------------------
const hasActive = computed(() => props.generations.data.some((g) => !g.is_final));
const now = ref(Date.now());
let pollTimer: ReturnType<typeof setInterval> | undefined;
let clockTimer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    pollTimer = setInterval(() => {
        if (hasActive.value && !draft.processing) {
            router.reload({ only: ['generations', 'balance'] });
        }
    }, 2500);
    clockTimer = setInterval(() => (now.value = Date.now()), 1000);
});

onBeforeUnmount(() => {
    clearInterval(pollTimer);
    clearInterval(clockTimer);
    clearTimeout(estimateTimer);
});

const steps: { key: Status; label: string }[] = [
    { key: 'pending', label: 'Sent' },
    { key: 'queued', label: 'Queued' },
    { key: 'processing', label: 'Generating' },
    { key: 'succeeded', label: 'Done' },
];
const rank: Record<Status, number> = { pending: 0, queued: 1, processing: 2, succeeded: 3, failed: 3 };

function elapsed(generation: Generation): string {
    const start = new Date(generation.created_at).getTime();
    const end = generation.completed_at ? new Date(generation.completed_at).getTime() : now.value;
    const seconds = Math.max(0, Math.round((end - start) / 1000));

    return seconds < 60 ? `${seconds}s` : `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}

const errorHelp: Record<string, string> = {
    content_policy_violation: 'Pixelforge rejected this prompt.',
    generation_failed: "The model couldn't produce an image for this prompt.",
    internal_error: 'Pixelforge had an internal error. Trying again usually works.',
    daily_limit_exceeded: "Pixelforge's daily limit is used up. Try again after midnight UTC.",
    gave_up: 'Pixelforge stayed busy, so we stopped retrying.',
};

const openReceipts = ref<Record<number, boolean>>({});

const exactTitle = (exact: string | null) => (exact ? 'Exact: $' + exact : '');

function reuse(generation: Generation) {
    draft.prompt = generation.prompt;
    draft.size = generation.size;
    draft.quality = generation.quality;
    draft.reference_images = [...generation.reference_images];
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>

<template>
    <Head title="Generate" />

    <div class="grid gap-6 p-4 lg:grid-cols-[minmax(0,400px)_1fr] lg:items-start">
        <!-- ================= Composer ================= -->
        <section class="space-y-5 rounded-2xl border bg-card p-5 lg:sticky lg:top-4">
            <div class="flex items-center justify-between gap-4 rounded-xl bg-muted/60 px-4 py-3">
                <div>
                    <div class="text-xs text-muted-foreground">Balance</div>
                    <div class="text-lg font-semibold tabular-nums" data-test="balance">{{ balance }}</div>
                </div>
                <div class="flex gap-1.5">
                    <Button v-for="amount in creditPacks" :key="amount" variant="outline" size="sm" @click="buy(amount)">+${{ amount }}</Button>
                </div>
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="prompt">Prompt</Label>
                        <span class="text-xs tabular-nums text-muted-foreground">{{ promptLength }} / 4000</span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="draft.prompt"
                        rows="4"
                        maxlength="4000"
                        required
                        placeholder="A lighthouse on a cliff at dawn, oil painting"
                        class="w-full resize-y rounded-lg border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @keydown.meta.enter="submit"
                        @keydown.ctrl.enter="submit"
                    ></textarea>
                    <InputError :message="draft.errors.prompt" />
                </div>

                <div class="grid gap-2">
                    <Label>Size</Label>
                    <div class="grid grid-cols-3 gap-2">
                        <button
                            v-for="size in sizes"
                            :key="size"
                            type="button"
                            class="flex flex-col items-center gap-1.5 rounded-lg border px-2 py-2.5 text-xs transition"
                            :class="draft.size === size ? 'border-primary ring-1 ring-primary' : 'hover:bg-muted'"
                            :aria-pressed="draft.size === size"
                            @click="draft.size = size"
                        >
                            <span
                                class="block rounded-[3px] border-2 border-current opacity-70"
                                :class="size === '1024x1024' ? 'h-5 w-5' : size === '1536x1024' ? 'h-4 w-6' : 'h-6 w-4'"
                            />
                            <span class="font-medium">{{ sizeLabels[size] }}</span>
                            <span class="text-[10px] text-muted-foreground">{{ size }}</span>
                        </button>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Quality</Label>
                    <div class="grid grid-cols-3 gap-1 rounded-lg bg-muted p-1">
                        <button
                            v-for="quality in qualities"
                            :key="quality"
                            type="button"
                            class="rounded-md py-1.5 text-xs capitalize transition"
                            :class="draft.quality === quality ? 'bg-background font-medium shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                            :aria-pressed="draft.quality === quality"
                            @click="draft.quality = quality"
                        >
                            {{ quality }}
                        </button>
                    </div>
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label>Reference images <span class="font-normal text-muted-foreground">· optional, up to 4</span></Label>
                        <Button type="button" variant="ghost" size="sm" :disabled="draft.reference_images.length >= 4" @click="addReferenceImage">+ Add URL</Button>
                    </div>
                    <div v-for="n in draft.reference_images.length" :key="n" class="flex gap-2">
                        <Input :model-value="draft.reference_images[n - 1]" type="url" placeholder="https://example.com/style.png" @update:model-value="(value) => (draft.reference_images[n - 1] = String(value))" />
                        <Button type="button" variant="ghost" size="sm" aria-label="Remove" @click="draft.reference_images.splice(n - 1, 1)">✕</Button>
                    </div>
                    <p v-if="draft.reference_images.length" class="text-xs text-muted-foreground">Each reference image adds 765 tokens at the image rate.</p>
                    <InputError v-for="message in referenceImageErrors" :key="message" :message="message" />
                </div>

                <!-- Price before submitting -->
                <div class="rounded-xl border border-dashed p-4">
                    <div v-if="estimate" class="space-y-2">
                        <div class="flex items-baseline justify-between">
                            <span class="text-sm text-muted-foreground">Estimated price</span>
                            <span class="text-2xl font-semibold tabular-nums">{{ estimate.expected }}</span>
                        </div>
                        <div class="text-xs text-muted-foreground">
                            Between <span class="tabular-nums text-foreground">{{ estimate.min }}</span> and
                            <span class="tabular-nums text-foreground">{{ estimate.max }}</span>.
                            Image output is fixed by size and quality; reasoning and exact prompt tokens are only known when it's done.
                        </div>
                        <div class="text-xs text-muted-foreground">
                            We hold {{ estimate.max }} while it runs, charge the exact amount after, and return the rest. Failed images are free.
                        </div>
                        <p v-if="!estimate.can_afford" class="text-xs font-medium text-destructive">Not enough credits for the worst case. Add credits above.</p>
                    </div>
                    <p v-else-if="estimateFailed" class="text-sm text-destructive">Couldn't load the price. Check your connection; we'll retry as you type.</p>
                    <p v-else class="text-sm text-muted-foreground">Calculating price…</p>
                </div>

                <Button type="submit" class="w-full" size="lg" :disabled="draft.processing || !draft.prompt.trim() || estimate?.can_afford === false">
                    {{ submitLabel }}
                </Button>

                <!-- Test modes -->
                <div class="space-y-2 border-t pt-4">
                    <div class="text-xs font-medium text-muted-foreground">Test modes (Pixelforge keywords)</div>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="mode in testModes"
                            :key="mode.keyword"
                            type="button"
                            :title="mode.hint"
                            class="rounded-full border px-2.5 py-1 font-mono text-[11px] transition"
                            :class="draft.prompt.includes(mode.keyword) ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                            @click="toggleKeyword(mode.keyword)"
                        >
                            {{ mode.keyword }}
                        </button>
                    </div>
                </div>
            </form>
        </section>

        <!-- ================= History ================= -->
        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Your generations</h2>
                <Link :href="creditsIndex()" class="text-sm text-muted-foreground underline-offset-4 hover:underline">Credit activity →</Link>
            </div>

            <div v-if="generations.data.length === 0" class="rounded-2xl border border-dashed p-10 text-center">
                <p class="font-medium">No images yet</p>
                <p class="mt-1 text-sm text-muted-foreground">Write a prompt, check the price, and generate. You only pay for images that succeed.</p>
            </div>

            <article v-for="generation in generations.data" :key="generation.id" class="overflow-hidden rounded-2xl border bg-card">
                <div class="flex flex-col gap-4 p-4 sm:flex-row">
                    <!-- Image -->
                    <div class="relative flex aspect-square w-full shrink-0 items-center justify-center overflow-hidden rounded-xl bg-muted sm:w-36">
                        <a v-if="generation.image_url" :href="generation.image_url" target="_blank" rel="noopener" class="h-full w-full">
                            <img :src="generation.image_url" :alt="generation.prompt" class="h-full w-full object-cover" />
                        </a>
                        <span v-else-if="generation.image_expired" class="px-3 text-center text-xs text-muted-foreground">Image link expired after 24h</span>
                        <span v-else-if="generation.status === 'failed'" class="text-2xl text-muted-foreground">✕</span>
                        <div v-else class="h-full w-full animate-pulse bg-gradient-to-br from-muted to-muted-foreground/10" />
                    </div>

                    <div class="min-w-0 flex-1 space-y-3">
                        <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            <Badge v-if="generation.status === 'succeeded'">Done</Badge>
                            <Badge v-else-if="generation.status === 'failed'" variant="destructive">Failed</Badge>
                            <Badge v-else variant="secondary">In progress</Badge>
                            <span>{{ sizeLabels[generation.size] }} · {{ generation.quality }}</span>
                            <span v-if="generation.reference_images.length">· {{ generation.reference_images.length }} ref</span>
                            <span class="tabular-nums">· {{ elapsed(generation) }}</span>
                        </div>

                        <p class="line-clamp-2 text-sm">{{ generation.prompt }}</p>

                        <!-- Status timeline -->
                        <ol v-if="generation.status !== 'failed'" class="flex items-center gap-1.5 text-[11px]">
                            <li v-for="step in steps" :key="step.key" class="flex items-center gap-1.5">
                                <span
                                    class="flex items-center gap-1"
                                    :class="rank[generation.status] >= rank[step.key] ? 'text-foreground' : 'text-muted-foreground/60'"
                                >
                                    <span
                                        class="size-1.5 rounded-full"
                                        :class="[
                                            rank[generation.status] >= rank[step.key] ? 'bg-primary' : 'bg-muted-foreground/30',
                                            generation.status === step.key && !generation.is_final ? 'animate-ping' : '',
                                        ]"
                                    />
                                    {{ step.label }}
                                </span>
                                <span v-if="step.key !== 'succeeded'" class="h-px w-4 bg-border" />
                            </li>
                        </ol>

                        <p v-if="generation.notice && !generation.is_final" class="text-xs text-amber-600 dark:text-amber-400">{{ generation.notice }}</p>

                        <div v-if="generation.status === 'failed'" class="rounded-lg bg-destructive/10 px-3 py-2 text-xs text-destructive">
                            {{ errorHelp[generation.error_code ?? ''] ?? generation.error_message ?? 'The generation failed.' }}
                            <strong>You were not charged.</strong>
                        </div>

                        <!-- Money -->
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs tabular-nums">
                            <span class="text-muted-foreground">Estimated {{ generation.estimated_cost }}</span>
                            <span v-if="generation.status === 'succeeded'" class="font-semibold" :title="exactTitle(generation.charged_exact)">
                                Charged {{ generation.charged }}
                            </span>
                            <span v-else-if="generation.status === 'failed'" class="font-semibold">Charged $0.00</span>
                            <span v-else class="text-muted-foreground">Holding {{ generation.max_cost }}</span>
                            <button
                                v-if="generation.receipt"
                                type="button"
                                class="text-muted-foreground underline-offset-4 hover:underline"
                                @click="openReceipts[generation.id] = !openReceipts[generation.id]"
                            >
                                {{ openReceipts[generation.id] ? 'Hide receipt' : 'Receipt' }}
                            </button>
                            <button type="button" class="text-muted-foreground underline-offset-4 hover:underline" @click="reuse(generation)">Reuse prompt</button>
                        </div>
                    </div>
                </div>

                <!-- Itemised receipt -->
                <div v-if="generation.receipt && openReceipts[generation.id]" class="border-t bg-muted/40 px-4 py-3 text-xs">
                    <table class="w-full tabular-nums">
                        <tbody>
                            <tr v-for="line in generation.receipt.lines" :key="line.label" class="text-muted-foreground">
                                <td class="py-0.5">{{ line.label }}</td>
                                <td class="py-0.5 text-right">{{ line.tokens.toLocaleString() }} tokens</td>
                                <td class="py-0.5 pl-3 text-right">× {{ line.rate }}</td>
                                <td class="py-0.5 pl-3 text-right text-foreground">{{ line.cost }}</td>
                            </tr>
                            <tr class="border-t">
                                <td class="pt-1.5" colspan="3">Pixelforge cost</td>
                                <td class="pt-1.5 text-right">{{ generation.receipt.provider_cost }}</td>
                            </tr>
                            <tr>
                                <td colspan="3">Our 25% markup</td>
                                <td class="text-right">{{ generation.receipt.markup }}</td>
                            </tr>
                            <tr class="font-semibold">
                                <td colspan="3">You paid</td>
                                <td class="text-right">{{ generation.receipt.charged }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-2 text-muted-foreground">
                        Estimated {{ generation.receipt.estimated }} · held {{ generation.receipt.held }} · {{ generation.receipt.returned }} returned to your balance.
                    </p>
                    <p v-if="generation.receipt.billed_after_we_gave_up" class="mt-1 text-muted-foreground">
                        Pixelforge finished this after we had stopped waiting. We absorbed the cost; you keep the image for free.
                    </p>
                </div>
            </article>

            <nav v-if="generations.links.length > 3" class="flex flex-wrap justify-center gap-1">
                <template v-for="link in generations.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="rounded-md px-3 py-1 text-sm"
                        :class="link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                        ><span v-html="link.label"
                    /></Link>
                </template>
            </nav>
        </section>
    </div>
</template>
