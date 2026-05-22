<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import AddBenefit from '@/components/Gratitude/AddBenefit.vue';
import UpdateBenefit from '@/components/Gratitude/UpdateBenefit.vue';
import { Button } from '@/components/ui/button';
import { Trash2 } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Gratitude Program', href: '/gratitude' },
    { title: 'Base Benefits', href: '/gratitude/benefits' },
];

const page = usePage();
const canImportGratitude = computed(() => Boolean((page.props.auth as any)?.can_import_gratitude));

const benefits = ref<any[]>([]);

const fetchBenefits = async () => {
    try {
        const response = await axios.get('/internal-api/gratitude/benefits');
        benefits.value = response.data;
    } catch (error) {
        console.error("Failed to load gratitude benefits", error);
    }
};

const importBenefits = async () => {
    try {
        await axios.get('/internal-api/gratitude/migrate-benefits/data');
        fetchBenefits();
    } catch (error) {
        console.error("Failed to import benefits", error);
    }
}

const deleteBenefit = async (id: number) => {
    if (confirm('Are you sure you want to delete this benefit?')) {
        try {
            await axios.delete(`/internal-api/gratitude/benefits/${id}`);
            fetchBenefits();
        } catch (error) {
            console.error("Failed to delete benefit", error);
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
            <div class="sm:flex sm:items-center mb-8">
                <div class="sm:flex-auto">
                    <h1 class="text-3xl font-bold tracking-tight text-foreground">Base Benefits Pool</h1>
                    <p class="mt-2 text-sm text-muted-foreground">Manage the master list of benefits available in the program.</p>
                </div>
                <div v-if="canImportGratitude" class="mt-4 sm:ml-16 sm:mt-0 sm:flex-none">
                    <Button @click="importBenefits">Import</Button>
                </div>
                <div class="mt-4 sm:ml-16 sm:mt-0 sm:flex-none">
                    <AddBenefit @saved="fetchBenefits" />
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border border-border bg-card">
                <table class="min-w-full divide-y divide-border">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Benefit Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Benefit Key</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-muted-foreground uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border bg-card">
                        <tr v-for="benefit in benefits" :key="benefit.id">
                            <td class="px-6 py-4">
                                <div class="font-medium text-foreground">{{ benefit.name }}</div>
                                <div class="text-xs text-muted-foreground truncate max-w-xs" v-if="benefit.description">{{ benefit.description }}</div>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4">
                                <span v-if="benefit.benefit_key" class="inline-flex items-center rounded-md bg-muted px-2 py-0.5 text-xs font-mono text-muted-foreground">{{ benefit.benefit_key }}</span>
                                <span v-else class="text-muted-foreground/40 text-xs">—</span>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-muted-foreground capitalize">{{ benefit.type }}</td>
                            <td class="whitespace-nowrap px-6 py-4 text-muted-foreground">
                                <span v-if="benefit.is_active" class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">Active</span>
                                <span v-else class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">Inactive</span>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium">
                                <div class="flex items-center justify-end space-x-2">
                                    <UpdateBenefit :benefit="benefit" @saved="fetchBenefits" />
                                    <Button variant="ghost" size="icon" @click="deleteBenefit(benefit.id)" class="text-destructive h-8 w-8 hover:bg-destructive/10 hover:text-destructive">
                                        <Trash2 class="w-4 h-4" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="benefits.length === 0">
                            <td colspan="5" class="px-6 py-4 text-center text-muted-foreground">No base benefits established yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
