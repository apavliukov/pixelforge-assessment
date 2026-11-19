<script setup lang="ts">
import { CircleAlert, ImageOff } from '@lucide/vue';
import { useResizeObserver } from '@vueuse/core';
import { computed, onMounted, ref, useTemplateRef } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import type { Generation } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

const imageFailed = ref(false);
const submittedAt = ref('');
const promptExpanded = ref(false);
const promptTruncated = ref(false);
const promptElement = useTemplateRef<HTMLElement>('promptElement');
const promptExpandable = computed(
    () => promptTruncated.value || promptExpanded.value,
);

// Only a prompt that does not fit on one line can be expanded.
useResizeObserver(promptElement, () => {
    const element = promptElement.value;

    if (element && !promptExpanded.value) {
        promptTruncated.value = element.scrollWidth > element.clientWidth;
    }
});

// Formatted after mounting: the server does not know the user's time zone. The wording is
// English like the rest of the app; only the zone is the user's.
onMounted(() => {
    submittedAt.value = new Intl.DateTimeFormat('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(props.generation.submitted_at));
});

const showImage = computed(
    () => props.generation.image_url !== null && !imageFailed.value,
);

const imageExpired = computed(
    () =>
        props.generation.image_expired ||
        (props.generation.image_url !== null && imageFailed.value),
);

const badgeVariant = computed(() => {
    if (props.generation.status === 'succeeded') {
        return 'default';
    }

    return props.generation.status === 'failed' ? 'destructive' : 'secondary';
});

const parameters = computed(() => {
    const { size, quality, reference_image_count: count } = props.generation;
    const references =
        count === 0
            ? null
            : `${count} reference ${count === 1 ? 'image' : 'images'}`;

    return [size.replace('x', '×'), `${quality} quality`, references]
        .filter(Boolean)
        .join(', ');
});
</script>

<template>
    <article class="flex gap-4 rounded-xl border p-3">
        <div
            class="flex size-28 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted text-center text-xs text-muted-foreground"
        >
            <a
                v-if="showImage"
                :href="generation.image_url!"
                target="_blank"
                rel="noopener"
                class="size-full"
            >
                <img
                    :src="generation.image_url!"
                    :alt="generation.prompt"
                    class="size-full object-cover"
                    loading="lazy"
                    @error="imageFailed = true"
                />
            </a>
            <span
                v-else-if="imageExpired"
                class="flex flex-col items-center gap-1"
            >
                <ImageOff class="size-5" />
                Image expired
            </span>
            <CircleAlert
                v-else-if="generation.status === 'failed'"
                class="size-5"
            />
            <Spinner v-else />
        </div>

        <div class="min-w-0 flex-1 space-y-1.5">
            <div class="flex items-start justify-between gap-3">
                <component
                    :is="promptExpandable ? 'button' : 'p'"
                    ref="promptElement"
                    :type="promptExpandable ? 'button' : undefined"
                    class="min-w-0 text-left text-sm font-medium"
                    :class="[
                        promptExpanded ? 'break-words' : 'truncate',
                        promptExpandable && 'cursor-pointer',
                    ]"
                    :aria-expanded="
                        promptExpandable ? promptExpanded : undefined
                    "
                    :title="promptTruncated ? generation.prompt : undefined"
                    @click="promptExpanded = !promptExpanded && promptTruncated"
                >
                    {{ generation.prompt }}
                </component>
                <Badge :variant="badgeVariant">
                    <Spinner v-if="!generation.is_final" class="size-3" />
                    {{ generation.status_label }}
                </Badge>
            </div>

            <p class="text-xs text-muted-foreground">{{ parameters }}</p>

            <p
                v-if="generation.charge"
                class="text-xs text-muted-foreground tabular-nums"
            >
                Charged
                <span
                    class="font-medium text-foreground"
                    :title="`$${generation.charge.exact}`"
                    >{{ generation.charge.formatted }}</span
                >
            </p>

            <p class="text-xs text-muted-foreground tabular-nums">
                Estimated
                <span :title="`$${generation.estimate.min.exact}`">{{
                    generation.estimate.min.formatted
                }}</span>
                –
                <span :title="`$${generation.estimate.max.exact}`">{{
                    generation.estimate.max.formatted
                }}</span>
            </p>

            <InputError :message="generation.failure_reason ?? undefined" />

            <p class="min-h-4 text-xs text-muted-foreground">
                {{ submittedAt }}
            </p>
        </div>
    </article>
</template>
