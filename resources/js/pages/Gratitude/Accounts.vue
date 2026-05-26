<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { computed, ref, onMounted, watch } from 'vue';
import axios from 'axios';
import {
    FileDown,
    FileText,
    Printer,
    RefreshCw,
    RotateCcw,
    Search,
} from 'lucide-vue-next';
import DataTable from '@/components/DataTable.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { route } from 'ziggy-js';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Gratitude Program', href: '/gratitude' },
    { title: 'Accounts', href: '/gratitude/accounts' },
];

const columns = [
    { key: 'id', label: 'ID', sortable: true },
    { key: 'gratitudeNumber', label: 'Gratitude Number', sortable: true },
    { key: 'level', label: 'Level', sortable: true },
    {
        key: 'totalPoints',
        label: 'Total Points',
        sortable: true,
        align: 'right' as const,
    },
    {
        key: 'total_balance',
        label: 'Total Balance',
        sortable: true,
        align: 'right' as const,
    },
    {
        key: 'useablePoints',
        label: 'Usable Points',
        sortable: true,
        align: 'right' as const,
    },
    {
        key: 'dollar_value',
        label: 'Dollar Value',
        sortable: true,
        align: 'right' as const,
    },
    {
        key: 'pending_points',
        label: 'Pending Points',
        sortable: true,
        align: 'right' as const,
    },
    {
        key: 'expiring_soon_points',
        label: 'Expiring Soon',
        sortable: true,
        align: 'right' as const,
    },
    {
        key: 'totalRedeemedPoints',
        label: 'Redeemed',
        sortable: true,
        align: 'right' as const,
    },
    {
        key: 'totalCancelledPoints',
        label: 'Cancelled',
        sortable: true,
        align: 'right' as const,
    },
    {
        key: 'totalExpiredPoints',
        label: 'Expired Points',
        sortable: true,
        align: 'right' as const,
    },
    { key: 'last_activity_at', label: 'Last Activity', sortable: true },
    {
        key: 'status',
        label: 'Status',
        sortable: true,
        align: 'center' as const,
    },
    {
        key: 'actions',
        label: 'Actions',
        align: 'center' as const,
        exportable: false,
    },
];

const gratitudePoints = ref<any[]>([]);
const loading = ref(true);
const filterOptions = ref<{ levels: any[]; about_to_expire_days?: number }>({
    levels: [],
    about_to_expire_days: 30,
});
const filters = ref({
    status: '',
    usable_points: '',
    expiry_status: '',
    expires_from: '',
    expires_to: '',
    level: '',
    search: '',
});
let filterTimer: ReturnType<typeof setTimeout> | null = null;

const cleanedFilters = computed(() =>
    Object.fromEntries(
        Object.entries(filters.value).filter(
            ([, value]) =>
                value !== null && value !== undefined && value !== '',
        ),
    ),
);

const fetchAccountsData = async () => {
    loading.value = true;
    try {
        const response = await axios.get('/internal-api/gratitude', {
            params: cleanedFilters.value,
        });
        gratitudePoints.value = response.data.points || [];
        filterOptions.value =
            response.data.filter_options || filterOptions.value;
    } catch (error) {
        console.error('Failed to load gratitude accounts', error);
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchAccountsData();
});

watch(
    filters,
    () => {
        if (filterTimer) clearTimeout(filterTimer);
        filterTimer = setTimeout(() => fetchAccountsData(), 250);
    },
    { deep: true },
);

const resetFilters = () => {
    filters.value = {
        status: '',
        usable_points: '',
        expiry_status: '',
        expires_from: '',
        expires_to: '',
        level: '',
        search: '',
    };
};

type ExportFormat = 'pdf' | 'excel' | 'print';

const accountExportUrl = (format: ExportFormat) => {
    const url = new URL(
        `/internal-api/gratitude/accounts/export/${format}`,
        window.location.origin,
    );

    Object.entries(cleanedFilters.value).forEach(([key, value]) => {
        url.searchParams.set(key, String(value));
    });

    return url.toString();
};

const exportAccounts = (format: ExportFormat) => {
    const url = accountExportUrl(format);

    if (format === 'excel') {
        window.location.href = url;
        return;
    }

    window.open(
        url,
        '_blank',
        format === 'print' ? 'width=1200,height=800' : undefined,
    );
};

