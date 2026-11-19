<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { store as estimateRoute } from '@/actions/App/Http/Controllers/GenerationEstimatesController';
import { store as submitRoute } from '@/actions/App/Http/Controllers/GenerationsController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    Estimate,
    GenerationDefaults,
    GenerationLimits,
    ImageQuality,
    ImageSize,
} from '@/types';

const props = defineProps<{
    initialEstimate: Estimate | null;
    defaults: GenerationDefaults;
    limits: GenerationLimits;
    unfinishedCount: number;
    maxUnfinished: number;
}>();

const emit = defineEmits<{ submitted: [] }>();

const sizes: { value: ImageSize; label: string }[] = [
    { value: '1024x1024', label: 'Square (1024×1024)' },
    { value: '1536x1024', label: 'Landscape (1536×1024)' },
    { value: '1024x1536', label: 'Portrait (1024×1536)' },
];

const qualities: { value: ImageQuality; label: string }[] = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];

// Bound rather than inline: vue-tsc misreads `//` in a static attribute.
const referenceImagePlaceholder = 'https://example.com/image.png';

const form = useForm({
    prompt: '',
    size: props.defaults.size,
    quality: props.defaults.quality,
    reference_images: [] as string[],
});

// Typed loosely: the keys include `generation` and one per reference image position.
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

// The server gets `generation` back as an error key when it refuses a valid request.
const refusal = computed(() => errors.value.generation);

// Counted as the backend counts: Unicode characters, not UTF-16 units.
const promptLength = computed(() => [...form.prompt.trim()].length);

const referenceImageCount = computed(
    () => form.reference_images.filter((url) => url.trim() !== '').length,
);

const estimate = ref<Estimate | null>(props.initialEstimate);
const pricingUnavailable = ref(props.initialEstimate === null);
const estimateInvalid = ref(false);
const shownEstimate = computed(() =>
    estimateInvalid.value ? null : estimate.value,
);

const estimateRequest = useHttp<
    {
        prompt: string;
        size: ImageSize;
        quality: ImageQuality;
        reference_image_count: number;
    },
    Estimate
>({
    prompt: '',
    size: props.defaults.size,
    quality: props.defaults.quality,
    reference_image_count: 0,
});

watchDebounced(
    () => [form.prompt, form.size, form.quality, referenceImageCount.value],
    () => {
        estimateRequest.cancel();
        estimateRequest.prompt = form.prompt;
        estimateRequest.size = form.size;
        estimateRequest.quality = form.quality;
        estimateRequest.reference_image_count = referenceImageCount.value;

        estimateRequest
            .post(estimateRoute.url(), {
                onSuccess: (response) => {
                    estimate.value = response;
                    pricingUnavailable.value = false;
                    estimateInvalid.value = false;
                },
                onError: () => {
                    estimateInvalid.value = true;
                },
                onHttpException: (response) => {
                    if (response.status === 503) {
                        pricingUnavailable.value = true;
                    }

                    return false;
                },
                onNetworkError: () => false,
            })
            // A failed or cancelled request keeps the last estimate; the callbacks above did the rest.
            .catch(() => {});
    },
    { debounce: 300 },
);

const limitReached = computed(
    () => props.unfinishedCount >= props.maxUnfinished,
);

const canSubmit = computed(
    () => !form.processing && !limitReached.value && !pricingUnavailable.value,
);

function removeReferenceImage(position: number): void {
    form.reference_images.splice(position, 1);
    form.clearErrors();
}

function referenceImageError(position: number): string | undefined {
    return errors.value[`reference_images.${position}`];
}

function submit(): void {
    // Blank fields are dropped first, so an error's position matches the field it is shown at.
    form.reference_images = form.reference_images
        .map((url) => url.trim())
        .filter((url) => url !== '');

    form.post(submitRoute.url(), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => emit('submitted'),
    });
}
</script>

