<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { 
    Award, 
    Clock, 
    Gift, 
    Activity, 
    BookOpen,
    CreditCard
} from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Gratitude Program', href: '/gratitude' },
    { title: 'Overview', href: '/gratitude' },
];

interface GratitudeOverviewSummary {
    total_accounts: number;
    total_point_balance: number;
    total_usable_points: number;
    total_usable_amount: number;
    total_pending_points: number;
    total_reserved: number;
    total_used_money: number;
}

const summary = ref<GratitudeOverviewSummary | null>(null);
const loading = ref(true);

const fetchGratitudeData = async () => {
    try {
        const response = await axios.get('/internal-api/gratitude/overview');
        summary.value = response.data;
    } catch (error) {
        console.error("Failed to load gratitude overview data", error);
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchGratitudeData();
});

const formatMoney = (value: number | string | null | undefined) =>
    new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 2,
    }).format(Number(value || 0));

</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Gratitude Overview" />

        <div class="px-4 py-6 max-w-7xl mx-auto space-y-8 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight text-foreground">Gratitude Overview</h1>
                    <p class="mt-2 text-sm text-muted-foreground">High-level statistics and summaries of the Gratitude Program.</p>
                </div>
            </div>

            <!-- Loading Skeleton -->
            <div v-if="loading" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <div v-for="i in 4" :key="i" class="h-32 bg-muted animate-pulse rounded-xl border border-border"></div>
            </div>

            <!-- Metric Cards -->
            <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                
                <!-- Accounts -->
                <div class="bg-card text-card-foreground rounded-xl border border-border shadow-sm p-6 flex flex-col justify-between">
                    <div class="flex items-center justify-between pb-2">
                        <h3 class="font-medium text-muted-foreground">Accounts</h3>
                        <div class="p-2 bg-blue-500/10 rounded-full text-blue-500">
                            <BookOpen class="w-5 h-5" />
                        </div>
                    </div>
                    <div>
                        <div class="text-3xl font-bold">{{ summary?.total_accounts.toLocaleString() }}</div>
                        <p class="text-xs text-muted-foreground mt-1">Total active program members</p>
                    </div>
                </div>

                <!-- Usable Points -->
                <div class="bg-card text-card-foreground rounded-xl border border-border shadow-sm p-6 flex flex-col justify-between">
                    <div class="flex items-center justify-between pb-2">
                        <h3 class="font-medium text-muted-foreground">Usable Balance</h3>
                        <div class="p-2 bg-primary/10 rounded-full text-primary">
                            <Activity class="w-5 h-5" />
                        </div>
                    </div>
                    <div>
                        <div class="text-3xl font-bold">{{ summary?.total_usable_points.toLocaleString() }}</div>
                        <p class="text-xs text-muted-foreground mt-1">Total amount: {{ formatMoney(summary?.total_usable_amount) }}</p>
                    </div>
                </div>

                <!-- Pending Points -->
                <div class="bg-card text-card-foreground rounded-xl border border-border shadow-sm p-6 flex flex-col justify-between">
                    <div class="flex items-center justify-between pb-2">
                        <h3 class="font-medium text-muted-foreground">Pending Points</h3>
                        <div class="p-2 bg-yellow-500/10 rounded-full text-yellow-500">
                            <Clock class="w-5 h-5" />
                        </div>
                    </div>
                    <div>
                        <div class="text-3xl font-bold">{{ summary?.total_pending_points.toLocaleString() }}</div>
                        <p class="text-xs text-muted-foreground mt-1">Unlocking upon journey return</p>
                    </div>
                </div>

                <!-- Total Reserved -->
                <div class="bg-card text-card-foreground rounded-xl border border-border shadow-sm p-6 flex flex-col justify-between">
                    <div class="flex items-center justify-between pb-2">
                        <h3 class="font-medium text-muted-foreground">Total Reserved</h3>
                        <div class="p-2 bg-indigo-500/10 rounded-full text-indigo-500">
                            <Award class="w-5 h-5" />
                        </div>
                    </div>
                    <div>
                        <div class="text-3xl font-bold">{{ summary?.total_reserved.toLocaleString() }}</div>
                        <p class="text-xs text-muted-foreground mt-1">Total reserved journey benefits</p>
                    </div>
                </div>
                
                <!-- Total Used Money -->
                <div class="bg-card text-card-foreground rounded-xl border border-border shadow-sm p-6 flex flex-col justify-between">
                    <div class="flex items-center justify-between pb-2">
                        <h3 class="font-medium text-muted-foreground">Total Used Value</h3>
                        <div class="p-2 bg-green-500/10 rounded-full text-green-500">
                            <CreditCard class="w-5 h-5" />
                        </div>
                    </div>
                    <div>
                        <div class="text-3xl font-bold">${{ summary?.total_used_money.toLocaleString() }}</div>
                        <p class="text-xs text-muted-foreground mt-1">Value used on benefits</p>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
