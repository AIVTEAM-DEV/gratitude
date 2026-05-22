<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { onMounted, ref } from 'vue';
import axios from 'axios';
import AddBenefit from '@/components/Gratitude/AddBenefit.vue';
import UpdateBenefit from '@/components/Gratitude/UpdateBenefit.vue';
import { Button } from '@/components/ui/button';
import { Trash2 } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Gratitude Program', href: '/gratitude' },
    { title: 'Base Benefits', href: '/gratitude/benefits' },
];

const benefits = ref<any[]>([]);

const fetchBenefits = async () => {
    try {
        const response = await axios.get('/internal-api/gratitude/benefits');
        benefits.value = response.data;
    } catch (error) {
        console.error('Failed to load gratitude benefits', error);
    }
};

const deleteBenefit = async (id: number) => {
    if (confirm('Are you sure you want to delete this benefit?')) {
        try {
            await axios.delete(`/internal-api/gratitude/benefits/${id}`);
            fetchBenefits();
        } catch (error) {
            console.error('Failed to delete benefit', error);
        }
    }
};

onMounted(() => {
    fetchBenefits();
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Base Benefits" />

        <div class="px-4 py-6 sm:px-6 lg:px-8">
            <div class="mb-8 sm:flex sm:items-center">
                <div class="sm:flex-auto">
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        Base Benefits Pool
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Manage the master list of benefits available in the
                        program.
                    </p>
                </div>
                <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
                    <AddBenefit @saved="fetchBenefits" />
                </div>
            </div>

            <div
                class="overflow-hidden rounded-lg border border-border bg-card"
            >
                <table class="min-w-full divide-y divide-border">
                    <thead class="bg-muted/50">
                        <tr>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Benefit Name
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Benefit Key
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Type
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Status
                            </th>
                            <th
                                class="px-6 py-3 text-right text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border bg-card">
                        <tr v-for="benefit in benefits" :key="benefit.id">
                            <td class="px-6 py-4">
                                <div class="font-medium text-foreground">
                                    {{ benefit.name }}
                                </div>
                                <div
                                    class="max-w-xs truncate text-xs text-muted-foreground"
                                    v-if="benefit.description"
                                >
                                    {{ benefit.description }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span
                                    v-if="benefit.benefit_key"
                                    class="inline-flex items-center rounded-md bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground"
                                    >{{ benefit.benefit_key }}</span
                                >
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground/40"
                                    >—</span
                                >
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-muted-foreground capitalize"
                            >
                                {{ benefit.type }}
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-muted-foreground"
                            >
                                <span
                                    v-if="benefit.is_active"
                                    class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800"
                                    >Active</span
                                >
                                <span
                                    v-else
                                    class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800"
                                    >Inactive</span
                                >
                            </td>
                            <td
                                class="px-6 py-4 text-right text-sm font-medium whitespace-nowrap"
                            >
                                <div
                                    class="flex items-center justify-end space-x-2"
                                >
                                    <UpdateBenefit
                                        :benefit="benefit"
                                        @saved="fetchBenefits"
                                    />
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        @click="deleteBenefit(benefit.id)"
                                        class="h-8 w-8 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="benefits.length === 0">
                            <td
                                colspan="5"
                                class="px-6 py-4 text-center text-muted-foreground"
                            >
                                No base benefits established yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
