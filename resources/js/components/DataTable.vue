<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FileDown, FileText, Printer, Search, RefreshCw } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type Align = 'left' | 'center' | 'right';
type RowClass = string | string[] | Record<string, boolean> | undefined;

interface Column {
    key: string;
    label: string;
    sortable?: boolean;
    align?: Align;
    visible?: boolean;
    exportable?: boolean;
}

const props = defineProps<{
    columns: Column[];
    rows: Record<string, unknown>[];
    title?: string;
    busy?: boolean;
    searchPlaceholder?: string;
    defaultPageSize?: number;
    pageSizeOptions?: number[];
    rowClass?: (row: Record<string, unknown>) => RowClass;
    showSearch?: boolean;
    showExports?: boolean;
}>();

const searchTerm = ref('');
const sortKey = ref<string | null>(null);
const sortDirection = ref<'asc' | 'desc'>('asc');
const currentPage = ref(1);
const pageSizeOptions = computed(() =>
    props.pageSizeOptions && props.pageSizeOptions.length
        ? props.pageSizeOptions
        : [10, 25, 50, 100],
);
const pageSize = ref(props.defaultPageSize ?? pageSizeOptions.value[0] ?? 10);
const visibleColumnKeys = ref<Record<string, boolean>>({});
const showColumnMenu = ref(false);

watch(
    () => props.columns,
    (cols) => {
        visibleColumnKeys.value = Object.fromEntries(
            cols.map((col) => [col.key, col.visible !== false]),
        );
    },
    { immediate: true, deep: true },
);

const visibleColumns = computed(() =>
    props.columns.filter((col) => visibleColumnKeys.value[col.key] !== false),
);

const isActionColumn = (col: Column) => {
    const key = col.key.toLowerCase();
    const label = col.label.toLowerCase();

    return (
        col.exportable === false ||
        key === 'actions' ||
        key === 'action' ||
        key.endsWith('_actions') ||
        label === 'actions' ||
        label === 'action'
    );
};

const exportColumns = computed(() =>
    visibleColumns.value.filter((col) => !isActionColumn(col)),
);

const filteredRows = computed(() => {
    const term = searchTerm.value.toLowerCase().trim();
    let data = [...props.rows];

    if (term) {
        data = data.filter((row) =>
            Object.values(row)
                .filter((value) => ['string', 'number'].includes(typeof value))
                .some((value) => String(value).toLowerCase().includes(term)),
        );
    }

    if (sortKey.value) {
        data.sort((a, b) => {
            const aVal = a[sortKey.value as keyof typeof a];
            const bVal = b[sortKey.value as keyof typeof b];

            if (aVal === bVal) return 0;
            if (aVal === undefined || aVal === null) return -1;
            if (bVal === undefined || bVal === null) return 1;

            return sortDirection.value === 'asc'
                ? String(aVal).localeCompare(String(bVal))
                : String(bVal).localeCompare(String(aVal));
        });
    }

    return data;
});

const totalPages = computed(() =>
    Math.max(1, Math.ceil(filteredRows.value.length / pageSize.value)),
);

const goToPage = (page: number) => {
    const maxPage = totalPages.value;
    const nextPage = Math.min(Math.max(1, page), maxPage);
    currentPage.value = nextPage;
};

const goToFirstPage = () => goToPage(1);

const paginatedRows = computed(() => {
    const start = (currentPage.value - 1) * pageSize.value;
    return filteredRows.value.slice(start, start + pageSize.value);
});

const firstItem = computed(() =>
    filteredRows.value.length ? (currentPage.value - 1) * pageSize.value + 1 : 0,
);
const lastItem = computed(() =>
    Math.min(currentPage.value * pageSize.value, filteredRows.value.length),
);

watch(
    () => searchTerm.value,
    () => {
        currentPage.value = 1;
    },
);

watch(
    () => [pageSize.value, filteredRows.value.length],
    () => {
        const maxPage = Math.max(1, Math.ceil(filteredRows.value.length / pageSize.value));
        if (currentPage.value > maxPage) currentPage.value = maxPage;
    },
);

const toggleSort = (key: string) => {
    if (sortKey.value === key) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortDirection.value = 'asc';
    }
};

const getRowClass = (row: Record<string, unknown>) => props.rowClass?.(row) ?? '';

const buildCsv = () => {
    const header = exportColumns.value.map((col) => `"${col.label}"`).join(',');
    const body = filteredRows.value
        .map((row) =>
            exportColumns.value
                .map((col) => {
                    const value = row[col.key];
                    return `"${value !== undefined && value !== null ? String(value).replace(/"/g, '""') : ''}"`;
                })
                .join(','),
        )
        .join('\n');

    return `${header}\n${body}`;
};

const downloadFile = (content: string, mime: string, filename: string) => {
    const blob = new Blob([content], { type: mime });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
};

const exportExcel = () => {
    downloadFile(buildCsv(), 'text/csv;charset=utf-8;', `${props.title ?? 'export'}.csv`);
};

const escapeHtml = (value: unknown) =>
    String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

const renderTableHtml = () => {
    const head = exportColumns.value
        .map((col) => `<th style="text-align:${col.align ?? 'left'};padding:8px;border-bottom:1px solid #e5e7eb;">${escapeHtml(col.label)}</th>`)
        .join('');

    const body = filteredRows.value
        .map((row) => {
            const cells = exportColumns.value
                .map((col) => `<td style="padding:8px;text-align:${col.align ?? 'left'};border-bottom:1px solid #f4f4f5;">${escapeHtml(row[col.key])}</td>`)
                .join('');
            return `<tr>${cells}</tr>`;
        })
        .join('');

    return `
        <table style="width:100%;border-collapse:collapse;font-family:ui-sans-serif,system-ui,sans-serif;font-size:12px;">
            <thead style="background:#f8fafc;">
                <tr>${head}</tr>
            </thead>
            <tbody>${body}</tbody>
        </table>
    `;
};

