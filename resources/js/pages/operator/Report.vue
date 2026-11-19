<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ReconciliationCell from '@/components/operator/ReconciliationCell.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { show } from '@/routes/operatorReport';
import type {
    ChargeAboveEstimate,
    OperatorReport,
    Reconciliation,
    ReportDay,
    ReportPeriod,
} from '@/types';

const props = defineProps<{
    period: ReportPeriod;
    report: OperatorReport;
    // Loaded after the page: absent until then, null when the provider could not be asked.
    reconciliation?: Reconciliation | null;
    unfinishedCount: number;
    aboveEstimate: ChargeAboveEstimate[];
}>();

defineOptions({
    // Shared props the page does not declare would otherwise land on the root as HTML attributes.
    inheritAttrs: false,
    layout: {
        breadcrumbs: [
            {
                title: 'Operator report',
                href: show(),
            },
        ],
    },
});

const form = useForm({
    start_date: props.period.start,
    end_date: props.period.end,
});

const reconciliationLoading = computed(
    () => props.reconciliation === undefined,
);

const emptyFigures = {
    generations: 0,
    userCharge: '0.000000000',
    providerCost: '0.000000000',
    profit: '0.000000000',
};

// A day the provider billed and we recorded nothing for still gets a line, so the difference is seen.
const days = computed<ReportDay[]>(() => {
    const listed = new Set(props.report.days.map((day) => day.date));
    const providerOnly = Object.keys(props.reconciliation?.days ?? {})
        .filter((date) => !listed.has(date))
        .map((date) => ({ date, rows: [], totals: emptyFigures }));

    return [...props.report.days, ...providerOnly].sort((first, second) =>
        second.date.localeCompare(first.date),
    );
});

// With many users a day's rows would bury the totals, so days collapse to their total line. A
// small report opens with every day expanded: the rows per user are what the report is for.
const EXPANDED_BY_DEFAULT_UP_TO_ROWS = 30;

function initiallyExpanded(): Set<string> {
    const rows = props.report.days.reduce(
        (count, day) => count + day.rows.length,
        0,
    );

    return new Set(
        rows <= EXPANDED_BY_DEFAULT_UP_TO_ROWS
            ? props.report.days.map((day) => day.date)
            : [],
    );
}

const expandedDays = ref(initiallyExpanded());

watch(
    () => props.report,
    () => (expandedDays.value = initiallyExpanded()),
);

const allExpanded = computed(() =>
    props.report.days.every((day) => expandedDays.value.has(day.date)),
);

function toggleDay(date: string): void {
    if (!expandedDays.value.delete(date)) {
        expandedDays.value.add(date);
    }
}

function toggleAllDays(): void {
    expandedDays.value = new Set(
        allExpanded.value ? [] : props.report.days.map((day) => day.date),
    );
}

const dayFormat = new Intl.DateTimeFormat('en-US', {
    dateStyle: 'medium',
    timeZone: 'UTC',
});

function formatDay(date: string): string {
    return dayFormat.format(new Date(`${date}T00:00:00Z`));
}

const momentFormat = new Intl.DateTimeFormat('en-US', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'UTC',
});
</script>

