<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import BenefitRuleFields from '@/components/Gratitude/BenefitRuleFields.vue';

const props = defineProps({
    levels: { type: Array, required: true },
    benefits: { type: Array, required: false, default: () => [] },
});

const emit = defineEmits(['saved']);
const isOpen = ref(false);
const mode = ref('new'); // 'new' or 'existing'

const form = ref({
    existing_id: '',
    name: '',
    benefit_key: '',
    description: '',
    is_active: true,
    type: 'text',
    level_mappings: {} as Record<number, any>,
});

const unassignedBenefits = computed(() => {
    return props.benefits.filter((b: any) => {
        return !Object.values(b.levels || {}).some((l: any) => l.has_benefit);
    });
});

const supportedRules = [
    'text',
    'per_person',
    'per_room',
    'miles',
    'points_per_dollar',
    'referral_points',
];

const resetForm = () => {
    form.value.existing_id = '';
    form.value.name = '';
    form.value.benefit_key = '';
    form.value.description = '';
    form.value.is_active = true;
    form.value.type = 'text';
    form.value.level_mappings = {};

    props.levels.forEach((l: any) => {
        form.value.level_mappings[l.id] = {
            enabled: false,
            value: '',
            description: '',
            value_type: 'text',
            calculation: { key: 'text' },
            is_active: true,
            web_status: true,
        };
    });
};

const openModal = () => {
    resetForm();
    mode.value = 'new';
    isOpen.value = true;
};

watch(
    () => form.value.level_mappings,
    (newVal) => {
        if (!newVal) return;
        Object.keys(newVal).forEach((key) => {
            if (newVal[key as any].is_active === false) {
                newVal[key as any].web_status = false;
            }
        });
    },
    { deep: true },
);

watch(
    () => form.value.existing_id,
    (benefitId) => {
        const selected = props.benefits.find(
            (benefit: any) => String(benefit.id) === String(benefitId),
        ) as any;
        const defaultRule = supportedRules.includes(selected?.type)
            ? selected.type
            : 'text';

        Object.values(form.value.level_mappings).forEach((mapping: any) => {
            mapping.calculation = {
                key: defaultRule,
                ...(['per_person', 'per_room'].includes(defaultRule)
                    ? { currency: 'USD' }
                    : {}),
            };
        });
    },
);

const submit = async () => {
    try {
        if (mode.value === 'existing') {
            if (!form.value.existing_id) {
                alert('Please select an existing benefit');
                return;
            }
            await axios.put(
                `/internal-api/gratitude/program-benefits/${form.value.existing_id}`,
                {
                    level_mappings: form.value.level_mappings,
                },
            );
        } else {
            if (!form.value.name.trim()) {
                alert('Please enter a name for the new base benefit');
                return;
            }
            const firstEnabledMapping = Object.values(
                form.value.level_mappings,
            ).find((mapping: any) => mapping.enabled) as any;
            form.value.type = firstEnabledMapping?.calculation?.key || 'text';
            await axios.post('/internal-api/gratitude/benefits', form.value);
        }

        isOpen.value = false;
        emit('saved');
    } catch (error) {
        console.error('Error saving benefit', error);
    }
};
</script>

