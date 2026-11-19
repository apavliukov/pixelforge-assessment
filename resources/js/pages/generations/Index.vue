<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed, useTemplateRef, watch } from 'vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import GenerationForm from '@/components/generations/GenerationForm.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/generations';
import type {
    Estimate,
    Generation,
    GenerationDefaults,
    GenerationLimits,
    Paginated,
} from '@/types';

const props = defineProps<{
    generations: Paginated<Generation>;
    unfinishedCount: number;
    maxUnfinished: number;
    limits: GenerationLimits;
    defaults: GenerationDefaults;
    estimate: Estimate | null;
}>();

defineOptions({
    // Shared props the page does not declare would otherwise land on the root as HTML attributes.
    inheritAttrs: false,
    layout: {
        breadcrumbs: [
            {
                title: 'Generations',
                href: index(),
            },
        ],
    },
});

const history = useTemplateRef<HTMLElement>('history');

// The new generation is the first card; on a narrow screen it is below the form.
function showHistory(): void {
    history.value?.scrollIntoView({ behavior: 'smooth' });
}

// Counted across all pages: the form's limit depends on generations that may not be listed here.
const hasUnfinished = computed(() => props.unfinishedCount > 0);

const { start, stop } = usePoll(
    2000,
    { only: ['generations', 'unfinishedCount'] },
    { autoStart: false },
);

watch(hasUnfinished, (unfinished) => (unfinished ? start() : stop()), {
    immediate: true,
});
</script>

<template>
    <div
        class="grid grid-cols-1 items-start gap-8 p-4 lg:grid-cols-[22rem_minmax(0,1fr)]"
    >
        <Head title="Generations" />

        <section class="lg:sticky lg:top-4">
            <Heading
                variant="small"
                title="New generation"
                description="Describe the image and see the estimated charge"
            />

            <GenerationForm
                class="mt-4"
                :initial-estimate="estimate"
                :defaults="defaults"
                :limits="limits"
                :unfinished-count="unfinishedCount"
                :max-unfinished="maxUnfinished"
                @submitted="showHistory"
            />
        </section>

        <section ref="history" class="scroll-mt-4">
            <Heading
                variant="small"
                title="History"
                description="Your generations, newest first"
            />

            <p
                v-if="generations.data.length === 0"
                class="mt-4 rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                Nothing here yet. Your first generation will appear as soon as
                you submit it.
            </p>

            <ul v-else class="mt-4 space-y-3">
                <li v-for="generation in generations.data" :key="generation.id">
                    <GenerationCard :generation="generation" />
                </li>
            </ul>

            <nav
                v-if="generations.meta.last_page > 1"
                class="mt-4 flex items-center justify-between text-sm text-muted-foreground"
                aria-label="History pages"
            >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!generations.links.prev"
                    as-child
                >
                    <Link
                        v-if="generations.links.prev"
                        :href="generations.links.prev"
                        preserve-state
                    >
                        Newer
                    </Link>
                    <span v-else>Newer</span>
                </Button>

                <span>
                    Page {{ generations.meta.current_page }} of
                    {{ generations.meta.last_page }}
                </span>

                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!generations.links.next"
                    as-child
                >
                    <Link
                        v-if="generations.links.next"
                        :href="generations.links.next"
                        preserve-state
                    >
                        Older
                    </Link>
                    <span v-else>Older</span>
                </Button>
            </nav>
        </section>
    </div>
</template>
