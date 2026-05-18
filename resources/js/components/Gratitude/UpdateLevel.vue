<script setup lang="ts">
import { ref, watch } from 'vue';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { X } from 'lucide-vue-next';

const props = defineProps({
    level: {
        type: Object,
        required: true
    }
});

const emit = defineEmits(['saved']);
const isOpen = ref(false);
const form = ref<any>({});

const levelImage = ref<File | null>(null);
const levelIcon = ref<File | null>(null);

type Rule = { _key: number; name: string; value: string; status: boolean; value_type: string };
let ruleCounter = 0;
const rules = ref<Rule[]>([]);

// Deep-clone and pre-fill every time the modal opens
watch(isOpen, (newVal) => {
    if (newVal) {
        form.value = {
            ...props.level,
            earned_expire_days: props.level.earned_expire_days ?? 730,
            bonus_expire_days: props.level.bonus_expire_days ?? 730,
            partner_points_per_dollar: props.level.partner_points_per_dollar ?? props.level.redemption_points_per_dollar ?? 35,
            level_interval_years: props.level.level_interval_years ?? 2,
            min_journeys: props.level.min_journeys ?? 0,
        };
        levelImage.value = null;
        levelIcon.value = null;

        // Deep-clone rules so we never mutate the prop
        const raw = props.level.level_rules
            ? (typeof props.level.level_rules === 'string'
                ? JSON.parse(props.level.level_rules)
                : props.level.level_rules)
            : [];
        rules.value = (raw as any[]).map((r: any) => ({ ...r, _key: ++ruleCounter }));
    }
});

const handleImageUpload = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files?.length) levelImage.value = target.files[0];
};

const handleIconUpload = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files?.length) levelIcon.value = target.files[0];
};

const addRule = () => {
    rules.value.push({ _key: ++ruleCounter, name: '', value: '', status: true, value_type: 'string' });
};
const removeRule = (index: number) => {
    rules.value.splice(index, 1);
};

const submit = async () => {
    try {
        const formData = new FormData();
        formData.append('_method', 'PUT');
        formData.append('name', form.value.name);
        formData.append('min_points', String(form.value.min_points));
        if (form.value.max_points !== '' && form.value.max_points !== null) {
            formData.append('max_points', String(form.value.max_points));
        }
        formData.append('status', String(form.value.status));
        formData.append('redemption_points_per_dollar', String(form.value.redemption_points_per_dollar || 35));
        formData.append('partner_points_per_dollar', String(form.value.partner_points_per_dollar || form.value.redemption_points_per_dollar || 35));
        formData.append('earned_expire_days', String(form.value.earned_expire_days || 730));
        formData.append('bonus_expire_days', String(form.value.bonus_expire_days || 730));
        formData.append('level_interval_years', String(form.value.level_interval_years || 2));
        formData.append('min_journeys', String(form.value.min_journeys || 0));

        if (levelImage.value) formData.append('level_image', levelImage.value);
        if (levelIcon.value) formData.append('level_icon', levelIcon.value);

        // Always send level_rules — empty string signals "clear all rules"
        const rulesPayload = rules.value.map(({ _key, ...rest }) => rest);
        formData.append('level_rules', rulesPayload.length > 0 ? JSON.stringify(rulesPayload) : '');

        await axios.post(`/internal-api/gratitude/levels/${props.level.id}`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        });

        isOpen.value = false;
        emit('saved');
    } catch (error) {
        console.error('Error updating level', error);
    }
};
</script>

