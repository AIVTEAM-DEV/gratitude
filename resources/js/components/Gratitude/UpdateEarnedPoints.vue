<script setup lang="ts">
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps({
    point: { type: Object, required: true },
    gratitudeNumber: { type: String, required: true },
    expireDays: { type: Number, default: 730 },
    journeys: { type: Array, default: () => [] },
});

const emit = defineEmits(['saved']);
const isOpen = ref(false);
const isSubmitting = ref(false);
const toDateInput = (val: string | null | undefined) => val ? val.split('T')[0] : '';
const hasCancellation = computed(() => Number(props.point.cancelled_points || 0) > 0 || !!props.point.cancel_id);
const pointEarningType = () =>
    props.point.points_breakdown?.earning_type || (props.point.journey_id ? 'journey' : 'other');

const form = ref({
    earning_type: pointEarningType(),
    date: toDateInput(props.point.usable_date || props.point.useable_date || props.point.date),
    journey_id: props.point.journey_id ?? '',
    category: props.point.category,
    points: props.point.points,
    amount: props.point.amount,
    description: props.point.description,
    expires_at: toDateInput(props.point.expires_at),
});

const journeyOptions = computed(() => {
    const options = [...props.journeys] as any[];
    const currentJourneyId = props.point.journey_id;

    if (
        currentJourneyId
        && !options.some((journey: any) => String(journey.journey_id || journey.id) === String(currentJourneyId))
    ) {
        const raw = props.point.project_data || {};
        options.push({
            id: currentJourneyId,
            journey_id: currentJourneyId,
            label: raw.name || `Journey #${currentJourneyId}`,
            endDate: raw.endDate || raw.end_date || raw.returnDate || raw.return_date || toDateInput(props.point.date),
            raw,
        });
    }

    return options;
});
const selectedJourney = computed(() =>
    journeyOptions.value.find((journey: any) => String(journey.journey_id || journey.id) === String(form.value.journey_id)),
);
const selectedJourneyEndDate = computed(() =>
    selectedJourney.value?.endDate
    || selectedJourney.value?.raw?.endDate
    || selectedJourney.value?.raw?.end_date
    || selectedJourney.value?.raw?.returnDate
    || selectedJourney.value?.raw?.return_date
    || '',
);
const isJourneyEntry = computed(() => form.value.earning_type === 'journey');
const canSubmit = computed(() =>
    !isJourneyEntry.value || (!!form.value.journey_id && !!selectedJourneyEndDate.value),
);

watch(isOpen, (open) => {
    if (!open) {
        return;
    }

    form.value = {
        earning_type: pointEarningType(),
        date: toDateInput(props.point.usable_date || props.point.useable_date || props.point.date),
        journey_id: props.point.journey_id ?? '',
        category: props.point.category,
        points: props.point.points,
        amount: props.point.amount,
        description: props.point.description,
        expires_at: toDateInput(props.point.expires_at),
    };
});

const submit = async () => {
    if (!canSubmit.value) return;

    isSubmitting.value = true;
    try {
        const payload: any = { ...form.value };

        if (isJourneyEntry.value) {
            payload.date = selectedJourneyEndDate.value;
            payload.journey_end_date = selectedJourneyEndDate.value;
            payload.project_data = selectedJourney.value?.raw || selectedJourney.value || null;
        } else {
            payload.journey_id = null;
            payload.journey_end_date = null;
            payload.project_data = null;
        }

        await axios.put(`/internal-api/gratitude/${props.gratitudeNumber}/earned/${props.point.id}`, payload);
        isOpen.value = false;
        emit('saved');
    } catch (error) {
        console.error('Error updating earned points', error);
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <div>
        <Button @click="isOpen = true" variant="outline" size="sm">
            {{ hasCancellation ? 'Restore & Edit' : 'Edit' }}
        </Button>

        <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 text-left px-4">
            <div class="bg-card w-full max-w-lg p-6 rounded-lg shadow-lg border border-border">
                <h2 class="text-xl font-bold mb-4">{{ hasCancellation ? 'Restore and Update Earned Points' : 'Update Earned Points' }}</h2>
                <div v-if="hasCancellation" class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-800/50 dark:bg-red-950/30 dark:text-red-300">
                    Saving will remove the cancellation from this entry first, then apply the points, date, and expiry shown below.
                </div>
                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <Label>Earned Through</Label>
                        <select v-model="form.earning_type" class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground" required>
                            <option value="journey">Journey</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div v-if="isJourneyEntry">
                        <Label>Journey</Label>
                        <select
                            v-model="form.journey_id"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground"
                            required
                        >
                            <option value="" disabled>Select a journey</option>
                            <option v-for="journey in journeyOptions" :key="journey.journey_id || journey.id" :value="journey.journey_id || journey.id">
                                {{ journey.label || `Journey #${journey.journey_id || journey.id}` }}
                            </option>
                        </select>
                    </div>
                    <div v-if="!isJourneyEntry">
                        <Label>Effective Date</Label>
                        <Input type="date" v-model="form.date" required />
                    </div>
                    <div v-else>
                        <Label>Effective Date</Label>
                        <div class="flex h-10 items-center rounded-md border border-input bg-muted/30 px-3 text-sm text-muted-foreground">
                            {{ selectedJourneyEndDate || 'Select a journey with an end date' }}
                        </div>
                    </div>
                    <div>
                        <Label>Category</Label>
                        <Input v-model="form.category" required />
                    </div>
                    <div>
                        <Label>Amount</Label>
                        <Input type="number" step="0.01" v-model="form.amount" required />
                    </div>
                    <div>
                        <Label>Points</Label>
                        <Input type="number" v-model="form.points" required />
                    </div>
                    <div>
                        <Label>Description</Label>
                        <Input v-model="form.description" required />
                    </div>
                    <div>
                        <Label>Expiry Date <span class="text-muted-foreground font-normal">(override)</span></Label>
                        <Input type="date" v-model="form.expires_at" />
                        <p class="text-xs text-muted-foreground mt-1">Leave unchanged to keep the existing expiry. Auto-calculated as {{ props.expireDays }} days from the selected date if cleared.</p>
                    </div>
                    <div class="flex justify-end space-x-2 mt-6">
                        <Button type="button" variant="outline" @click="isOpen = false">Cancel</Button>
                        <Button type="submit" :disabled="isSubmitting || !canSubmit">
                            {{ isSubmitting ? 'Saving...' : (hasCancellation ? 'Restore & Update' : 'Update') }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
