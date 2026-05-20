<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import {
    Activity,
    Award,
    BadgeCheck,
    CircleDollarSign,
    Gift,
    History,
    KeyRound,
    Landmark,
    Layers,
    MinusCircle,
    Plug,
    RefreshCw,
    ShieldCheck,
    Sparkles,
    Trophy,
    UserPlus,
} from 'lucide-vue-next';

type GratitudeOverviewSummary = {
    total_accounts: number;
    total_point_balance: number;
    total_usable_points: number;
    total_usable_amount: number;
    total_pending_points: number;
    total_reserved: number;
    total_used_money: number;
};

type ApplicationKey = {
    id: number;
    name: string;
    url?: string | null;
    status: string;
    roles?: Array<{ name: string }>;
};

type Operation = {
    name: string;
    method: 'GET' | 'POST' | 'PUT' | 'DELETE';
    path: string;
    icon: typeof UserPlus;
    payload: string;
    returns: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
    },
];

const loading = ref(true);
const summary = ref<GratitudeOverviewSummary>({
    total_accounts: 0,
    total_point_balance: 0,
    total_usable_points: 0,
    total_usable_amount: 0,
    total_pending_points: 0,
    total_reserved: 0,
    total_used_money: 0,
});
const applicationKeys = ref<ApplicationKey[]>([]);

const activeApplications = computed(() =>
    applicationKeys.value.filter((app) => app.status === 'active'),
);

const inactiveApplications = computed(() =>
    applicationKeys.value.filter((app) => app.status !== 'active'),
);

const metrics = computed(() => [
    {
        label: 'Gratitude Accounts',
        value: formatNumber(summary.value.total_accounts),
        detail: 'Standalone member records',
        icon: UserPlus,
        color: 'text-sky-600 bg-sky-500/10',
    },
    {
        label: 'Usable Points',
        value: formatNumber(summary.value.total_usable_points),
        detail: `Total amount: ${formatMoney(summary.value.total_usable_amount)}`,
        icon: CircleDollarSign,
        color: 'text-emerald-600 bg-emerald-500/10',
    },
    {
        label: 'Pending Points',
        value: formatNumber(summary.value.total_pending_points),
        detail: 'Waiting for activation',
        icon: RefreshCw,
        color: 'text-amber-600 bg-amber-500/10',
    },
    {
        label: 'Active Applications',
        value: formatNumber(activeApplications.value.length),
        detail: `${inactiveApplications.value.length} blocked or inactive`,
        icon: KeyRound,
        color: 'text-violet-600 bg-violet-500/10',
    },
]);

const json = (value: unknown) => JSON.stringify(value, null, 2);