<template>
    <form class="space-y-5" @submit.prevent="submit">
        <div class="grid gap-2">
            <div class="flex items-baseline justify-between">
                <Label for="prompt">Prompt</Label>
                <span
                    class="text-xs tabular-nums"
                    :class="
                        promptLength > limits.promptLength
                            ? 'text-red-600 dark:text-red-500'
                            : 'text-muted-foreground'
                    "
                >
                    {{ promptLength }} / {{ limits.promptLength }}
                </span>
            </div>
            <textarea
                id="prompt"
                v-model="form.prompt"
                rows="3"
                required
                placeholder="A lighthouse on a cliff at dusk, oil painting"
                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm lg:min-h-32 dark:bg-input/30"
            />
            <InputError
                :message="form.errors.prompt ?? estimateRequest.errors.prompt"
            />
        </div>

        <div class="grid grid-cols-[3fr_2fr] gap-3">
            <div class="grid min-w-0 gap-2">
                <Label for="size">Size</Label>
                <Select v-model="form.size">
                    <SelectTrigger id="size" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="size in sizes"
                            :key="size.value"
                            :value="size.value"
                        >
                            {{ size.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.size" />
            </div>

            <div class="grid min-w-0 gap-2">
                <Label for="quality">Quality</Label>
                <Select v-model="form.quality">
                    <SelectTrigger id="quality" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="quality in qualities"
                            :key="quality.value"
                            :value="quality.value"
                        >
                            {{ quality.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.quality" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label>Reference images</Label>

            <div
                v-for="(referenceImage, position) in form.reference_images"
                :key="position"
                class="grid gap-1"
            >
                <div class="flex gap-2">
                    <Input
                        :model-value="referenceImage"
                        @update:model-value="
                            form.reference_images[position] = String($event)
                        "
                        type="url"
                        :placeholder="referenceImagePlaceholder"
                        :aria-label="`Reference image ${position + 1}`"
                        :aria-invalid="
                            referenceImageError(position) !== undefined
                        "
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Remove reference image ${position + 1}`"
                        @click="removeReferenceImage(position)"
                    >
                        <X />
                    </Button>
                </div>
                <InputError :message="referenceImageError(position)" />
            </div>

            <InputError :message="form.errors.reference_images" />

            <Button
                v-if="form.reference_images.length < limits.referenceImages"
                type="button"
                variant="outline"
                size="sm"
                class="w-fit"
                @click="form.reference_images.push('')"
            >
                <Plus />
                Add reference image
            </Button>
            <p class="text-xs text-muted-foreground">
                Up to {{ limits.referenceImages }} image URLs. Each one adds to
                the price.
            </p>
        </div>

        <div
            class="space-y-2 rounded-lg border bg-muted/40 p-3"
            aria-live="polite"
        >
            <InputError
                v-if="pricingUnavailable"
                message="Pricing is temporarily unavailable, so generations can't be submitted right now."
            />
            <template v-else>
                <p class="text-sm">
                    <span class="text-muted-foreground">Estimated cost</span>
                    <span
                        class="ml-1 font-semibold tabular-nums transition-opacity"
                        :class="{ 'opacity-50': estimateRequest.processing }"
                    >
                        <template v-if="shownEstimate">
                            <span :title="`$${shownEstimate.min.exact}`">{{
                                shownEstimate.min.formatted
                            }}</span>
                            –
                            <span :title="`$${shownEstimate.max.exact}`">{{
                                shownEstimate.max.formatted
                            }}</span>
                        </template>
                        <template v-else>—</template>
                    </span>
                </p>
                <p v-if="shownEstimate" class="text-xs text-muted-foreground">
                    The exact price is known after generation and will not
                    exceed {{ shownEstimate.max.formatted }}. Failed generations
                    are free.
                </p>
            </template>
        </div>

        <div class="space-y-2">
            <Button type="submit" class="w-full" :disabled="!canSubmit">
                Generate
                <span
                    v-if="shownEstimate && !pricingUnavailable"
                    class="tabular-nums transition-opacity"
                    :class="{ 'opacity-50': estimateRequest.processing }"
                >
                    — up to {{ shownEstimate.max.formatted }}
                </span>
            </Button>

            <p v-if="limitReached" class="text-sm text-muted-foreground">
                You have {{ unfinishedCount }} generations in progress. Wait for
                one to finish.
            </p>
            <InputError v-else :message="refusal" />
        </div>
    </form>
</template>
