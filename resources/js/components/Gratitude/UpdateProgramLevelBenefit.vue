<script setup lang="ts">
import { ref, watch } from 'vue';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import BenefitRuleFields from '@/components/Gratitude/BenefitRuleFields.vue';

const props = defineProps({
    benefit: { type: Object, required: true },
    levels: { type: Array, required: true },
});

const emit = defineEmits(['saved']);
const isOpen = ref(false);
const form = ref({
    level_mappings: {} as Record<number, any>,
});

const supportedRules = [
    'text',
    'per_person',
    'per_room',
    'miles',
    'points_per_dollar',
    'referral_points',
];

const openModal = () => {
    const defaultRule = supportedRules.includes(props.benefit.type)
        ? props.benefit.type
        : 'text';

    // Populate form with existing mappings
    props.levels.forEach((l: any) => {
        const pivot = props.benefit.levels[l.id];
        form.value.level_mappings[l.id] = {
            enabled: pivot?.has_benefit || false,
            value: pivot?.rule_value ?? pivot?.value ?? '',
            description: pivot?.description || '',
            value_type: pivot?.value_type || 'text',
            calculation: {
                key: pivot?.rule_key || pivot?.calculation?.key || defaultRule,
                ...(pivot?.currency || pivot?.calculation?.currency
                    ? {
                          currency:
                              pivot?.currency || pivot?.calculation?.currency,
                      }
                    : ['per_person', 'per_room'].includes(defaultRule)
                      ? { currency: 'USD' }
                      : {}),
            },
            is_active: pivot?.is_active ?? true,
            web_status: pivot?.web_status ?? true,
        };
    });
    isOpen.value = true;
};

// Ensure web_status is strictly false if system status (is_active) goes false
watch(
    () => form.value.level_mappings,
    (newVal) => {
        Object.keys(newVal).forEach((key) => {
            if (newVal[key as any].is_active === false) {
                newVal[key as any].web_status = false;
            }
        });
    },
    { deep: true },
);

const submit = async () => {
    try {
        await axios.put(
            `/internal-api/gratitude/program-benefits/${props.benefit.id}`,
            form.value,
        );
        isOpen.value = false;
        emit('saved');
    } catch (error) {
        console.error('Error updating program level benefit', error);
    }
};
</script>

<template>
    <div>
        <Button @click="openModal" variant="outline" size="sm"
            >Edit Levels</Button
        >

        <div
            v-if="isOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 text-left"
        >
            <div
                class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-lg border border-border bg-card p-6 shadow-lg"
            >
                <h2 class="mb-1 text-xl font-bold">
                    Assign "{{ benefit.name }}" to Levels
                </h2>
                <div class="mb-3 flex items-center gap-2">
                    <span
                        v-if="benefit.benefit_key"
                        class="inline-flex items-center rounded-md bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground"
                        >{{ benefit.benefit_key }}</span
                    >
                    <span v-else class="text-xs text-muted-foreground italic"
                        >No benefit key set — edit via Base Benefits.</span
                    >
                </div>
                <p class="mb-4 text-sm text-muted-foreground">
                    Select which tier levels receive this benefit and specify
                    their exact values.
                </p>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="mt-4">
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
                                        :id="'edit_level_' + level.id"
                                        class="h-4 w-4 rounded border-input text-primary"
                                    />
                                    <Label
                                        :for="'edit_level_' + level.id"
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
                                            :id="'is_active_' + level.id"
                                            class="h-4 w-4 rounded border-input text-primary"
                                        />
                                        <Label
                                            :for="'is_active_' + level.id"
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
                                            :id="'web_status_' + level.id"
                                            class="h-4 w-4 rounded border-input text-primary disabled:opacity-50"
                                        />
                                        <Label
                                            :for="'web_status_' + level.id"
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

                    <div class="mt-6 flex justify-end space-x-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="isOpen = false"
                            >Cancel</Button
                        >
                        <Button type="submit">Save Assignments</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
