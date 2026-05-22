<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { X, Eye, Package, Hash } from 'lucide-vue-next';

const props = defineProps({
    redemption: { type: Object, required: true },
    pointsPerDollar: { type: Number, default: 35 },
});

const isOpen = ref(false);

const formatNumber = (num: number) => {
    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(num || 0);
};

const formatDate = (val: string | null) => {
    if (!val) return 'N/A';
    const match = String(val).match(/^(\d{4}-\d{2}-\d{2})/);
    if (match) return match[1];

    return new Date(val).toISOString().split('T')[0];
};

const redemptionDate = () =>
    formatDate(
        props.redemption.points_breakdown?.redemption_date ||
            props.redemption.points_breakdown?.imported_redemption_date ||
            props.redemption.points_breakdown?.date ||
            props.redemption.created_at,
    );

const computedValue = () => {
    const amount = Number(props.redemption.amount);
    const points = Number(props.redemption.points);
    const rate = Number(
        props.redemption.points_breakdown?.points_per_dollar ||
            props.pointsPerDollar ||
            35,
    );
    return amount > 0 ? amount.toFixed(2) : (points / rate).toFixed(2);
};

const sourceLabel = (detail: any) => {
    if (!detail.source_type) return `ID #${detail.source_id}`;
    const parts = detail.source_type.split('\\');
    return parts[parts.length - 1] || detail.source_type;
};
</script>

