<script setup lang="ts">
import { ref } from 'vue';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Plus } from 'lucide-vue-next';

const props = defineProps({
    gratitudeNumber: { type: String, required: true },
    availableBenefits: { type: Array as () => any[], default: () => [] },
});

const emit = defineEmits(['saved']);
const isOpen = ref(false);
const isSubmitting = ref(false);
const error = ref('');

const blank = () => ({
    benefit_id: '',
    benefit_name: '',
    benefit_key: '',
    description: '',
    journey_id: '',
    benefit_value: '',
    value_type: '',
    project_data_raw: '',
    date: new Date().toISOString().split('T')[0],
    status: 'active',
    notes: '',
});

const form = ref(blank());

const onBenefitSelect = () => {
    const selected = props.availableBenefits.find(
        (b: any) => String(b.id) === String(form.value.benefit_id),
    );
    if (selected) {
        if (!form.value.benefit_name) form.value.benefit_name = selected.name;
        if (!form.value.benefit_key)
            form.value.benefit_key = selected.benefit_key ?? '';
        form.value.benefit_value =
            selected.formatted_value ?? selected.value ?? '';
        form.value.value_type = selected.rule_key ?? selected.value_type ?? '';
        if (!form.value.description) {
            form.value.description =
                selected.description ?? selected.benefit_description ?? '';
        }
    }
};

const submit = async () => {
    error.value = '';
    isSubmitting.value = true;
    try {
        let project_data: any = null;
        if (form.value.project_data_raw.trim()) {
            try {
                project_data = JSON.parse(form.value.project_data_raw);
            } catch {
                /* leave null */
            }
        }
        const payload: any = { ...form.value, project_data };
        delete payload.project_data_raw;
        if (!payload.benefit_id) delete payload.benefit_id;
        if (!payload.journey_id) delete payload.journey_id;
        if (!payload.benefit_key) delete payload.benefit_key;

        await axios.post(
            `/internal-api/gratitude/${props.gratitudeNumber}/earned-benefits`,
            payload,
        );
        isOpen.value = false;
        form.value = blank();
        emit('saved');
    } catch (err: any) {
        error.value =
            err?.response?.data?.message ?? 'Failed to save. Please try again.';
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <div>
        <Button
            @click="isOpen = true"
            variant="secondary"
            size="sm"
            class="flex h-8 items-center gap-1.5 text-xs font-bold tracking-wider uppercase"
        >
            <Plus class="h-3.5 w-3.5" /> Add Benefit
        </Button>

        <div
            v-if="isOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4 text-left backdrop-blur-sm"
        >
            <div
                class="flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-xl border border-border bg-card shadow-2xl"
            >
                <!-- Header -->
                <div
                    class="flex shrink-0 items-center justify-between bg-primary px-6 py-4 text-primary-foreground"
                >
                    <h2
                        class="flex items-center gap-2 text-sm font-semibold tracking-wide"
                    >
                        <Plus class="h-4 w-4 opacity-80" /> Record Earned
                        Benefit
                    </h2>
                    <button
                        @click="isOpen = false"
                        class="opacity-70 transition-opacity hover:opacity-100"
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

                <form
                    @submit.prevent="submit"
                    class="space-y-4 overflow-y-auto p-6"
                >
                    <!-- Benefit picker -->
                    <div v-if="availableBenefits.length">
                        <Label
                            >Benefit Template
                            <span class="font-normal text-muted-foreground"
                                >(optional)</span
                            ></Label
                        >
                        <select
                            v-model="form.benefit_id"
                            @change="onBenefitSelect"
                            class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
                        >
                            <option value="">— Select a benefit —</option>
                            <option
                                v-for="b in availableBenefits"
                                :key="b.id"
                                :value="b.id"
                            >
                                {{ b.name }}
                            </option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <Label
                                >Benefit Name
                                <span class="text-destructive">*</span></Label
                            >
                            <Input
                                v-model="form.benefit_name"
                                placeholder="e.g. Room Upgrade"
                                required
                                class="mt-1"
                            />
                        </div>
                        <div>
                            <Label>Benefit Value</Label>
                            <Input
                                v-model="form.benefit_value"
                                placeholder="e.g. 1 Night Free"
                                class="mt-1"
                            />
                        </div>
                        <div>
                            <Label>Value Type</Label>
                            <select
                                v-model="form.value_type"
                                class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
                            >
                                <option value="">— None —</option>
                                <option value="amount">Amount</option>
                                <option value="percentage">Percentage</option>
                                <option value="points">Points</option>
                                <option value="item">Item / Perk</option>
                                <option value="nights">Nights</option>
                                <option value="per_person">
                                    Amount per person
                                </option>
                                <option value="per_room">
                                    Amount per room
                                </option>
                                <option value="miles">Miles</option>
                                <option value="points_per_dollar">
                                    Points per dollar
                                </option>
                                <option value="referral_points">
                                    Referral points
                                </option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <Label
                            >Description
                            <span class="text-destructive">*</span></Label
                        >
                        <textarea
                            v-model="form.description"
                            rows="2"
                            required
                            class="mt-1 w-full resize-none rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
                            placeholder="Details about this benefit usage…"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <Label
                                >Date
                                <span class="text-destructive">*</span></Label
                            >
                            <Input
                                type="date"
                                v-model="form.date"
                                required
                                class="mt-1"
                            />
                        </div>
                        <div>
                            <Label
                                >Journey ID
                                <span class="font-normal text-muted-foreground"
                                    >(optional)</span
                                ></Label
                            >
                            <Input
                                type="number"
                                v-model="form.journey_id"
                                placeholder="Journey ID"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div>
                        <Label
                            >Project Data
                            <span class="font-normal text-muted-foreground"
                                >(JSON, optional)</span
                            ></Label
                        >
                        <textarea
                            v-model="form.project_data_raw"
                            rows="2"
                            class="mt-1 w-full resize-none rounded-md border border-input bg-background px-3 py-2 font-mono text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
                            placeholder='{"projectNumber": "P-001", "name": "The Lodge"}'
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <Label>Status</Label>
                            <select
                                v-model="form.status"
                                class="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
                            >
                                <option value="active">Active</option>
                                <option value="used">Used</option>
                                <option value="expired">Expired</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div>
                            <Label
                                >Benefit Key
                                <span class="font-normal text-muted-foreground"
                                    >(optional)</span
                                ></Label
                            >
                            <Input
                                v-model="form.benefit_key"
                                placeholder="e.g. room_upgrade"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <div>
                        <Label
                            >Notes
                            <span class="font-normal text-muted-foreground"
                                >(optional)</span
                            ></Label
                        >
                        <textarea
                            v-model="form.notes"
                            rows="2"
                            class="mt-1 w-full resize-none rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:ring-1 focus:ring-ring focus:outline-none"
                        />
                    </div>

                    <p
                        v-if="error"
                        class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                    >
                        {{ error }}
                    </p>

                    <div
                        class="flex justify-end space-x-3 border-t border-border/50 pt-2"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            class="h-9 px-5 font-semibold"
                            @click="isOpen = false"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            class="h-9 px-5 font-bold tracking-wider"
                            :disabled="isSubmitting"
                        >
                            {{ isSubmitting ? 'Saving…' : 'Save Benefit' }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
