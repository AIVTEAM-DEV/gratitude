<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { Eye, RefreshCw, RotateCcw, Search, ShieldAlert, Trash2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type ActivityLog = {
    id: number;
    log_name: string | null;
    event: string | null;
    description: string;
    subject_type: string | null;
    subject_type_label: string;
    subject_id: number | null;
    causer_type: string | null;
    causer_type_label: string;
    causer_id: number | null;
    properties: Record<string, unknown>;
    created_at: string | null;
    updated_at: string | null;
};

type Meta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Logs', href: '/logs' },
];

const logs = ref<ActivityLog[]>([]);
const loading = ref(false);
const deleting = ref(false);
const pruning = ref(false);
const perPage = ref(25);
const selectedIds = ref<Set<number>>(new Set());
const filterTimer = ref<ReturnType<typeof setTimeout> | null>(null);
const viewingLog = ref<ActivityLog | null>(null);
const isViewOpen = ref(false);

const filters = ref({
    search: '',
    log_name: '',
    event: '',
    subject_type: '',
    causer_type: '',
    date_from: '',
    date_to: '',
});

const filterOptions = ref({
    log_names: [] as string[],
    events: [] as string[],
    subject_types: [] as string[],
    causer_types: [] as string[],
});

const meta = ref<Meta>({
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 0,
    from: null,
    to: null,
});

const cleanedFilters = computed(() => Object.fromEntries(
    Object.entries(filters.value).filter(([, value]) => value !== null && value !== undefined && value !== ''),
));

const selectedCount = computed(() => selectedIds.value.size);
const allVisibleSelected = computed(() => logs.value.length > 0 && logs.value.every((log) => selectedIds.value.has(log.id)));

const fetchLogs = async (page = 1) => {
    loading.value = true;
    try {
        const response = await axios.get('/internal-api/logs', {
            params: {
                ...cleanedFilters.value,
                page,
                per_page: perPage.value,
            },
        });

        logs.value = response.data.logs || [];
        meta.value = response.data.meta || meta.value;
        filterOptions.value = response.data.filter_options || filterOptions.value;
        selectedIds.value = new Set([...selectedIds.value].filter((id) => logs.value.some((log) => log.id === id)));
    } finally {
        loading.value = false;
    }
};

onMounted(() => fetchLogs());

watch([filters, perPage], () => {
    if (filterTimer.value) clearTimeout(filterTimer.value);
    filterTimer.value = setTimeout(() => fetchLogs(1), 250);
}, { deep: true });

const resetFilters = () => {
    filters.value = {
        search: '',
        log_name: '',
        event: '',
        subject_type: '',
        causer_type: '',
        date_from: '',
        date_to: '',
    };
};

const checkboxChecked = (event: Event) => (event.target as HTMLInputElement).checked;

const toggleLog = (id: number, checked: boolean) => {
    const next = new Set(selectedIds.value);
    checked ? next.add(id) : next.delete(id);
    selectedIds.value = next;
};

const toggleVisibleSelection = (checked: boolean) => {
    const next = new Set(selectedIds.value);

    logs.value.forEach((log) => {
        checked ? next.add(log.id) : next.delete(log.id);
    });

    selectedIds.value = next;
};

const deleteLog = async (log: ActivityLog) => {
    if (!window.confirm(`Delete log #${log.id}?`)) return;

    deleting.value = true;
    try {
        await axios.delete(`/internal-api/logs/${log.id}`);
        await fetchLogs(meta.value.current_page);
    } finally {
        deleting.value = false;
    }
};

const viewLog = (log: ActivityLog) => {
    viewingLog.value = log;
    isViewOpen.value = true;
};

const deleteSelectedLogs = async () => {
    const ids = [...selectedIds.value];
    if (!ids.length || !window.confirm(`Delete ${ids.length} selected log entries?`)) return;

    deleting.value = true;
    try {
        await axios.delete('/internal-api/logs', { data: { ids } });
        selectedIds.value = new Set();
        await fetchLogs(meta.value.current_page);
    } finally {
        deleting.value = false;
    }
};

const pruneOldLogs = async () => {
    if (!window.confirm('Delete all log entries older than 60 days?')) return;

    pruning.value = true;
    try {
        await axios.delete('/internal-api/logs/prune/old', { data: { days: 60 } });
        selectedIds.value = new Set();
        await fetchLogs(1);
    } finally {
        pruning.value = false;
    }
};