<template>
    <div class="inline-block">
        <Button
            @click.stop="isOpen = true"
            variant="outline"
            size="sm"
            class="h-7 border-primary/20 px-2 text-[10px] font-bold tracking-wider text-primary uppercase hover:bg-primary/10"
        >
            <Eye class="mr-1 h-3 w-3" /> View
        </Button>

        <div
            v-if="isOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4 text-left backdrop-blur-sm"
        >
            <div
                class="w-full max-w-2xl overflow-hidden rounded-xl border border-border/50 bg-card shadow-2xl"
                @click.stop
            >
                <!-- Header -->
                <div
                    class="flex items-center justify-between bg-amber-600 px-6 py-4 text-white"
                >
                    <h2
                        class="flex items-center gap-2 text-base font-bold tracking-wide"
                    >
                        <Eye class="h-5 w-5 opacity-80" />
                        Redemption Details
                        <span class="ml-1 text-xs font-normal text-white/60"
                            >#{{ redemption.id }}</span
                        >
                    </h2>
                    <button
                        @click="isOpen = false"
                        class="text-white/70 transition-colors hover:text-white"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="max-h-[80vh] space-y-6 overflow-y-auto p-6">
                    <!-- Summary Cards -->
                    <div class="grid grid-cols-2 gap-3">
                        <div
                            class="rounded-lg border border-border/50 bg-muted/30 p-3"
                        >
                            <span
                                class="text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                >Date</span
                            >
                            <div class="mt-0.5 text-sm font-medium">
                                {{ redemptionDate() }}
                            </div>
                        </div>
                        <div
                            class="rounded-lg border border-border/50 bg-muted/30 p-3"
                        >
                            <span
                                class="text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                >Status</span
                            >
                            <div class="mt-0.5 text-sm font-medium capitalize">
                                {{ redemption.status || 'Completed' }}
                            </div>
                        </div>
                        <div
                            class="rounded-lg border border-amber-200/50 bg-amber-50/60 p-3 dark:border-amber-800/30 dark:bg-amber-950/20"
                        >
                            <span
                                class="text-[10px] font-bold tracking-wider text-amber-600 uppercase dark:text-amber-400"
                                >Points Used</span
                            >
                            <div
                                class="mt-0.5 text-base font-bold text-amber-600 dark:text-amber-400"
                            >
                                {{ formatNumber(redemption.points) }} pts
                            </div>
                        </div>
                        <div
                            class="rounded-lg border border-green-200/50 bg-green-50/60 p-3 dark:border-green-800/30 dark:bg-green-950/20"
                        >
                            <span
                                class="text-[10px] font-bold tracking-wider text-green-600 uppercase dark:text-green-400"
                                >Value</span
                            >
                            <div
                                class="mt-0.5 text-base font-bold text-green-600 dark:text-green-400"
                            >
                                ${{ computedValue() }}
                            </div>
                        </div>
                    </div>

                    <!-- Reason & Details -->
                    <div class="space-y-3">
                        <div
                            class="rounded-lg border border-border/50 bg-muted/30 p-3"
                        >
                            <span
                                class="text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                >Reason</span
                            >
                            <div class="mt-0.5 text-sm font-medium">
                                {{ redemption.reason || '—' }}
                            </div>
                        </div>
                        <div
                            v-if="redemption.category"
                            class="rounded-lg border border-border/50 bg-muted/30 p-3"
                        >
                            <span
                                class="text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                >Category</span
                            >
                            <div class="mt-0.5 text-sm font-medium capitalize">
                                {{ redemption.category }}
                            </div>
                        </div>
                        <div
                            v-if="redemption.roomStatus"
                            class="rounded-lg border border-border/50 bg-muted/30 p-3"
                        >
                            <span
                                class="text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                >Room Status</span
                            >
                            <div class="mt-0.5 text-sm font-medium capitalize">
                                {{ redemption.roomStatus }}
                            </div>
                        </div>
                    </div>

                    <!-- Reference IDs -->
                    <div
                        v-if="
                            redemption.journey_id ||
                            redemption.cancel_id ||
                            redemption.old_id
                        "
                        class="space-y-1"
                    >
                        <h3
                            class="mb-2 flex items-center gap-2 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            <Hash class="h-4 w-4" /> References
                        </h3>
                        <div class="grid grid-cols-2 gap-3">
                            <div
                                v-if="redemption.journey_id"
                                class="rounded-lg border border-blue-200/50 bg-blue-50/50 p-3 dark:border-blue-800/30 dark:bg-blue-950/20"
                            >
                                <span
                                    class="text-[10px] font-bold tracking-wider text-blue-600 uppercase dark:text-blue-400"
                                    >Journey ID</span
                                >
                                <div class="mt-0.5 text-sm font-medium">
                                    {{ redemption.journey_id }}
                                </div>
                            </div>
                            <div
                                v-if="redemption.cancel_id"
                                class="rounded-lg border border-red-200/50 bg-red-50/50 p-3 dark:border-red-800/30 dark:bg-red-950/20"
                            >
                                <span
                                    class="text-[10px] font-bold tracking-wider text-red-600 uppercase dark:text-red-400"
                                    >Cancel ID</span
                                >
                                <div class="mt-0.5 text-sm font-medium">
                                    {{ redemption.cancel_id }}
                                </div>
                            </div>
                            <div
                                v-if="redemption.old_id"
                                class="rounded-lg border border-border/50 bg-muted/30 p-3"
                            >
                                <span
                                    class="text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                    >Legacy ID</span
                                >
                                <div class="mt-0.5 text-sm font-medium">
                                    {{ redemption.old_id }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Point Allocation (details) -->
                    <div
                        v-if="
                            redemption.details && redemption.details.length > 0
                        "
                    >
                        <h3
                            class="mb-3 flex items-center gap-2 text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            <Package class="h-4 w-4 text-amber-500" /> Point
                            Allocation
                        </h3>
                        <div
                            class="overflow-hidden rounded-lg border border-border/50"
                        >
                            <table class="min-w-full divide-y divide-border/50">
                                <thead class="bg-muted/30">
                                    <tr>
                                        <th
                                            class="px-4 py-2 text-left text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Source
                                        </th>
                                        <th
                                            class="px-4 py-2 text-left text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Source ID
                                        </th>
                                        <th
                                            class="px-4 py-2 text-right text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Points
                                        </th>
                                    </tr>
                                </thead>
                                <tbody
                                    class="divide-y divide-border/50 bg-card"
                                >
                                    <tr
                                        v-for="detail in redemption.details"
                                        :key="detail.id"
                                        class="transition-colors hover:bg-muted/20"
                                    >
                                        <td
                                            class="px-4 py-2.5 text-xs whitespace-nowrap text-foreground/70"
                                        >
                                            {{ sourceLabel(detail) }}
                                        </td>
                                        <td
                                            class="px-4 py-2.5 text-xs whitespace-nowrap text-foreground/70"
                                        >
                                            #{{ detail.source_id }}
                                        </td>
                                        <td
                                            class="px-4 py-2.5 text-right text-xs font-bold whitespace-nowrap text-amber-600 dark:text-amber-400"
                                        >
                                            -{{ formatNumber(detail.points) }}
                                            pts
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Points Breakdown JSON -->
                    <div
                        v-if="
                            redemption.points_breakdown &&
                            Object.keys(redemption.points_breakdown).length > 0
                        "
                    >
                        <details class="group">
                            <summary
                                class="flex cursor-pointer list-none items-center gap-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase transition-colors outline-none hover:text-foreground"
                            >
                                <span
                                    class="inline-block transition-transform group-open:rotate-90"
                                    >▶</span
                                >
                                Points Breakdown
                            </summary>
                            <div
                                class="mt-2 overflow-x-auto rounded border border-border/50 bg-muted/50 p-3 font-mono text-xs"
                            >
                                <pre>{{
                                    JSON.stringify(
                                        redemption.points_breakdown,
                                        null,
                                        2,
                                    )
                                }}</pre>
                            </div>
                        </details>
                    </div>
                </div>

                <div
                    class="flex justify-end border-t border-border/50 bg-muted/10 p-4"
                >
                    <Button
                        type="button"
                        variant="outline"
                        class="h-9 px-6 font-semibold shadow-sm"
                        @click="isOpen = false"
                        >Close</Button
                    >
                </div>
            </div>
        </div>
    </div>
</template>
