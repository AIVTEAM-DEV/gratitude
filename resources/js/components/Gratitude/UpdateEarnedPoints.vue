<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps({
    point: { type: Object, required: true },
    gratitudeNumber: { type: String, required: true },
    expireDays: { type: Number, default: 730 },
});

const emit = defineEmits(['saved']);
const isOpen = ref(false);
const toDateInput = (val: string | null | undefined) => val ? val.split('T')[0] : '';
const hasCancellation = computed(() => Number(props.point.cancelled_points || 0) > 0 || !!props.point.cancel_id);

const form = ref({
    date: toDateInput(props.point.usable_date || props.point.useable_date || props.point.date),
    journey_id: props.point.journey_id,
    category: props.point.category,
    points: props.point.points,
    amount: props.point.amount,
    description: props.point.description,
    expires_at: toDateInput(props.point.expires_at),
});

watch(isOpen, (open) => {
    if (!open) {
        return;
    }

    form.value = {
        date: toDateInput(props.point.usable_date || props.point.useable_date || props.point.date),
        journey_id: props.point.journey_id,
        category: props.point.category,
        points: props.point.points,
        amount: props.point.amount,
        description: props.point.description,
        expires_at: toDateInput(props.point.expires_at),
    };
});

const submit = async () => {
    try {
        await axios.put(`/internal-api/gratitude/${props.gratitudeNumber}/earned/${props.point.id}`, form.value);
        isOpen.value = false;
        emit('saved');
    } catch (error) {
        console.error('Error updating earned points', error);
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
                        <Label>Effective Date</Label>
                        <Input type="date" v-model="form.date" required />
                    </div>
                    <div>
                        <Label>Journey ID</Label>
                        <Input type="number" v-model="form.journey_id" required />
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
                        <Button type="submit">{{ hasCancellation ? 'Restore & Update' : 'Update' }}</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
