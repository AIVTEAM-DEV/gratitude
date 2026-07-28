<script setup lang="ts">
import { computed, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const model = defineModel<any>({ required: true });

type RuleKey =
    | 'text'
    | 'per_person'
    | 'per_room'
    | 'miles'
    | 'points_per_dollar'
    | 'referral_points';

const ruleOptions = [
    { key: 'text', label: 'Text / Included' },
    { key: 'per_person', label: 'Amount per person' },
    { key: 'per_room', label: 'Amount per room' },
    { key: 'miles', label: 'Miles' },
    { key: 'points_per_dollar', label: 'Points per dollar' },
    { key: 'referral_points', label: 'Referral points' },
];

const currencies = [
    'USD',
    'EUR',
    'GBP',
    'ZAR',
    'AUD',
    'CAD',
    'CHF',
    'JPY',
    'AED',
];

const ruleKey = computed<RuleKey>(() => {
    const key = model.value.calculation?.key;

    return ruleOptions.some((option) => option.key === key)
        ? (key as RuleKey)
        : 'text';
});
const isMonetary = computed(() =>
    ['per_person', 'per_room'].includes(ruleKey.value),
);
const isNumeric = computed(() => ruleKey.value !== 'text');

const valueLabel = computed(
    () =>
        (
            ({
                text: 'Value',
                per_person: 'Amount per person',
                per_room: 'Amount per room',
                miles: 'Number of miles',
                points_per_dollar: 'Points per dollar',
                referral_points: 'Referral points',
            }) satisfies Record<RuleKey, string>
        )[ruleKey.value],
);

const valuePlaceholder = computed(
    () =>
        (
            ({
                text: 'e.g. Included',
                per_person: '250',
                per_room: '500',
                miles: '50',
                points_per_dollar: '35',
                referral_points: '5000',
            }) satisfies Record<RuleKey, string>
        )[ruleKey.value],
);

watch(
    ruleKey,
    (key) => {
        model.value.value_type = key === 'text' ? 'text' : 'number';
        if (['per_person', 'per_room'].includes(key)) {
            model.value.calculation.currency ||= 'USD';
        } else if (model.value.calculation) {
            delete model.value.calculation.currency;
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="grid grid-cols-12 gap-3">
        <div class="col-span-12 sm:col-span-4">
            <Label class="text-xs text-muted-foreground">Rule</Label>
            <select
                v-model="model.calculation.key"
                class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
            >
                <option
                    v-for="option in ruleOptions"
                    :key="option.key"
                    :value="option.key"
                >
                    {{ option.label }}
                </option>
            </select>
        </div>

        <div v-if="isMonetary" class="col-span-12 sm:col-span-8">
            <Label class="text-xs text-muted-foreground">{{
                valueLabel
            }}</Label>
            <div class="flex">
                <select
                    v-model="model.calculation.currency"
                    aria-label="Currency"
                    class="h-9 rounded-l-md border border-r-0 border-input bg-muted/30 px-2 text-sm focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <option
                        v-for="currency in currencies"
                        :key="currency"
                        :value="currency"
                    >
                        {{ currency }}
                    </option>
                </select>
                <Input
                    v-model="model.value"
                    type="number"
                    min="0"
                    step="0.01"
                    required
                    :placeholder="valuePlaceholder"
                    class="rounded-l-none"
                />
            </div>
        </div>

        <div v-else class="col-span-12 sm:col-span-8">
            <Label class="text-xs text-muted-foreground">{{
                valueLabel
            }}</Label>
            <Input
                v-model="model.value"
                :type="isNumeric ? 'number' : 'text'"
                :min="isNumeric ? 0 : undefined"
                :step="ruleKey === 'points_per_dollar' ? '0.01' : '1'"
                :required="isNumeric"
                :placeholder="valuePlaceholder"
            />
        </div>
    </div>
</template>
