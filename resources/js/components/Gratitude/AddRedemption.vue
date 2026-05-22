<script setup lang="ts">
import { ref, computed } from 'vue';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { History, DollarSign } from 'lucide-vue-next';

const props = defineProps({
    gratitudeNumber: { type: String, required: true },
    usablePoints: { type: Number, default: 0 },
    pointsPerDollar: { type: Number, default: 35 },
    partnerPointsPerDollar: { type: Number, default: 35 },
    level: { type: String, default: 'Explorer' },
    journeys: { type: Array, default: () => [] },
});

const emit = defineEmits(['saved']);
const isOpen = ref(false);
const isSubmitting = ref(false);
const todayDate = () => new Date().toISOString().split('T')[0];

const form = ref({
    redemption_type: 'partner',
    journey_id: '' as string | number,
    date: todayDate(),
    points: 0,
    amount: '' as string | number,
    reason: 'Partner Redemption',
});

const selectedRate = computed(() => {
    return form.value.redemption_type === 'partner'
        ? props.partnerPointsPerDollar
        : props.pointsPerDollar;
});
const journeyOptions = computed(() => props.journeys as any[]);
const selectedJourney = computed(() =>
    journeyOptions.value.find(
        (journey: any) =>
            String(journey.journey_id || journey.id) ===
            String(form.value.journey_id),
    ),
);

// Computed estimated value based on level rate
const estimatedValue = computed(() => {
    const pts = Number(form.value.points) || 0;
    if (pts <= 0 || !selectedRate.value) return '0.00';
    return (pts / selectedRate.value).toFixed(2);
});

const maxRedeemable = computed(() => props.usablePoints);
const isTodayRedemption = computed(() => form.value.date === todayDate());
const isInsufficient = computed(
    () =>
        isTodayRedemption.value &&
        Number(form.value.points) > maxRedeemable.value,
);
const needsJourney = computed(() => form.value.redemption_type === 'journey');
const canSubmit = computed(
    () =>
        Number(form.value.points) > 0 &&
        !!form.value.date &&
        !isInsufficient.value &&
        (!needsJourney.value || !!form.value.journey_id),
);

const setMaxPoints = () => {
    form.value.points = maxRedeemable.value;
};