const getStatusBadge = (status: string) => {
    switch (status?.toLowerCase()) {
        case 'active':
            return 'bg-green-100 text-green-800';
        case 'pending':
            return 'bg-yellow-100 text-yellow-800';
        case 'expired':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
};

const isActiveValue = (value: any) =>
    value === true ||
    value === 1 ||
    value === '1' ||
    String(value).toLowerCase() === 'true';
const isActiveStatus = (value: any) =>
    String(value || '').toLowerCase() === 'active' || isActiveValue(value);

const isInactiveAccount = (row: any) => {
    return !isActiveStatus(row.status) || !isActiveValue(row.is_active);
};

const accountRowClass = (row: Record<string, unknown>) => {
    return isInactiveAccount(row)
        ? 'bg-red-50/90 text-red-950 [&>td:first-child]:border-l-4 [&>td:first-child]:border-red-500'
        : '';
};

const getLevelBadge = (level: string) => {
    switch (level?.toLowerCase()) {
        case 'jetsetter':
            return 'bg-amber-100 text-amber-800 border border-amber-300';
        case 'globetrotter':
            return 'bg-blue-100 text-blue-800 border border-blue-300';
        case 'wanderer':
            return 'bg-gray-100 text-gray-700 border border-gray-300';
        default:
            return 'bg-gray-100 text-gray-600 border border-gray-200';
    }
};

const levelIconUrl = (row: any) => {
    return row.level_icon_url || '';
};

const formatNumber = (val: any) => {
    const n = Number(val || 0);
    return new Intl.NumberFormat('en-US').format(n);
};

const formatMoney = (val: any) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 2,
    }).format(Number(val || 0));
};