const goToPage = (page: number) => {
    const nextPage = Math.min(Math.max(1, page), meta.value.last_page || 1);
    fetchLogs(nextPage);
};

const formatDate = (value: string | null) => {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '-';

    return date.toLocaleString();
};

const entityLabel = (label: string, id: number | null) => {
    if (!label && !id) return '-';
    return `${label || 'Record'}${id ? ` #${id}` : ''}`;
};

const eventBadgeClass = (event: string | null) => {
    switch ((event || '').toLowerCase()) {
        case 'created': return 'bg-green-100 text-green-800 border-green-200';
        case 'updated': return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'deleted': return 'bg-red-100 text-red-800 border-red-200';
        default: return 'bg-muted text-muted-foreground border-border';
    }
};

const propertiesSummary = (properties: Record<string, unknown>) => {
    const attributes = properties?.attributes as Record<string, unknown> | undefined;
    const old = properties?.old as Record<string, unknown> | undefined;
    const keys = Array.from(new Set([
        ...Object.keys(attributes || {}),
        ...Object.keys(old || {}),
    ])).slice(0, 4);

    if (!keys.length) return '-';

    const summary = keys.join(', ');
    return keys.length === 4 ? `${summary}...` : summary;
};

const displayValue = (value: unknown) => {
    if (value === null || value === undefined || value === '') return '-';
    if (typeof value === 'boolean') return value ? 'true' : 'false';
    if (typeof value === 'object') return JSON.stringify(value);

    return String(value);
};

const changedRows = (log: ActivityLog | null) => {
    const properties = log?.properties || {};
    const attributes = (properties.attributes || {}) as Record<string, unknown>;
    const old = (properties.old || {}) as Record<string, unknown>;
    const keys = Array.from(new Set([
        ...Object.keys(old),
        ...Object.keys(attributes),
    ]));

    return keys.map((key) => ({
        field: key,
        oldValue: displayValue(old[key]),
        newValue: displayValue(attributes[key]),
    }));
};

