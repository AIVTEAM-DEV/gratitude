<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import {
    Bug,
    FileText,
    RefreshCw,
    Search,
    Terminal,
    Trash2,
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type DebugLogFile = {
    name: string;
    size: number;
    size_label: string;
    modified_at: string | null;
    modified_label: string | null;
};

type DebugLogEntry = {
    timestamp: string | null;
    environment: string | null;
    level: string;
    message: string;
    line_count: number;
    trace: string;
    raw: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Developer', href: '/developer/debug-logs' },
    { title: 'Debug Logs', href: '/developer/debug-logs' },
];

const files = ref<DebugLogFile[]>([]);
const selectedFile = ref('');
const lineCount = ref(300);
const content = ref('');
const entries = ref<DebugLogEntry[]>([]);
const loading = ref(false);
const clearing = ref(false);
const error = ref('');
const notice = ref('');
const search = ref('');
const viewMode = ref<'entries' | 'raw'>('entries');
const linesReturned = ref(0);

const selectedFileMeta = computed(() =>
    files.value.find((file) => file.name === selectedFile.value),
);

const filteredEntries = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) return entries.value;

    return entries.value.filter((entry) =>
        [
            entry.timestamp,
            entry.environment,
            entry.level,
            entry.message,
            entry.raw,
        ]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(term)),
    );
});

const filteredContent = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) return content.value;

    return content.value
        .split('\n')
        .filter((line) => line.toLowerCase().includes(term))
        .join('\n');
});

const fetchLogs = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await axios.get('/internal-api/developer/debug-logs', {
            params: {
                file: selectedFile.value || undefined,
                lines: lineCount.value,
            },
        });

        files.value = response.data.files || [];
        selectedFile.value = response.data.file || '';
        content.value = response.data.content || '';
        entries.value = response.data.entries || [];
        linesReturned.value = response.data.lines_returned || 0;
    } catch (requestError: any) {
        error.value =
            requestError.response?.data?.message ||
            'Failed to load debug logs.';
    } finally {
        loading.value = false;
    }
};

const clearSelectedLog = async () => {
    if (!selectedFile.value || clearing.value) return;

    if (!window.confirm(`Clear ${selectedFile.value}?`)) return;

    clearing.value = true;
    error.value = '';
    notice.value = '';

    try {
        const response = await axios.delete(
            '/internal-api/developer/debug-logs',
            {
                data: { file: selectedFile.value },
            },
        );

        notice.value =
            response.data?.message || `${selectedFile.value} cleared.`;
        search.value = '';
        await fetchLogs();
    } catch (requestError: any) {
        error.value =
            requestError.response?.data?.message ||
            'Failed to clear debug log.';
    } finally {
        clearing.value = false;
    }
};

onMounted(() => fetchLogs());

const formatDate = (value: string | null) => {
    if (!value) return '-';

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    return date.toLocaleString();
};

