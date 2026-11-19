<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import type { CostComparison } from '@/types';

defineProps<{
    // Absent while the provider's figures are loading; null when they could not be obtained
    // or the provider has nothing for these days.
    comparison: CostComparison | null | undefined;
    loading: boolean;
}>();
</script>

<template>
    <td class="px-3 py-2">
        <Skeleton v-if="loading" class="h-4 w-20" />
        <Badge v-else-if="comparison?.matches" variant="secondary"
            >Matches</Badge
        >
        <div v-else-if="comparison" class="space-y-0.5">
            <Badge variant="destructive">Differs</Badge>
            <p class="text-xs text-muted-foreground tabular-nums">
                Pixelforge is
                {{ comparison.providerIsHigher ? 'higher' : 'lower' }} by ${{
                    comparison.difference
                }}; generations: {{ comparison.generations.ours }} here,
                {{ comparison.generations.provider }} there
            </p>
        </div>
        <template v-else>—</template>
    </td>
</template>
