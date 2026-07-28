<script setup lang="ts">
import { ref } from 'vue';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const emit = defineEmits(['saved']);
const isOpen = ref(false);
const form = ref({
    name: '',
    benefit_key: '',
    description: '',
    type: 'text',
    is_active: true,
});

const submit = async () => {
    try {
        await axios.post('/internal-api/gratitude/benefits', form.value);
        isOpen.value = false;
        form.value = { name: '', benefit_key: '', description: '', type: 'text', is_active: true };
        emit('saved');
    } catch (error) {
        console.error('Error saving benefit', error);
    }
};

const openModal = () => {
    isOpen.value = true;
};
</script>

<template>
    <div>
        <Button @click="openModal" variant="default">Add Base Benefit</Button>

        <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-card w-full max-w-md p-6 rounded-lg shadow-lg border border-border">
                <h2 class="text-xl font-bold mb-4">Add Base Benefit</h2>
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
                            <option value="text">Text / Included</option>
                            <option value="per_person">Amount per person</option>
                            <option value="per_room">Amount per room</option>
                            <option value="miles">Miles</option>
                            <option value="points_per_dollar">Points per dollar</option>
                            <option value="referral_points">Referral points</option>
                        </select>
                    </div>
                    <div class="flex items-center space-x-2">
                        <input type="checkbox" v-model="form.is_active" id="benefit_active" class="rounded border-input text-primary focus:ring-primary h-4 w-4" />
                        <Label for="benefit_active">Active</Label>
                    </div>
                    <div class="flex justify-end space-x-2 mt-6">
                        <Button type="button" variant="outline" @click="isOpen = false">Cancel</Button>
                        <Button type="submit">Save Benefit</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