const operations: Operation[] = [
    {
        name: 'Create account',
        method: 'POST',
        path: '/api/v1/gratitude',
        icon: UserPlus,
        payload: json({
            old_id: 4821,
            category: [1],
            level: 'Explorer',
            status: 'active',
        }),
        returns: json({
            message: 'Gratitude account created',
            gratitude: {
                gratitudeNumber: 'G0042',
                level: 'Explorer',
                useablePoints: 0,
            },
            prefix_used: 'G',
            already_exists: false,
        }),
    },
    {
        name: 'Get single account',
        method: 'GET',
        path: '/api/v1/gratitude/{number}',
        icon: BadgeCheck,
        payload: 'No request body',
        returns: json({
            gratitude: {
                gratitudeNumber: 'G0880',
                level: 'Explorer',
                useablePoints: 12500,
            },
            earned_points: [],
            bonus_points: [],
            redemptions: [],
        }),
    },
    {
        name: 'Record earned points',
        method: 'POST',
        path: '/api/v1/gratitude/{number}/earned',
        icon: Sparkles,
        payload: json({
            date: '2026-05-05',
            category: 'journey',
            points: 1200,
            amount: 350,
            description: 'Journey completed',
            journey_id: 12345,
        }),
        returns: json({
            message: 'Points added',
            point: {
                id: 101,
                points: 1200,
                status: 'pending',
            },
        }),
    },
    {
        name: 'Record bonus points',
        method: 'POST',
        path: '/api/v1/gratitude/{number}/bonus',
        icon: Gift,
        payload: json({
            date: '2026-05-05',
            description: 'Service recovery bonus',
            points: 500,
        }),
        returns: json({
            message: 'Bonus points added',
            point: {
                id: 44,
                points: 500,
            },
        }),
    },
    {
        name: 'Redeem points',
        method: 'POST',
        path: '/api/v1/gratitude/{number}/redeem',
        icon: Landmark,
        payload: json({
            points: 3500,
            reason: 'Partner purchase',
            redemption_type: 'partner',
        }),
        returns: json({
            message: 'Points redeemed successfully',
            redemption: {
                points: 3500,
                amount: '100.00',
                category: 'partner',
            },
        }),
    },
    {
        name: 'Cancel points',
        method: 'POST',
        path: '/api/v1/gratitude/{number}/cancel',
        icon: MinusCircle,
        payload: json({
            date: '2026-05-05',
            cancellation_reason: 'Journey adjustment',
            cancellation_points: 250,
            earned_point_id: 101,
        }),
        returns: json({
            message: 'Points cancelled',
            cancellation: {
                points: 250,
                description: 'Journey adjustment',
            },
        }),
    },
    {
        name: 'Check balance',
        method: 'GET',
        path: '/api/v1/gratitude/{number}/balance',
        icon: Activity,
        payload: 'No request body',
        returns: json({
            gratitudeNumber: 'G0880',
            balance: {
                usable_points: 12500,
                pending_points: 1200,
                redeemed_points: 3500,
            },
        }),
    },
    {
        name: 'Check level',
        method: 'GET',
        path: '/api/v1/gratitude/{number}/level',
        icon: Trophy,
        payload: 'No request body',
        returns: json({
            gratitudeNumber: 'G0880',
            level: {
                name: 'Globetrotter',
                obtained_at: '2026-05-05',
            },
            level_rules: {
                min_points: 15000,
                earned_expire_days: 730,
            },
        }),
    },
    {
        name: 'Get level benefits',
        method: 'GET',
        path: '/api/v1/gratitude/levels/{level}/benefits',
        icon: Layers,
        payload: 'No request body',
        returns: json({
            level: {
                name: 'Globetrotter',
                min_points: 15000,
            },
            benefits: [
                {
                    benefit_key: 'late_checkout',
                    value: 'Included',
                },
            ],
        }),
    },
    {
        name: 'Get points history',
        method: 'GET',
        path: '/api/v1/gratitude/{number}/points-history',
        icon: History,
        payload: 'No request body',
        returns: json({
            gratitudeNumber: 'G0880',
            history: [
                {
                    type: 'earned',
                    date: '2026-05-05',
                    points: 1200,
                    description: 'Journey completed',
                },
            ],
        }),
    },
    {
        name: 'Record earned benefit',
        method: 'POST',
        path: '/api/v1/gratitude/{number}/earned-benefits',
        icon: Award,
        payload: json({
            date: '2026-05-05',
            description: 'Late checkout granted on departure',
            benefit_name: 'Late Checkout',
            benefit_key: 'late_checkout',
            benefit_value: '2 Hours',
            value_type: 'item',
            journey_id: 12345,
            project_data: {
                projectNumber: 'P-001',
                name: 'The Lodge',
            },
            status: 'used',
            notes: 'Guest requested at front desk',
        }),
        returns: json({
            message: 'Earned benefit recorded',
            earned_benefit: {
                id: 7,
                gratitudeNumber: 'G0880',
                benefit_name: 'Late Checkout',
                benefit_key: 'late_checkout',
                benefit_value: '2 Hours',
                value_type: 'item',
                description: 'Late checkout granted on departure',
                journey_id: 12345,
                date: '2026-05-05',
                status: 'used',
            },
        }),
    },
];

const loadDashboard = async () => {
    loading.value = true;

    try {
        const [overviewResponse, keysResponse] = await Promise.all([
            axios.get<GratitudeOverviewSummary>('/internal-api/gratitude/overview'),
            axios.get<ApplicationKey[]>('/internal-api/application-keys'),
        ]);

        summary.value = overviewResponse.data;
        applicationKeys.value = Array.isArray(keysResponse.data)
            ? keysResponse.data
            : [];
    } finally {
        loading.value = false;
    }
};

const formatNumber = (value: number | string | null | undefined) =>
    new Intl.NumberFormat('en-US').format(Number(value || 0));

const formatMoney = (value: number | string | null | undefined) =>
    new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 2,
    }).format(Number(value || 0));