<template>
    <div class="space-y-6 p-4">
        <Head title="Operator report" />

        <Heading
            variant="small"
            title="Operator report"
            description="Succeeded generations by the UTC day they were completed, with exact amounts"
        />

        <form
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="form.get(show.url(), { preserveState: true })"
        >
            <div class="grid gap-2">
                <Label for="start_date">From</Label>
                <Input
                    id="start_date"
                    v-model="form.start_date"
                    type="date"
                    required
                />
            </div>
            <div class="grid gap-2">
                <Label for="end_date">To</Label>
                <Input
                    id="end_date"
                    v-model="form.end_date"
                    type="date"
                    required
                />
            </div>
            <Button type="submit" :disabled="form.processing">Show</Button>
            <Button
                v-if="report.days.length > 0"
                type="button"
                variant="outline"
                class="ml-auto"
                @click="toggleAllDays"
            >
                {{ allExpanded ? 'Collapse all days' : 'Expand all days' }}
            </Button>
            <div class="basis-full">
                <InputError :message="form.errors.start_date" />
                <InputError :message="form.errors.end_date" />
            </div>
        </form>

        <AlertError
            v-if="reconciliation === null"
            title="Pixelforge usage is unavailable right now"
            :errors="[
                'Provider costs could not be reconciled. Reload the page to try again.',
            ]"
        />

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[60rem] text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Day (UTC) / user</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Generations
                        </th>
                        <th class="px-3 py-2 text-right font-medium">
                            User charges
                        </th>
                        <th class="px-3 py-2 text-right font-medium">
                            Provider cost
                        </th>
                        <th class="px-3 py-2 text-right font-medium">Profit</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Pixelforge reports
                        </th>
                        <th class="px-3 py-2 font-medium">Reconciliation</th>
                    </tr>
                </thead>

                <tbody v-if="days.length === 0">
                    <tr>
                        <td
                            colspan="7"
                            class="px-3 py-8 text-center text-muted-foreground"
                        >
                            No generation succeeded in this period.
                        </td>
                    </tr>
                </tbody>

                <tbody v-for="day in days" :key="day.date" class="border-t">
                    <tr class="bg-muted/30 font-medium">
                        <th class="px-3 py-2 text-left" scope="row">
                            <button
                                v-if="day.rows.length > 0"
                                type="button"
                                class="flex cursor-pointer items-center gap-1.5 font-medium"
                                :aria-expanded="expandedDays.has(day.date)"
                                @click="toggleDay(day.date)"
                            >
                                <ChevronRight
                                    class="size-4 transition-transform"
                                    :class="{
                                        'rotate-90': expandedDays.has(day.date),
                                    }"
                                />
                                {{ formatDay(day.date) }}
                                <span class="font-normal text-muted-foreground">
                                    ({{ day.rows.length }}
                                    {{
                                        day.rows.length === 1
                                            ? 'user'
                                            : 'users'
                                    }})
                                </span>
                            </button>
                            <span v-else class="pl-5.5">{{
                                formatDay(day.date)
                            }}</span>
                        </th>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ day.totals.generations }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            ${{ day.totals.userCharge }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            ${{ day.totals.providerCost }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            ${{ day.totals.profit }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            <Skeleton
                                v-if="reconciliationLoading"
                                class="ml-auto h-4 w-24"
                            />
                            <template
                                v-else-if="reconciliation?.days[day.date]"
                            >
                                ${{ reconciliation?.days[day.date].provider }}
                            </template>
                            <template v-else>—</template>
                        </td>
                        <ReconciliationCell
                            :comparison="reconciliation?.days[day.date]"
                            :loading="reconciliationLoading"
                        />
                    </tr>
                    <tr
                        v-for="row in expandedDays.has(day.date)
                            ? day.rows
                            : []"
                        :key="row.user.email"
                    >
                        <td class="px-3 py-2 pl-8">
                            {{ row.user.name }}
                            <span class="text-muted-foreground"
                                >({{ row.user.email }})</span
                            >
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ row.generations }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            ${{ row.userCharge }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            ${{ row.providerCost }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            ${{ row.profit }}
                        </td>
                        <td colspan="2"></td>
                    </tr>
                </tbody>

                <tfoot class="border-t-2 font-semibold">
                    <tr>
                        <th class="px-3 py-2 text-left" scope="row">
                            Period total
                        </th>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ report.totals.generations }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            ${{ report.totals.userCharge }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            ${{ report.totals.providerCost }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            ${{ report.totals.profit }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            <Skeleton
                                v-if="reconciliationLoading"
                                class="ml-auto h-4 w-24"
                            />
                            <template v-else-if="reconciliation?.total">
                                ${{ reconciliation?.total.provider }}
                            </template>
                            <template v-else>—</template>
                        </td>
                        <ReconciliationCell
                            :comparison="reconciliation?.total"
                            :loading="reconciliationLoading"
                        />
                    </tr>
                </tfoot>
            </table>
        </div>

        <p v-if="unfinishedCount > 0" class="text-sm text-muted-foreground">
            {{ unfinishedCount }}
            {{ unfinishedCount === 1 ? 'generation is' : 'generations are' }}
            unfinished right now. One that Pixelforge has already completed and
            this app has not yet recorded makes today differ until it arrives.
        </p>

        <section v-if="aboveEstimate.length > 0" class="space-y-3">
            <Heading
                variant="small"
                title="Charged above the estimate"
                description="The user charge exceeded the maximum the user was shown, because rates changed after the estimate"
            />

            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full min-w-[44rem] text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">User</th>
                            <th class="px-3 py-2 font-medium">
                                Completed (UTC)
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Estimate maximum
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                User charge
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Excess
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="generation in aboveEstimate"
                            :key="generation.id"
                            class="border-t"
                        >
                            <td class="px-3 py-2">
                                {{ generation.user.name }}
                                <span class="text-muted-foreground"
                                    >({{ generation.user.email }})</span
                                >
                            </td>
                            <td class="px-3 py-2">
                                {{
                                    momentFormat.format(
                                        new Date(generation.completedAt),
                                    )
                                }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                ${{ generation.estimateMax }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                ${{ generation.userCharge }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                ${{ generation.excess }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
