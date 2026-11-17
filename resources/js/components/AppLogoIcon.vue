<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- Pixelforge Studio mark: a 3x3 pixel grid with one "forged" spark pixel. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        v-bind="$attrs"
    >
        <rect x="2" y="2" width="6" height="6" rx="1.5" />
        <rect x="9" y="2" width="6" height="6" rx="1.5" opacity="0.55" />
        <rect x="16" y="2" width="6" height="6" rx="1.5" opacity="0.3" />
        <rect x="2" y="9" width="6" height="6" rx="1.5" opacity="0.55" />
        <rect x="9" y="9" width="6" height="6" rx="1.5" />
        <rect x="16" y="9" width="6" height="6" rx="1.5" opacity="0.55" />
        <rect x="2" y="16" width="6" height="6" rx="1.5" opacity="0.3" />
        <rect x="9" y="16" width="6" height="6" rx="1.5" opacity="0.55" />
        <path
            d="M19 15.5l1.1 2.4 2.4 1.1-2.4 1.1L19 22.5l-1.1-2.4-2.4-1.1 2.4-1.1z"
        />
    </svg>
</template>