onMounted(loadDashboard);
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="flex items-center gap-2 text-sm font-medium text-primary">
                        <Plug class="size-4" />
                        Standalone gratitude service
                    </div>
                    <h1 class="mt-2 text-3xl font-bold tracking-tight text-foreground">
                        Gratitude Operations
                    </h1>
                    <p class="mt-2 max-w-3xl text-sm text-muted-foreground">
                        Central account, point, redemption, cancellation, balance, and level service for connected applications.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <Link href="/application-keys">
                            <KeyRound class="size-4" />
                            Application Keys
                        </Link>
                    </Button>
                    <Button as-child>
                        <Link href="/gratitude/accounts">
                            <BadgeCheck class="size-4" />
                            Accounts
                        </Link>
                    </Button>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="metric in metrics"
                    :key="metric.label"
                    class="rounded-lg border border-border bg-card p-5 shadow-sm"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">
                                {{ metric.label }}
                            </p>
                            <p class="mt-2 text-3xl font-bold tracking-tight text-foreground">
                                {{ loading ? '...' : metric.value }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ metric.detail }}
                            </p>
                        </div>
                        <div :class="['rounded-md p-2', metric.color]">
                            <component :is="metric.icon" class="size-5" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(360px,0.8fr)]">
                <div class="rounded-lg border border-border bg-card shadow-sm">
                    <div class="border-b border-border px-5 py-4">
                        <div class="flex items-center gap-2">
                            <ShieldCheck class="size-5 text-primary" />
                            <h2 class="text-base font-semibold text-foreground">
                                Core API Operations
                            </h2>
                        </div>
                    </div>

                    <div class="grid gap-4 p-4 lg:grid-cols-2">
                        <div
                            v-for="operation in operations"
                            :key="operation.path"
                            class="min-w-0 rounded-md border border-border bg-background p-4"
                        >
                            <div class="flex items-start gap-3">
                                <div class="rounded-md bg-muted p-2 text-muted-foreground">
                                    <component :is="operation.icon" class="size-4" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-foreground">
                                        {{ operation.name }}
                                    </p>
                                    <div class="mt-1 flex min-w-0 items-center gap-2 text-xs">
                                        <span class="rounded border border-border px-1.5 py-0.5 font-mono font-semibold text-primary">
                                            {{ operation.method }}
                                        </span>
                                        <span class="truncate font-mono text-muted-foreground">
                                            {{ operation.path }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 grid gap-3">
                                <div>
                                    <p class="mb-1 text-xs font-semibold uppercase text-muted-foreground">
                                        Payload
                                    </p>
                                    <pre class="max-h-44 overflow-auto rounded-md bg-muted/70 p-3 text-xs leading-relaxed text-foreground">{{ operation.payload }}</pre>
                                </div>
                                <div>
                                    <p class="mb-1 text-xs font-semibold uppercase text-muted-foreground">
                                        Returns
                                    </p>
                                    <pre class="max-h-44 overflow-auto rounded-md bg-muted/70 p-3 text-xs leading-relaxed text-foreground">{{ operation.returns }}</pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-card shadow-sm">
                    <div class="border-b border-border px-5 py-4">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <KeyRound class="size-5 text-primary" />
                                <h2 class="text-base font-semibold text-foreground">
                                    Connected Applications
                                </h2>
                            </div>
                            <Button
                                variant="ghost"
                                size="sm"
                                title="Refresh dashboard"
                                aria-label="Refresh dashboard"
                                @click="loadDashboard"
                            >
                                <RefreshCw class="size-4" :class="{ 'animate-spin': loading }" />
                            </Button>
                        </div>
                    </div>

                    <div class="divide-y divide-border">
                        <div
                            v-for="application in applicationKeys.slice(0, 5)"
                            :key="application.id"
                            class="flex items-center justify-between gap-4 px-5 py-4"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-foreground">
                                    {{ application.name }}
                                </p>
                                <p class="truncate text-xs text-muted-foreground">
                                    {{ application.url || 'No URL recorded' }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 rounded-full border px-2.5 py-0.5 text-xs font-semibold"
                                :class="
                                    application.status === 'active'
                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                        : 'border-red-200 bg-red-50 text-red-700'
                                "
                            >
                                {{ application.status }}
                            </span>
                        </div>

                        <div
                            v-if="!loading && applicationKeys.length === 0"
                            class="px-5 py-8 text-center text-sm text-muted-foreground"
                        >
                            No application keys have been created yet.
                        </div>
                    </div>

                    <div class="border-t border-border px-5 py-4">
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-md bg-muted/50 p-3">
                                <p class="text-xs font-medium text-muted-foreground">
                                    Total points
                                </p>
                                <p class="mt-1 font-semibold text-foreground">
                                    {{ formatNumber(summary.total_point_balance) }}
                                </p>
                            </div>
                            <div class="rounded-md bg-muted/50 p-3">
                                <p class="text-xs font-medium text-muted-foreground">
                                    Redeemed value
                                </p>
                                <p class="mt-1 font-semibold text-foreground">
                                    {{ formatMoney(summary.total_used_money) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
