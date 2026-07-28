<script setup lang="ts">
import { ref, watch } from 'vue';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps({
    benefit: {
        type: Object,
        required: true
    }
});

const emit = defineEmits(['saved']);
const isOpen = ref(false);
const form = ref({ ...props.benefit });

watch(isOpen, (newVal) => {
    if (newVal) {
        form.value = { ...props.benefit };
    }
});

const submit = async () => {
    try {
        await axios.put(`/internal-api/gratitude/benefits/${props.benefit.id}`, form.value);
        isOpen.value = false;
        emit('saved');
    } catch (error) {
        console.error('Error updating benefit', error);
    }
};

const openModal = () => {
    isOpen.value = true;
};
</script>

<template>
    <div>
        <Button @click="openModal" variant="outline" size="sm">Edit</Button>

        <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 text-left">
            <div class="bg-card w-full max-w-md p-6 rounded-lg shadow-lg border border-border">
                <h2 class="text-xl font-bold mb-4">Edit Base Benefit</h2>
                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <Label>Name</Label>
                        <Input v-model="form.name" required />
                    </div>
                    <div>
                        <Label>Benefit Key</Label>
                        <Input v-model="form.benefit_key" placeholder="e.g. journey_payment" class="font-mono" />
                        <p class="text-xs text-muted-foreground mt-1">Unique programmatic identifier used for feature gating (snake_case).</p>
                    </div>
                    <div>
                        <Label>Description</Label>
                        <Input v-model="form.description" />
                    </div>
                    <div>
                        <Label>Default Rule</Label>
                        <select v-model="form.type" class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                            <option v-if="form.type && !['text', 'per_person', 'per_room', 'miles', 'points_per_dollar', 'referral_points'].includes(form.type)" :value="form.type">
                                Legacy: {{ form.type }}
                            </option>
                            <option value="text">Text / Included</option>
                            <option value="per_person">Amount per person</option>
                            <option value="per_room">Amount per room</option>
                            <option value="miles">Miles</option>
                            <option value="points_per_dollar">Points per dollar</option>
                            <option value="referral_points">Referral points</option>
                        </select>
                    </div>
                    <div class="flex items-center space-x-2">
                        <input type="checkbox" v-model="form.is_active" id="edit_benefit_active" class="rounded border-input text-primary focus:ring-primary h-4 w-4" />
                        <Label for="edit_benefit_active">Active</Label>
                    </div>
                    <div class="flex justify-end space-x-2 mt-6">
                        <Button type="button" variant="outline" @click="isOpen = false">Cancel</Button>
                        <Button type="submit">Update Benefit</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