const openPrintWindow = () => {
    const html = renderTableHtml();
    const printWindow = window.open('', '_blank', 'width=1200,height=800');

    if (!printWindow) {
        return;
    }

    printWindow.document.write(`
        <html>
            <head>
                <title>${props.title ?? 'Export'}</title>
                <style>
                    @page { size: auto; margin: 16px; }
                    body { margin: 16px; color: #0f172a; }
                    h1 { font-size: 18px; margin-bottom: 12px; }
                    table { page-break-inside: auto; }
                    tr { page-break-inside: avoid; page-break-after: auto; }
                </style>
            </head>
            <body>
                <h1>${props.title ?? 'Export'}</h1>
                ${html}
            </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
    printWindow.close();
};

const exportPdf = () => openPrintWindow();
const printTable = () => openPrintWindow();

defineExpose({
    goToPage,
    goToFirstPage,
    currentPage,
});
</script>

<template>
    <div class="relative space-y-4">
        <div
            v-if="busy"
            class="absolute inset-0 z-10 flex items-center justify-center rounded-lg bg-background/80"
        >
            <RefreshCw
                class="size-8 animate-spin text-muted-foreground"
            />
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <template v-if="showExports !== false">
                    <Button variant="outline" size="sm" @click="exportPdf">
                        <FileText class="size-4" />
                        PDF
                    </Button>
                    <Button variant="outline" size="sm" @click="exportExcel">
                        <FileDown class="size-4" />
                        Excel
                    </Button>
                    <Button variant="outline" size="sm" @click="printTable">
                        <Printer class="size-4" />
                        Print
                    </Button>
                </template>
                <div class="relative">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="showColumnMenu = !showColumnMenu"
                    >
                        Columns
                    </Button>
                    <div
                        v-if="showColumnMenu"
                        class="absolute z-20 mt-2 w-52 rounded-md border border-border/70 bg-background shadow-lg"
                    >
                        <div class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Column visibility
                        </div>
                        <div class="max-h-64 space-y-1 overflow-auto px-3 py-2 text-sm">
                            <label
                                v-for="column in columns"
                                :key="`toggle-${column.key}`"
                                class="flex cursor-pointer items-center gap-2"
                            >
                                <input
                                    v-model="visibleColumnKeys[column.key]"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-border/80 text-primary focus:ring-2 focus:ring-ring/50"
                                />
                                <span>{{ column.label }}</span>
                            </label>
                        </div>
                    </div>
                </div>
                <slot name="toolbar" />
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <div v-if="showSearch !== false" class="relative w-64">
                    <Input
                        v-model="searchTerm"
                        :placeholder="searchPlaceholder ?? 'Search users...'"
                        type="search"
                        class="pl-9"
                    />
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                </div>

                <div class="flex items-center gap-2 text-sm text-muted-foreground">
                    <span>Rows</span>
                    <select
                        v-model.number="pageSize"
                        class="h-9 rounded-md border border-input bg-background px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50"
                    >
                        <option
                            v-for="option in pageSizeOptions"
                            :key="option"
                            :value="option"
                        >
                            {{ option }}
                        </option>
                    </select>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border/60">
            <table class="min-w-full divide-y divide-border text-sm">
                <thead class="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th
                            v-for="column in visibleColumns"
                            :key="column.key"
                            class="px-4 py-3 font-semibold"
                        >
                            <button
                                v-if="column.sortable"
                                class="flex items-center gap-1"
                                type="button"
                                @click="toggleSort(column.key)"
                            >
                                <span>{{ column.label }}</span>
                                <span
                                    class="text-[10px]"
                                    v-if="sortKey === column.key"
                                >
                                    {{ sortDirection === 'asc' ? '▲' : '▼' }}
                                </span>
                            </button>
                            <span v-else>{{ column.label }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/70 bg-background">
                    <tr
                        v-for="row in paginatedRows"
                        :key="String(row.id)"
                        :class="getRowClass(row)"
                    >
                        <td
                            v-for="column in visibleColumns"
                            :key="`${row.id}-${column.key}`"
                            class="whitespace-nowrap px-4 py-3"
                            :class="{
                                'text-right': column.align === 'right',
                                'text-center': column.align === 'center',
                            }"
                        >
                            <slot
                                :name="`cell-${column.key}`"
                                :row="row"
                                :column="column"
                            >
                                <slot name="cell" :row="row" :column="column">
                                    {{ row[column.key] ?? '—' }}
                                </slot>
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div
                v-if="!filteredRows.length"
                class="p-6 text-center text-sm text-muted-foreground"
            >
                <slot name="empty">No records found.</slot>
            </div>
        </div>

        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border/60 bg-muted/20 px-4 py-3 text-sm text-muted-foreground"
        >
            <div>
                Showing
                <span class="font-medium text-foreground">{{ firstItem }}</span>
                -
                <span class="font-medium text-foreground">{{ lastItem }}</span>
                of
                <span class="font-medium text-foreground">{{
                    filteredRows.length
                }}</span>
                entries
            </div>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="currentPage <= 1"
                    @click="currentPage--"
                >
                    Prev
                </Button>
                <span class="text-xs">
                    Page
                    <span class="font-semibold text-foreground">{{
                        currentPage
                    }}</span>
                    /
                    <span class="font-semibold text-foreground">
                        {{ totalPages }}
                    </span>
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="currentPage >= totalPages"
                    @click="currentPage++"
                >
                    Next
                </Button>
            </div>
        </div>
    </div>
</template>