const formatDate = (val: any) => {
    if (!val) return '—';
    return new Date(val).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const getAccountRoute = (gratitudeNumber: any): string => {
    return route('gratitude.account.show', gratitudeNumber) as any as string;
};

const syncingRows = ref<Set<string>>(new Set());
const recalculatingRows = ref<Set<string>>(new Set());
const syncBalance = async (gratitudeNumber: string) => {
    syncingRows.value = new Set([...syncingRows.value, gratitudeNumber]);
    try {
        await axios.post(
            `/internal-api/gratitude/${gratitudeNumber}/sync-balance`,
        );
        await fetchAccountsData();
    } catch (error) {
        console.error('Failed to sync balance', error);
    } finally {
        const next = new Set(syncingRows.value);
        next.delete(gratitudeNumber);
        syncingRows.value = next;
    }
};

const recalculateLevel = async (gratitudeNumber: string) => {
    recalculatingRows.value = new Set([
        ...recalculatingRows.value,
        gratitudeNumber,
    ]);
    try {
        await axios.post(
            `/internal-api/gratitude/${gratitudeNumber}/recalculate-level`,
        );
        await fetchAccountsData();
    } catch (error) {
        console.error('Failed to recalculate level', error);
    } finally {
        const next = new Set(recalculatingRows.value);
        next.delete(gratitudeNumber);
        recalculatingRows.value = next;
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Gratitude Accounts" />

        <div class="px-4 py-6 sm:px-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        Gratitude Accounts
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Manage user gratitudes and point balances.
                    </p>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button variant="outline" @click="exportAccounts('pdf')">
                        <FileText class="mr-2 h-4 w-4" />
                        PDF
                    </Button>
                    <Button variant="outline" @click="exportAccounts('excel')">
                        <FileDown class="mr-2 h-4 w-4" />
                        Excel
                    </Button>
                    <Button variant="outline" @click="exportAccounts('print')">
                        <Printer class="mr-2 h-4 w-4" />
                        Print
                    </Button>
                </div>
            </div>

            <div
                class="mt-6 grid gap-3 border-y border-border/70 py-4 md:grid-cols-2 xl:grid-cols-[1.5fr_1fr_1fr_1fr_1fr_1fr_1fr_auto]"
            >
                <div>
                    <Label
                        for="account-search"
                        class="text-xs font-semibold text-muted-foreground uppercase"
                        >Gratitude Number</Label
                    >
                    <div class="relative mt-1">
                        <Input
                            id="account-search"
                            v-model="filters.search"
                            type="search"
                            placeholder="Search Gratitude number"
                            class="pl-9"
                        />
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                        />
                    </div>
                </div>
                <div>
                    <Label
                        for="status-filter"
                        class="text-xs font-semibold text-muted-foreground uppercase"
                        >Status</Label
                    >
                    <select
                        id="status-filter"
                        v-model="filters.status"
                        class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div>
                    <Label
                        for="usable-filter"
                        class="text-xs font-semibold text-muted-foreground uppercase"
                        >Usable Points</Label
                    >
                    <select
                        id="usable-filter"
                        v-model="filters.usable_points"
                        class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Any balance</option>
                        <option value="with">With usable points</option>
                        <option value="without">No usable points</option>
                    </select>
                </div>
                <div>
                    <Label
                        for="expiry-filter"
                        class="text-xs font-semibold text-muted-foreground uppercase"
                        >Expiration</Label
                    >
                    <select
                        id="expiry-filter"
                        v-model="filters.expiry_status"
                        class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Any expiration</option>
                        <option value="about_to_expire">
                            About to expire ({{
                                filterOptions.about_to_expire_days || 30
                            }}
                            days)
                        </option>
                    </select>
                </div>
                <div>
                    <Label
                        for="expires-from-filter"
                        class="text-xs font-semibold text-muted-foreground uppercase"
                        >Expires From</Label
                    >
                    <Input
                        id="expires-from-filter"
                        v-model="filters.expires_from"
                        type="date"
                        class="mt-1"
                    />
                </div>
                <div>
                    <Label
                        for="expires-to-filter"
                        class="text-xs font-semibold text-muted-foreground uppercase"
                        >Expires To</Label
                    >
                    <Input
                        id="expires-to-filter"
                        v-model="filters.expires_to"
                        type="date"
                        class="mt-1"
                    />
                </div>
                <div>
                    <Label
                        for="level-filter"
                        class="text-xs font-semibold text-muted-foreground uppercase"
                        >Level</Label
                    >
                    <select
                        id="level-filter"
                        v-model="filters.level"
                        class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">All levels</option>
                        <option
                            v-for="level in filterOptions.levels"
                            :key="level.id || level.name"
                            :value="level.name"
                        >
                            {{ level.name }}
                        </option>
                    </select>
                </div>
                <div class="flex items-end">
                    <Button
                        variant="outline"
                        class="w-full"
                        @click="resetFilters"
                    >
                        <RotateCcw class="mr-2 h-4 w-4" />
                        Reset
                    </Button>
                </div>
            </div>

            <!-- Gratitudes Table -->
            <div
                class="mt-6 rounded-xl border border-border bg-card p-3 shadow-sm"
            >
                <DataTable
                    title="Gratitude Accounts"
                    :columns="columns"
                    :rows="gratitudePoints"
                    :busy="loading"
                    :row-class="accountRowClass"
                    :show-search="false"
                    :show-exports="false"
                >
                    <template #cell-level="{ row }">
                        <div class="flex items-center gap-2">
                            <img
                                v-if="levelIconUrl(row)"
                                :src="levelIconUrl(row)"
                                :alt="String(row.level || 'Level')"
                                class="h-7 w-7 object-contain"
                            />
                            <span
                                v-else
                                :class="[
                                    'rounded-full px-2.5 py-1 text-xs font-semibold',
                                    getLevelBadge(String(row.level || '')),
                                ]"
                            >
                                {{ row.level || '—' }}
                            </span>
                        </div>
                    </template>
                    <template #cell-totalPoints="{ row }">
                        {{ formatNumber(row.totalPoints) }}
                    </template>
                    <template #cell-useablePoints="{ row }">
                        {{ formatNumber(row.useablePoints) }}
                    </template>
                    <template #cell-dollar_value="{ row }">
                        {{ formatMoney(row.dollar_value) }}
                    </template>
                    <template #cell-total_balance="{ row }">
                        {{ formatNumber(row.total_balance) }}
                    </template>
                    <template #cell-pending_points="{ row }">
                        {{ formatNumber(row.pending_points) }}
                    </template>
                    <template #cell-expiring_soon_points="{ row }">
                        {{ formatNumber(row.expiring_soon_points) }}
                    </template>
                    <template #cell-totalRedeemedPoints="{ row }">
                        {{ formatNumber(row.totalRedeemedPoints) }}
                    </template>
                    <template #cell-totalCancelledPoints="{ row }">
                        {{ formatNumber(row.totalCancelledPoints) }}
                    </template>
                    <template #cell-totalExpiredPoints="{ row }">
                        {{ formatNumber(row.totalExpiredPoints) }}
                    </template>
                    <template #cell-last_activity_at="{ row }">
                        {{ formatDate(row.last_activity_at) }}
                    </template>
                    <template #cell-status="{ row }">
                        <span
                            :class="[
                                'rounded-full px-2.5 py-1 text-xs font-semibold',
                                getStatusBadge(String(row.status || '')),
                            ]"
                        >
                            {{ row.status || 'Unknown' }}
                        </span>
                    </template>
                    <template #cell-actions="{ row }">
                        <div class="flex items-center justify-center gap-1">
                            <Button
                                variant="ghost"
                                size="sm"
                                :disabled="
                                    syncingRows.has(
                                        (row as any).gratitudeNumber,
                                    )
                                "
                                @click="
                                    syncBalance((row as any).gratitudeNumber)
                                "
                                title="Sync balance"
                            >
                                <RefreshCw
                                    class="h-3.5 w-3.5"
                                    :class="{
                                        'animate-spin': syncingRows.has(
                                            (row as any).gratitudeNumber,
                                        ),
                                    }"
                                />
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                :disabled="
                                    recalculatingRows.has(
                                        (row as any).gratitudeNumber,
                                    )
                                "
                                @click="
                                    recalculateLevel(
                                        (row as any).gratitudeNumber,
                                    )
                                "
                                title="Recalculate level"
                            >
                                <RotateCcw
                                    class="h-3.5 w-3.5"
                                    :class="{
                                        'animate-spin': recalculatingRows.has(
                                            (row as any).gratitudeNumber,
                                        ),
                                    }"
                                />
                            </Button>
                            <Link
                                :href="
                                    getAccountRoute(
                                        (row as any).gratitudeNumber,
                                    )
                                "
                            >
                                <Button variant="ghost" size="sm">View</Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