const levelClass = (level: string) => {
    switch (level.toUpperCase()) {
        case 'EMERGENCY':
        case 'ALERT':
        case 'CRITICAL':
        case 'ERROR':
            return 'border-red-200 bg-red-50 text-red-800';
        case 'WARNING':
            return 'border-amber-200 bg-amber-50 text-amber-800';
        case 'NOTICE':
        case 'INFO':
            return 'border-blue-200 bg-blue-50 text-blue-800';
        case 'DEBUG':
            return 'border-zinc-200 bg-zinc-50 text-zinc-700';
        default:
            return 'border-border bg-muted text-muted-foreground';
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Debug Logs" />

        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <div
                class="flex flex-col gap-4 border-b border-border/70 pb-5 lg:flex-row lg:items-center lg:justify-between"
            >
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        Debug Logs
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {{ selectedFile || 'No log file selected' }}
                        <span v-if="selectedFileMeta">
                            · {{ selectedFileMeta.size_label }} ·
                            {{ selectedFileMeta.modified_label }}
                        </span>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        variant="destructive"
                        :disabled="loading || clearing || !selectedFile"
                        class="gap-2"
                        @click="clearSelectedLog"
                    >
                        <Trash2 class="h-4 w-4" />
                        {{ clearing ? 'Clearing...' : 'Clear Log' }}
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="loading || clearing"
                        class="gap-2"
                        @click="fetchLogs"
                    >
                        <RefreshCw
                            class="h-4 w-4"
                            :class="{ 'animate-spin': loading }"
                        />
                        Refresh
                    </Button>
                </div>
            </div>

            <div
                class="grid gap-3 border-y border-border/70 py-4 lg:grid-cols-[1.2fr_1fr_auto_auto]"
            >
                <div>
                    <Label
                        for="debug-log-search"
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        Search
                    </Label>
                    <div class="relative mt-1">
                        <Input
                            id="debug-log-search"
                            v-model="search"
                            type="search"
                            placeholder="Search loaded lines"
                            class="pl-9"
                        />
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                        />
                    </div>
                </div>

                <div>
                    <Label
                        for="debug-log-file"
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        File
                    </Label>
                    <select
                        id="debug-log-file"
                        v-model="selectedFile"
                        class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                        :disabled="loading || files.length === 0"
                        @change="fetchLogs"
                    >
                        <option
                            v-for="file in files"
                            :key="file.name"
                            :value="file.name"
                        >
                            {{ file.name }} · {{ file.size_label }}
                        </option>
                    </select>
                </div>

                <div>
                    <Label
                        for="debug-log-lines"
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        Lines
                    </Label>
                    <select
                        id="debug-log-lines"
                        v-model.number="lineCount"
                        class="mt-1 h-10 w-32 rounded-md border border-input bg-background px-3 text-sm"
                        :disabled="loading"
                        @change="fetchLogs"
                    >
                        <option :value="100">100</option>
                        <option :value="300">300</option>
                        <option :value="500">500</option>
                        <option :value="1000">1000</option>
                        <option :value="2000">2000</option>
                    </select>
                </div>

                <div>
                    <Label
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        View
                    </Label>
                    <div
                        class="mt-1 flex h-10 rounded-md border border-input bg-background p-1"
                    >
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded px-3 text-sm font-medium"
                            :class="
                                viewMode === 'entries'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            @click="viewMode = 'entries'"
                        >
                            <FileText class="h-4 w-4" />
                            Entries
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded px-3 text-sm font-medium"
                            :class="
                                viewMode === 'raw'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            @click="viewMode = 'raw'"
                        >
                            <Terminal class="h-4 w-4" />
                            Raw
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-if="error"
                class="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive"
            >
                {{ error }}
            </div>

            <div
                v-if="notice"
                class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800"
            >
                {{ notice }}
            </div>

            <div
                v-if="!loading && files.length === 0"
                class="rounded-lg border border-border bg-card p-8 text-center text-sm text-muted-foreground"
            >
                No log files found.
            </div>

            <div
                v-else-if="viewMode === 'entries'"
                class="overflow-hidden rounded-lg border border-border bg-card"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-border/70 bg-muted/20 px-4 py-3 text-sm"
                >
                    <div class="text-muted-foreground">
                        Showing
                        <span class="font-medium text-foreground">
                            {{ filteredEntries.length }}
                        </span>
                        entries from
                        <span class="font-medium text-foreground">
                            {{ linesReturned }}
                        </span>
                        lines
                    </div>
                    <Bug class="h-4 w-4 text-muted-foreground" />
                </div>

                <div class="relative">
                    <div
                        v-if="loading"
                        class="absolute inset-0 z-10 flex items-center justify-center bg-background/80"
                    >
                        <RefreshCw
                            class="h-8 w-8 animate-spin text-muted-foreground"
                        />
                    </div>

                    <div class="divide-y divide-border/70">
                        <article
                            v-for="(entry, index) in filteredEntries"
                            :key="`${entry.timestamp}-${entry.level}-${index}`"
                            class="p-4 hover:bg-muted/20"
                        >
                            <div
                                class="flex flex-col gap-3 lg:flex-row lg:items-start"
                            >
                                <div class="flex min-w-48 items-center gap-2">
                                    <span
                                        :class="[
                                            'inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold',
                                            levelClass(entry.level),
                                        ]"
                                    >
                                        {{ entry.level }}
                                    </span>
                                    <span class="text-xs text-muted-foreground">
                                        {{ entry.environment || 'log' }}
                                    </span>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="text-xs text-muted-foreground">
                                        {{ formatDate(entry.timestamp) }}
                                    </div>
                                    <p
                                        class="mt-1 text-sm font-medium break-words text-foreground"
                                    >
                                        {{ entry.message || entry.raw }}
                                    </p>

                                    <details v-if="entry.trace" class="mt-3">
                                        <summary
                                            class="cursor-pointer text-xs font-semibold text-muted-foreground uppercase"
                                        >
                                            Trace · {{ entry.line_count }} lines
                                        </summary>
                                        <pre
                                            class="mt-2 max-h-96 overflow-auto rounded-md bg-muted/40 p-3 text-xs leading-relaxed text-foreground"
                                            >{{ entry.trace }}</pre
                                        >
                                    </details>
                                </div>
                            </div>
                        </article>
                    </div>

                    <div
                        v-if="!loading && filteredEntries.length === 0"
                        class="p-8 text-center text-sm text-muted-foreground"
                    >
                        No matching entries.
                    </div>
                </div>
            </div>

            <div
                v-else
                class="overflow-hidden rounded-lg border border-border bg-card"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-border/70 bg-muted/20 px-4 py-3 text-sm text-muted-foreground"
                >
                    <span>{{ linesReturned }} loaded lines</span>
                    <span>{{ selectedFile }}</span>
                </div>
                <div class="relative">
                    <div
                        v-if="loading"
                        class="absolute inset-0 z-10 flex items-center justify-center bg-background/80"
                    >
                        <RefreshCw
                            class="h-8 w-8 animate-spin text-muted-foreground"
                        />
                    </div>
                    <pre
                        class="max-h-[70vh] overflow-auto bg-background p-4 text-xs leading-relaxed text-foreground"
                        >{{ filteredContent || 'No matching lines.' }}</pre
                    >
                </div>
            </div>
        </div>
    </AppLayout>
</template>