<template>
    <div>
        <Button @click="isOpen = true" variant="outline" size="sm">Edit</Button>

        <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 overflow-y-auto">
            <div class="bg-card w-full max-w-2xl p-6 rounded-lg shadow-lg border border-border m-4 max-h-[90vh] overflow-y-auto text-left">
                <h2 class="text-xl font-bold mb-4">Update Gratitude Level</h2>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <Label>Name</Label>
                            <Input v-model="form.name" required />
                        </div>
                        <div class="flex items-end space-x-2 pb-2">
                            <input type="checkbox" v-model="form.status" id="status_edit" class="rounded border-input text-primary focus:ring-primary h-4 w-4" />
                            <Label for="status_edit">Active</Label>
                        </div>
                        <div>
                            <Label>Min Points</Label>
                            <Input type="number" v-model="form.min_points" required />
                        </div>
                        <div>
                            <Label>Max Points (Leave blank for ∞)</Label>
                            <Input type="number" v-model="form.max_points" />
                        </div>
                        <div>
                            <Label>Journey Points Per Dollar</Label>
                            <Input type="number" step="0.01" min="1" v-model="form.redemption_points_per_dollar" required />
                            <p class="text-xs text-muted-foreground mt-1">Points needed for $1 on journey redemptions.</p>
                        </div>
                        <div>
                            <Label>Partner Points Per Dollar</Label>
                            <Input type="number" step="0.01" min="1" v-model="form.partner_points_per_dollar" required />
                            <p class="text-xs text-muted-foreground mt-1">Points needed for $1 with partners.</p>
                        </div>
                        <div>
                            <Label>Earned Points Expire After (Days)</Label>
                            <Input type="number" min="1" v-model="form.earned_expire_days" required />
                        </div>
                        <div>
                            <Label>Bonus Points Expire After (Days)</Label>
                            <Input type="number" min="1" v-model="form.bonus_expire_days" required />
                        </div>
                        <div>
                            <Label>Cycle Length (Years)</Label>
                            <Input type="number" min="1" v-model="form.level_interval_years" required />
                        </div>
                        <div>
                            <Label>Minimum Journeys Per Cycle</Label>
                            <Input type="number" min="0" v-model="form.min_journeys" required />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <Label>Level Image (Leave blank to keep current)</Label>
                            <Input type="file" accept="image/*" @change="handleImageUpload" />
                        </div>
                        <div>
                            <Label>Level Icon (Leave blank to keep current)</Label>
                            <Input type="file" accept="image/*" @change="handleIconUpload" />
                        </div>
                    </div>

                    <div class="mt-6 border-t pt-4">
                        <div class="flex items-center justify-between mb-3">
                            <Label class="text-base font-semibold">Rules</Label>
                            <Button type="button" variant="outline" size="sm" @click="addRule">+ Add Rule</Button>
                        </div>
                        <div v-for="(rule, index) in rules" :key="rule._key" class="p-4 border rounded-md mb-2 bg-muted/10">
                            <div class="grid grid-cols-12 gap-2 items-end">
                                <div class="col-span-3">
                                    <Label class="text-xs">Rule Name</Label>
                                    <Input v-model="rule.name" placeholder="e.g., discount_rate" required />
                                </div>
                                <div class="col-span-3">
                                    <Label class="text-xs">Value Type</Label>
                                    <select v-model="rule.value_type" class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                        <option value="string">String</option>
                                        <option value="number">Number</option>
                                        <option value="boolean">Boolean</option>
                                    </select>
                                </div>
                                <div class="col-span-3">
                                    <Label class="text-xs">Value</Label>
                                    <select v-if="rule.value_type === 'boolean'" v-model="rule.value" class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                        <option value="true">True</option>
                                        <option value="false">False</option>
                                    </select>
                                    <Input v-else-if="rule.value_type === 'number'" type="number" v-model="rule.value" required />
                                    <Input v-else v-model="rule.value" required />
                                </div>
                                <div class="col-span-2 flex flex-col items-center gap-1">
                                    <Label class="text-xs">Active</Label>
                                    <input type="checkbox" v-model="rule.status" class="rounded border-input text-primary focus:ring-primary h-4 w-4 mt-1" />
                                </div>
                                <div class="col-span-1 flex justify-end pb-1">
                                    <Button type="button" variant="ghost" size="icon" class="h-8 w-8 text-destructive" @click="removeRule(index)">
                                        <X class="w-4 h-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>
                        <p v-if="rules.length === 0" class="text-sm text-muted-foreground text-center py-2 border border-dashed rounded-md">No rules added.</p>
                    </div>

                    <div class="flex justify-end space-x-2 mt-6">
                        <Button type="button" variant="outline" @click="isOpen = false">Cancel</Button>
                        <Button type="submit">Save</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