<template>
    <div>
        <Button @click="openModal" size="sm">Add Benefit</Button>

        <div
            v-if="isOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 text-left"
        >
            <div
                class="m-4 max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-lg border border-border bg-card p-6 shadow-lg"
            >
                <h2 class="mb-4 text-xl font-bold">Add Benefit Assignments</h2>

                <div class="mb-6 flex space-x-6 border-b border-border pb-3">
                    <label class="flex cursor-pointer items-center space-x-2">
                        <input
                            type="radio"
                            v-model="mode"
                            value="new"
                            class="h-4 w-4 text-primary focus:ring-primary"
                        />
                        <span class="text-sm font-medium"
                            >Create New Base Benefit</span
                        >
                    </label>
                    <label class="flex cursor-pointer items-center space-x-2">
                        <input
                            type="radio"
                            v-model="mode"
                            value="existing"
                            class="h-4 w-4 text-primary focus:ring-primary"
                        />
                        <span class="text-sm font-medium text-foreground"
                            >Select Existing Benefit</span
                        >
                    </label>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <template v-if="mode === 'new'">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Name</Label>
                                <Input
                                    v-model="form.name"
                                    required
                                    placeholder="e.g. Priority Support"
                                />
                            </div>
                            <div>
                                <Label>Benefit Key</Label>
                                <Input
                                    v-model="form.benefit_key"
                                    placeholder="e.g. priority_support"
                                    class="font-mono"
                                />
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Unique snake_case key for feature gating.
                                </p>
                            </div>
                        </div>
                        <div>
                            <Label>Description</Label>
                            <Input
                                v-model="form.description"
                                placeholder="Optional details about this benefit"
                            />
                        </div>
                        <div class="flex items-center space-x-2 pt-2">
                            <input
                                type="checkbox"
                                v-model="form.is_active"
                                id="base_status"
                                class="h-4 w-4 rounded border-input text-primary"
                            />
                            <Label for="base_status">Base Active Status</Label>
                        </div>
                    </template>

                    <template v-else>
                        <div
                            class="rounded-lg border border-border bg-muted/30 p-4"
                        >
                            <Label>Select Unassigned Base Benefit</Label>
                            <select
                                v-model="form.existing_id"
                                required
                                class="mt-1 flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <option value="" disabled>
                                    Select an unassigned benefit...
                                </option>
                                <option
                                    v-for="b in unassignedBenefits as any[]"
                                    :key="b.id"
                                    :value="b.id"
                                >
                                    {{ b.name }}
                                </option>
                            </select>
                            <p
                                v-if="unassignedBenefits.length === 0"
                                class="mt-2 text-xs font-medium text-destructive"
                            >
                                There are no completely unassigned benefits
                                available. All imported benefits already have at
                                least one level assigned.
                            </p>
                            <p
                                v-else
                                class="mt-2 text-xs text-muted-foreground"
                            >
                                Select a benefit that was created or imported
                                but not yet mapped to any tier.
                            </p>
                        </div>
                    </template>

                    <div class="mt-6 border-t pt-4">
                        <h3 class="mb-2 font-semibold">
                            Level Assignments (Optional)
                        </h3>
                        <p class="mb-4 text-sm text-muted-foreground">
                            You can assign this benefit to levels right now, or
                            do it later.
                        </p>
                        <div
                            v-for="level in levels as any[]"
                            :key="level.id"
                            class="mb-2 rounded-md border border-border bg-muted/20 p-3"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <div class="flex items-center space-x-2">
                                    <input
                                        type="checkbox"
                                        v-model="
                                            form.level_mappings[level.id]
                                                .enabled
                                        "
                                        :id="'add_level_' + level.id"
                                        class="h-4 w-4 rounded border-input text-primary"
                                    />
                                    <Label
                                        :for="'add_level_' + level.id"
                                        class="font-bold"
                                        >{{ level.name }}</Label
                                    >
                                </div>
                            </div>
                            <div
                                v-if="form.level_mappings[level.id].enabled"
                                class="mt-3 space-y-3"
                            >
                                <BenefitRuleFields
                                    v-model="form.level_mappings[level.id]"
                                />
                                <div class="grid grid-cols-12 gap-3">
                                    <div class="col-span-12 sm:col-span-6">
                                        <Label
                                            class="text-xs text-muted-foreground"
                                            >Details/Description</Label
                                        >
                                        <Input
                                            v-model="
                                                form.level_mappings[level.id]
                                                    .description
                                            "
                                            size="sm"
                                        />
                                    </div>
                                    <div
                                        class="col-span-6 flex items-center space-x-2 pt-5 pl-2 sm:col-span-3"
                                    >
                                        <input
                                            type="checkbox"
                                            v-model="
                                                form.level_mappings[level.id]
                                                    .is_active
                                            "
                                            :id="'new_is_active_' + level.id"
                                            class="h-4 w-4 rounded border-input text-primary"
                                        />
                                        <Label
                                            :for="'new_is_active_' + level.id"
                                            class="text-xs"
                                            >System Status</Label
                                        >
                                    </div>
                                    <div
                                        class="col-span-6 flex items-center space-x-2 pt-5 sm:col-span-3"
                                    >
                                        <input
                                            type="checkbox"
                                            v-model="
                                                form.level_mappings[level.id]
                                                    .web_status
                                            "
                                            :disabled="
                                                !form.level_mappings[level.id]
                                                    .is_active
                                            "
                                            :id="'new_web_status_' + level.id"
                                            class="h-4 w-4 rounded border-input text-primary disabled:opacity-50"
                                        />
                                        <Label
                                            :for="'new_web_status_' + level.id"
                                            class="text-xs"
                                            :class="{
                                                'text-muted-foreground':
                                                    !form.level_mappings[
                                                        level.id
                                                    ].is_active,
                                            }"
                                            >Web Status</Label
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end space-x-2 border-t pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            @click="isOpen = false"
                            >Cancel</Button
                        >
                        <Button type="submit">Save</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