const submit = async () => {
    if (!canSubmit.value) return;

    isSubmitting.value = true;
    try {
        await axios.post(
            `/internal-api/gratitude/${props.gratitudeNumber}/redeem`,
            {
                points: form.value.points,
                amount: form.value.amount || estimatedValue.value,
                date: form.value.date,
                reason: form.value.reason,
                redemption_type: form.value.redemption_type,
                journey_id:
                    form.value.redemption_type === 'journey'
                        ? form.value.journey_id
                        : null,
                journey_data:
                    form.value.redemption_type === 'journey'
                        ? selectedJourney.value?.raw ||
                          selectedJourney.value ||
                          null
                        : null,
            },
        );
        isOpen.value = false;
        form.value.redemption_type = 'partner';
        form.value.journey_id = '';
        form.value.date = todayDate();
        form.value.points = 0;
        form.value.amount = '';
        form.value.reason = 'Partner Redemption';
        emit('saved');
    } catch (error: any) {
        console.error('Error redeeming points', error);
        alert(
            error.response?.data?.message ||
                'Failed to redeem points. Make sure you have enough useable points.',
        );
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <div>
        <Button
            @click="isOpen = true"
            variant="default"
            class="flex h-10 items-center gap-2 rounded-lg bg-amber-600 px-6 text-xs font-bold tracking-wider text-white uppercase shadow-md transition-all hover:bg-amber-700"
        >
            <History class="h-4 w-4" /> Redeem Points
        </Button>

        <div
            v-if="isOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4 text-left backdrop-blur-sm"
        >
            <div
                class="w-full max-w-md overflow-hidden rounded-xl border border-border bg-card shadow-2xl"
            >
                <div
                    class="flex items-center justify-between bg-amber-600 px-6 py-4 text-white"
                >
                    <h2
                        class="flex items-center gap-2 text-sm font-semibold tracking-wide"
                    >
                        <History class="h-4 w-4 opacity-80" /> Redeem Points
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

                <!-- Info Banner -->
                <div
                    class="flex items-center justify-between gap-4 border-b border-amber-200 bg-amber-50 px-6 py-3 dark:border-amber-800 dark:bg-amber-950/30"
                >
                    <div>
                        <p
                            class="text-[10px] font-bold tracking-wider text-amber-700 uppercase dark:text-amber-400"
                        >
                            Available Points
                        </p>
                        <p
                            class="text-lg font-bold text-amber-800 dark:text-amber-200"
                        >
                            {{ usablePoints.toLocaleString() }} pts
                        </p>
                    </div>
                    <div class="text-right">
                        <p
                            class="text-[10px] font-bold tracking-wider text-amber-700 uppercase dark:text-amber-400"
                        >
                            Rate ({{ level }})
                        </p>
                        <p
                            class="text-sm font-bold text-amber-800 dark:text-amber-200"
                        >
                            {{ selectedRate }} pts = $1
                        </p>
                    </div>
                </div>

                <form @submit.prevent="submit" class="space-y-5 p-6">
                    <div class="space-y-1.5">
                        <Label class="text-xs font-semibold text-foreground/80"
                            >Redemption Type</Label
                        >
                        <select
                            v-model="form.redemption_type"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                        >
                            <option value="partner">Partner Purchase</option>
                            <option value="journey">Journey</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div
                        v-if="form.redemption_type === 'journey'"
                        class="space-y-1.5"
                    >
                        <Label class="text-xs font-semibold text-foreground/80"
                            >Journey</Label
                        >
                        <select
                            v-model="form.journey_id"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                            required
                        >
                            <option value="" disabled>Select a journey</option>
                            <option
                                v-for="journey in journeyOptions"
                                :key="`${journey.guest_id || 'account'}-${journey.journey_id || journey.id}`"
                                :value="journey.journey_id || journey.id"
                            >
                                {{
                                    journey.guest_name
                                        ? `${journey.guest_name} - `
                                        : ''
                                }}{{
                                    journey.label ||
                                    `Journey #${journey.journey_id || journey.id}`
                                }}
                            </option>
                        </select>
                    </div>

                    <div class="space-y-1.5">
                        <Label class="text-xs font-semibold text-foreground/80"
                            >Redemption Date</Label
                        >
                        <Input
                            type="date"
                            v-model="form.date"
                            required
                            class="h-10"
                        />
                    </div>

                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <Label
                                class="text-xs font-semibold text-foreground/80"
                                >Points to Redeem</Label
                            >
                            <button
                                type="button"
                                @click="setMaxPoints"
                                class="text-[10px] font-bold tracking-wider text-amber-600 uppercase hover:underline"
                            >
                                Use Max ({{ usablePoints.toLocaleString() }})
                            </button>
                        </div>
                        <Input
                            type="number"
                            v-model="form.points"
                            required
                            class="h-10 text-lg font-bold"
                            min="1"
                            :max="isTodayRedemption ? maxRedeemable : undefined"
                        />
                        <p
                            v-if="isInsufficient"
                            class="text-xs font-bold text-destructive"
                        >
                            Exceeds available usable points.
                        </p>
                        <p v-else class="text-xs text-muted-foreground">
                            Points are consumed FIFO — soonest expiring first.
                        </p>
                    </div>

                    <!-- Computed Monetary Value Display -->
                    <div
                        class="flex items-center justify-between rounded-lg border border-green-200 bg-green-50/70 px-4 py-3 dark:border-green-800 dark:bg-green-950/20"
                    >
                        <div
                            class="flex items-center gap-2 text-green-700 dark:text-green-400"
                        >
                            <DollarSign class="h-4 w-4" />
                            <span
                                class="text-xs font-bold tracking-wider uppercase"
                                >Estimated Value</span
                            >
                        </div>
                        <span
                            class="text-xl font-bold text-green-700 dark:text-green-300"
                            >${{ estimatedValue }}</span
                        >
                    </div>

                    <div class="space-y-1.5">
                        <Label class="text-xs font-semibold text-foreground/80"
                            >Override Amount (Optional)</Label
                        >
                        <Input
                            type="number"
                            step="0.01"
                            v-model="form.amount"
                            class="h-10"
                            placeholder="Leave blank to use calculated value"
                        />
                    </div>

                    <div class="space-y-1.5">
                        <Label class="text-xs font-semibold text-foreground/80"
                            >Reason</Label
                        >
                        <Input v-model="form.reason" required class="h-10" />
                    </div>

                    <div
                        class="mt-6 flex justify-end space-x-3 border-t border-border pt-4"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            class="h-9 px-6 font-semibold shadow-sm"
                            @click="isOpen = false"
                            >CANCEL</Button
                        >
                        <Button
                            type="submit"
                            class="h-9 bg-amber-600 px-6 font-semibold tracking-wider text-white shadow-sm hover:bg-amber-700"
                            :disabled="isSubmitting || !canSubmit"
                        >
                            {{
                                isSubmitting
                                    ? 'PROCESSING...'
                                    : 'CONFIRM REDEMPTION'
                            }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