const formatJson = (value: unknown) => JSON.stringify(value ?? {}, null, 2);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Logs" />

        <div class="px-4 py-6 sm:px-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight text-foreground">Logs</h1>
                    <p class="mt-2 text-sm text-muted-foreground">Review and manage activity log entries.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" :disabled="loading" @click="fetchLogs(meta.current_page)">
                        <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': loading }" />
                        Refresh
                    </Button>
                    <Button variant="outline" :disabled="pruning" @click="pruneOldLogs">
                        <ShieldAlert class="mr-2 h-4 w-4" />
                        Delete 60+ Days
                    </Button>
                    <Button variant="destructive" :disabled="deleting || selectedCount === 0" @click="deleteSelectedLogs">
                        <Trash2 class="mr-2 h-4 w-4" />
                        Delete Selected
                        <span v-if="selectedCount" class="ml-1">({{ selectedCount }})</span>
                    </Button>
                </div>
            </div>

            <div class="mt-6 grid gap-3 border-y border-border/70 py-4 md:grid-cols-2 xl:grid-cols-[1.5fr_1fr_1fr_1fr_1fr_1fr_1fr_auto]">
                <div>
                    <Label for="log-search" class="text-xs font-semibold uppercase text-muted-foreground">Search</Label>
                    <div class="relative mt-1">
                        <Input id="log-search" v-model="filters.search" type="search" placeholder="Search logs" class="pl-9" />
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    </div>
                </div>
                <div>
                    <Label for="log-name-filter" class="text-xs font-semibold uppercase text-muted-foreground">Log</Label>
                    <select id="log-name-filter" v-model="filters.log_name" class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">All logs</option>
                        <option v-for="option in filterOptions.log_names" :key="option" :value="option">{{ option }}</option>
                    </select>
                </div>
                <div>
                    <Label for="event-filter" class="text-xs font-semibold uppercase text-muted-foreground">Event</Label>
                    <select id="event-filter" v-model="filters.event" class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">All events</option>
                        <option v-for="option in filterOptions.events" :key="option" :value="option">{{ option }}</option>
                    </select>
                </div>
                <div>
                    <Label for="subject-filter" class="text-xs font-semibold uppercase text-muted-foreground">Subject</Label>
                    <select id="subject-filter" v-model="filters.subject_type" class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">All subjects</option>
                        <option v-for="option in filterOptions.subject_types" :key="option" :value="option">{{ option.split('\\').pop() }}</option>
                    </select>
                </div>
                <div>
                    <Label for="causer-filter" class="text-xs font-semibold uppercase text-muted-foreground">Causer</Label>
                    <select id="causer-filter" v-model="filters.causer_type" class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm">
                        <option value="">All causers</option>
                        <option v-for="option in filterOptions.causer_types" :key="option" :value="option">{{ option.split('\\').pop() }}</option>
                    </select>
                </div>
                <div>
                    <Label for="date-from-filter" class="text-xs font-semibold uppercase text-muted-foreground">From</Label>
                    <Input id="date-from-filter" v-model="filters.date_from" type="date" class="mt-1" />
                </div>
                <div>
                    <Label for="date-to-filter" class="text-xs font-semibold uppercase text-muted-foreground">To</Label>
                    <Input id="date-to-filter" v-model="filters.date_to" type="date" class="mt-1" />
                </div>
                <div class="flex items-end">
                    <Button variant="outline" class="w-full" @click="resetFilters">
                        <RotateCcw class="mr-2 h-4 w-4" />
                        Reset
                    </Button>
                </div>
            </div>

            <div class="mt-6 overflow-hidden rounded-lg border border-border bg-card">
                <div class="flex items-center justify-between gap-3 border-b border-border/70 bg-muted/20 px-4 py-3">
                    <div class="text-sm text-muted-foreground">
                        Showing <span class="font-medium text-foreground">{{ meta.from || 0 }}</span>
                        -
                        <span class="font-medium text-foreground">{{ meta.to || 0 }}</span>
                        of <span class="font-medium text-foreground">{{ meta.total }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-sm text-muted-foreground">
                        <span>Rows</span>
                        <select v-model.number="perPage" class="h-9 rounded-md border border-input bg-background px-2 text-sm">
                            <option :value="10">10</option>
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                        </select>
                    </div>
                </div>

                <div class="relative overflow-x-auto">
                    <div v-if="loading" class="absolute inset-0 z-10 flex items-center justify-center bg-background/80">
                        <RefreshCw class="h-8 w-8 animate-spin text-muted-foreground" />
                    </div>
                    <table class="min-w-full divide-y divide-border text-sm">
                        <thead class="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="w-12 px-4 py-3">
                                    <input
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-border"
                                        :checked="allVisibleSelected"
                                        @change="toggleVisibleSelection(checkboxChecked($event))"
                                    />
                                </th>
                                <th class="px-4 py-3 font-semibold">Time</th>
                                <th class="px-4 py-3 font-semibold">Event</th>
                                <th class="px-4 py-3 font-semibold">Description</th>
                                <th class="px-4 py-3 font-semibold">Subject</th>
                                <th class="px-4 py-3 font-semibold">Causer</th>
                                <th class="px-4 py-3 font-semibold">Changed</th>
                                <th class="px-4 py-3 text-right font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/70 bg-background">
                            <tr v-for="log in logs" :key="log.id" class="hover:bg-muted/30">
                                <td class="px-4 py-3">
                                    <input
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-border"
                                        :checked="selectedIds.has(log.id)"
                                        @change="toggleLog(log.id, checkboxChecked($event))"
                                    />
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ formatDate(log.created_at) }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span :class="['inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold', eventBadgeClass(log.event)]">
                                        {{ log.event || 'activity' }}
                                    </span>
                                </td>
                                <td class="min-w-[280px] max-w-xl px-4 py-3">
                                    <div class="font-medium text-foreground">{{ log.description }}</div>
                                    <div class="mt-0.5 text-xs text-muted-foreground">{{ log.log_name || 'default' }} #{{ log.id }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ entityLabel(log.subject_type_label, log.subject_id) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ entityLabel(log.causer_type_label, log.causer_id) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ propertiesSummary(log.properties) }}</td>
                                <td class="px-4 py-3 text-right">
                                    <Button variant="ghost" size="sm" title="View log" @click="viewLog(log)">
                                        <Eye class="h-4 w-4" />
                                    </Button>
                                    <Button variant="ghost" size="sm" :disabled="deleting" title="Delete log" @click="deleteLog(log)">
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="!loading && logs.length === 0" class="p-8 text-center text-sm text-muted-foreground">
                        No logs found.
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border/70 bg-muted/20 px-4 py-3 text-sm text-muted-foreground">
                    <span>Page <span class="font-semibold text-foreground">{{ meta.current_page }}</span> / <span class="font-semibold text-foreground">{{ meta.last_page }}</span></span>
                    <div class="flex items-center gap-2">
                        <Button variant="outline" size="sm" :disabled="meta.current_page <= 1 || loading" @click="goToPage(meta.current_page - 1)">Prev</Button>
                        <Button variant="outline" size="sm" :disabled="meta.current_page >= meta.last_page || loading" @click="goToPage(meta.current_page + 1)">Next</Button>
                    </div>
                </div>
            </div>
        </div>

        <Dialog :open="isViewOpen" @update:open="isViewOpen = $event">
            <DialogScrollContent class="max-w-4xl">
                <DialogHeader>
                    <DialogTitle>Log #{{ viewingLog?.id }}</DialogTitle>
                    <DialogDescription>{{ viewingLog?.description }}</DialogDescription>
                </DialogHeader>

                <div v-if="viewingLog" class="space-y-6">
                    <div class="grid gap-3 rounded-lg border border-border/70 bg-muted/20 p-4 text-sm md:grid-cols-2">
                        <div>
                            <div class="text-xs font-semibold uppercase text-muted-foreground">Event</div>
                            <div class="mt-1">
                                <span :class="['inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold', eventBadgeClass(viewingLog.event)]">
                                    {{ viewingLog.event || 'activity' }}
                                </span>
                            </div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase text-muted-foreground">Log</div>
                            <div class="mt-1 font-medium text-foreground">{{ viewingLog.log_name || 'default' }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase text-muted-foreground">Subject</div>
                            <div class="mt-1 font-medium text-foreground">{{ entityLabel(viewingLog.subject_type_label, viewingLog.subject_id) }}</div>
                            <div v-if="viewingLog.subject_type" class="mt-0.5 break-all text-xs text-muted-foreground">{{ viewingLog.subject_type }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase text-muted-foreground">Causer</div>
                            <div class="mt-1 font-medium text-foreground">{{ entityLabel(viewingLog.causer_type_label, viewingLog.causer_id) }}</div>
                            <div v-if="viewingLog.causer_type" class="mt-0.5 break-all text-xs text-muted-foreground">{{ viewingLog.causer_type }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase text-muted-foreground">Created</div>
                            <div class="mt-1 font-medium text-foreground">{{ formatDate(viewingLog.created_at) }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase text-muted-foreground">Updated</div>
                            <div class="mt-1 font-medium text-foreground">{{ formatDate(viewingLog.updated_at) }}</div>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-border/70">
                        <div class="border-b border-border/70 bg-muted/30 px-4 py-3 text-xs font-semibold uppercase text-muted-foreground">
                            Changed Fields
                        </div>
                        <table v-if="changedRows(viewingLog).length" class="min-w-full divide-y divide-border text-sm">
                            <thead class="bg-muted/20 text-left text-xs uppercase text-muted-foreground">
                                <tr>
                                    <th class="px-4 py-2 font-semibold">Field</th>
                                    <th class="px-4 py-2 font-semibold">Old</th>
                                    <th class="px-4 py-2 font-semibold">New</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/70">
                                <tr v-for="row in changedRows(viewingLog)" :key="row.field">
                                    <td class="whitespace-nowrap px-4 py-2 font-medium text-foreground">{{ row.field }}</td>
                                    <td class="max-w-xs break-all px-4 py-2 text-muted-foreground">{{ row.oldValue }}</td>
                                    <td class="max-w-xs break-all px-4 py-2 text-muted-foreground">{{ row.newValue }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <div v-else class="px-4 py-3 text-sm text-muted-foreground">No changed fields recorded.</div>
                    </div>

                    <div class="space-y-2">
                        <div class="text-xs font-semibold uppercase text-muted-foreground">Properties</div>
                        <pre class="max-h-80 overflow-auto rounded-lg border border-border/70 bg-muted/30 p-4 text-xs leading-relaxed text-foreground">{{ formatJson(viewingLog.properties) }}</pre>
                    </div>
                </div>
            </DialogScrollContent>
        </Dialog>
    </AppLayout>
</template>
