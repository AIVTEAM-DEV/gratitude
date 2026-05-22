<script setup lang="ts">
import { ref } from 'vue';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Trash2, AlertTriangle, RotateCcw } from 'lucide-vue-next';

const props = defineProps({
    gratitudeNumber: { type: String, required: true },
    redemptionId: { type: [String, Number], required: true },
});

const emit = defineEmits(['saved']);
const isOpen = ref(false);
const isSubmitting = ref(false);
const isLoading = ref(false);
const redemption = ref<any>(null);

const openDialog = async () => {
    isOpen.value = true;
    isLoading.value = true;
    try {
        const res = await axios.get(
            `/internal-api/gratitude/${props.gratitudeNumber}/redeem/${props.redemptionId}`,
        );
        redemption.value = res.data;
    } catch (e) {
        console.error('Failed to load redemption details', e);
    } finally {
        isLoading.value = false;
    }
};

const submit = async () => {
    isSubmitting.value = true;
    try {
        await axios.delete(
            `/internal-api/gratitude/${props.gratitudeNumber}/redeem/${props.redemptionId}`,
        );
        isOpen.value = false;
        emit('saved');
    } catch (error) {
        console.error('Error deleting redemption', error);
        alert('Failed to delete redemption. Please try again.');
    } finally {
        isSubmitting.value = false;
    }
};

const formatDate = (d: string) => {
    if (!d) return 'N/A';

    const match = String(d).match(/^(\d{4}-\d{2}-\d{2})/);
    if (match) return match[1];

    return new Date(d).toISOString().split('T')[0];
};
const redemptionDate = () =>
    formatDate(
        redemption.value?.points_breakdown?.redemption_date ||
            redemption.value?.points_breakdown?.imported_redemption_date ||
            redemption.value?.points_breakdown?.date ||
            redemption.value?.created_at,
    );
const formatNum = (n: any) =>
    new Intl.NumberFormat('en-US').format(Number(n || 0));
</script>

<template>
    <div>
        <Button
            @click="openDialog"
            variant="destructive"
            size="sm"
            class="h-7 px-2 text-[10px] font-bold tracking-wider uppercase"
        >
            <Trash2 class="mr-1 h-3 w-3" />Delete
        </Button>

        <div
            v-if="isOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4 text-left backdrop-blur-sm"
        >
            <div
                class="w-full max-w-lg overflow-hidden rounded-xl border border-destructive/20 bg-card shadow-2xl"
            >
                <!-- Header -->
                <div
                    class="flex items-center justify-between bg-destructive px-6 py-4 text-white"
                >
                    <h2
                        class="flex items-center gap-2 text-sm font-semibold tracking-wide"
                    >
                        <Trash2 class="h-4 w-4 opacity-80" /> Delete Redemption
                    </h2>
                    <button
                        @click="isOpen = false"
                        class="text-white/70 transition-colors hover:text-white"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-5 w-5"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                        >
                            <path
                                fill-rule="evenodd"
                                d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                clip-rule="evenodd"
                            />
                        </svg>
                    </button>
                </div>

                <!-- Loading -->
                <div
                    v-if="isLoading"
                    class="p-8 text-center text-sm text-muted-foreground"
                >
                    Loading redemption details...
                </div>

                <!-- Details -->
                <div v-else-if="redemption" class="space-y-5 p-6">
                    <!-- Warning -->
                    <div
                        class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50/80 p-4 dark:border-amber-800 dark:bg-amber-950/20"
                    >
                        <AlertTriangle
                            class="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-600 dark:text-amber-400"
                        />
                        <p
                            class="text-sm font-medium text-amber-800 dark:text-amber-300"
                        >
                            Deleting this redemption will
                            <strong>restore all consumed points</strong> back to
                            their original entry segments and remove the history
                            entry.
                        </p>
                    </div>

                    <!-- Redemption Summary -->
                    <div class="grid grid-cols-2 gap-3">
                        <div
                            class="rounded-lg border border-border/50 bg-muted/40 p-3"
                        >
                            <p
                                class="text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                            >
                                Date
                            </p>
                            <p class="mt-0.5 text-sm font-semibold">
                                {{ redemptionDate() }}
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-border/50 bg-muted/40 p-3"
                        >
                            <p
                                class="text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                            >
                                Reason
                            </p>
                            <p class="mt-0.5 text-sm font-semibold">
                                {{ redemption.reason || 'N/A' }}
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-red-200/50 bg-red-50/70 p-3 dark:border-red-800/50 dark:bg-red-950/20"
                        >
                            <p
                                class="text-[10px] font-bold tracking-wider text-red-600 uppercase dark:text-red-400"
                            >
                                Points Redeemed
                            </p>
                            <p
                                class="mt-0.5 text-lg font-bold text-red-700 dark:text-red-300"
                            >
                                {{ formatNum(redemption.points) }} pts
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-green-200/50 bg-green-50/70 p-3 dark:border-green-800/50 dark:bg-green-950/20"
                        >
                            <p
                                class="text-[10px] font-bold tracking-wider text-green-600 uppercase dark:text-green-400"
                            >
                                Monetary Value
                            </p>
                            <p
                                class="mt-0.5 text-lg font-bold text-green-700 dark:text-green-300"
                            >
                                ${{ Number(redemption.amount || 0).toFixed(2) }}
                            </p>
                        </div>
                    </div>

                    <!-- Segment Breakdown -->
                    <div
                        v-if="
                            redemption.details && redemption.details.length > 0
                        "
                    >
                        <p
                            class="mb-2 flex items-center gap-1.5 text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                        >
                            <RotateCcw class="h-3.5 w-3.5" /> Points to be
                            Restored ({{ redemption.details.length }} segment{{
                                redemption.details.length > 1 ? 's' : ''
                            }})
                        </p>
                        <div class="max-h-40 space-y-1.5 overflow-y-auto pr-1">
                            <div
                                v-for="detail in redemption.details"
                                :key="detail.id"
                                class="flex items-center justify-between rounded-md border border-border/40 bg-muted/30 px-3 py-2 text-xs"
                            >
                                <span class="font-medium text-muted-foreground">
                                    {{
                                        detail.source_type?.split('\\').pop() ||
                                        'Segment'
                                    }}
                                    <span class="ml-1 text-foreground/50"
                                        >#{{ detail.source_id }}</span
                                    >
                                </span>
                                <span class="font-bold text-primary"
                                    >+{{ formatNum(detail.points) }} pts</span
                                >
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div
                        class="flex justify-end space-x-3 border-t border-border/50 pt-2"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            class="h-9 px-5 font-semibold shadow-sm"
                            @click="isOpen = false"
                            >Cancel</Button
                        >
                        <Button
                            @click="submit"
                            class="h-9 bg-destructive px-5 font-bold tracking-wider text-white shadow-sm hover:bg-destructive/90"
                            :disabled="isSubmitting"
                        >
                            {{
                                isSubmitting
                                    ? 'Deleting...'
                                    : 'Confirm Delete & Restore'
                            }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
