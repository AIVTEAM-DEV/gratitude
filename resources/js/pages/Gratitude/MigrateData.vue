<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { computed, ref } from 'vue';
import axios from 'axios';
import {
    ArrowLeft,
    CheckCircle2,
    Database,
    Gift,
    RefreshCw,
    Upload,
    UserRound,
    XCircle,
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type ImportAction = {
    key: string;
    title: string;
    description: string;
    endpoint: string;
    method: 'get' | 'post';
    buttonLabel: string;
    confirmLabel: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Gratitude Program', href: '/gratitude' },
    { title: 'Migrate Data', href: '/gratitude/migrate-data' },
];

const page = usePage();
const canImportGratitude = computed(() =>
    Boolean((page.props.auth as any)?.can_import_gratitude),
);

const actions: ImportAction[] = [
    {
        key: 'gratitudes-active',
        title: 'Active Gratitudes',
        description: 'Summary account rows',
        endpoint: '/internal-api/gratitude/migrate-data/active',
        method: 'get',
        buttonLabel: 'Import Active',
        confirmLabel: 'active gratitude table',
    },
    {
        key: 'gratitudes-inactive',
        title: 'Inactive Gratitudes',
        description: 'Inactive summary account rows',
        endpoint: '/internal-api/gratitude/migrate-data/inactive',
        method: 'get',
        buttonLabel: 'Import Inactive',
        confirmLabel: 'inactive gratitude table',
    },
    {
        key: 'accounts-active',
        title: 'Active Account Data',
        description: 'Detailed points and guest data',
        endpoint: '/internal-api/gratitude/migrate-account-data/active',
        method: 'get',
        buttonLabel: 'Import Active Data',
        confirmLabel: 'active account data',
    },
    {
        key: 'accounts-inactive',
        title: 'Inactive Account Data',
        description: 'Inactive detailed account data',
        endpoint: '/internal-api/gratitude/migrate-account-data/inactive',
        method: 'get',
        buttonLabel: 'Import Inactive Data',
        confirmLabel: 'inactive account data',
    },
    {
        key: 'benefits',
        title: 'Benefits',
        description: 'Base benefits and level values',
        endpoint: '/internal-api/gratitude/migrate-benefits/data',
        method: 'get',
        buttonLabel: 'Import Benefits',
        confirmLabel: 'benefits',
    },
];

const runningAction = ref<string | null>(null);
const singleGratitudeNumber = ref('');
const result = ref<{
    title: string;
    ok: boolean;
    message: string;
    payload?: unknown;
} | null>(null);

const isRunning = (key: string) => runningAction.value === key;

const runImport = async (action: ImportAction) => {
    if (!canImportGratitude.value || runningAction.value) return;
    if (!window.confirm(`Import ${action.confirmLabel} now?`)) return;

    runningAction.value = action.key;
    result.value = null;

    try {
        const response = await axios.request({
            method: action.method,
            url: action.endpoint,
        });

        result.value = {
            title: action.title,
            ok: true,
            message:
                response.data?.message ||
                `${action.title} imported successfully.`,
            payload: response.data,
        };
    } catch (error: any) {
        result.value = {
            title: action.title,
            ok: false,
            message:
                error.response?.data?.message ||
                `Failed to import ${action.confirmLabel}.`,
            payload: error.response?.data,
        };
    } finally {
        runningAction.value = null;
    }
};

const importSingleAccount = async () => {
    const gratitudeNumber = singleGratitudeNumber.value.trim();

    if (!gratitudeNumber) return;

    await runImport({
        key: 'single-account',
        title: `Account ${gratitudeNumber}`,
        description: 'Single account data',
        endpoint: `/internal-api/gratitude/account/${encodeURIComponent(gratitudeNumber)}/import`,
        method: 'post',
        buttonLabel: 'Import Account',
        confirmLabel: `account ${gratitudeNumber}`,
    });
};

const formattedPayload = computed(() => {
    if (!result.value?.payload) return '';

    return JSON.stringify(result.value.payload, null, 2);
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Migrate Data" />

        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <div
                class="flex flex-col gap-4 border-b border-border/70 pb-5 md:flex-row md:items-center md:justify-between"
            >
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        Migrate Data
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Developer imports for Gratitude data.
                    </p>
                </div>
                <Link href="/gratitude/accounts">
                    <Button variant="outline" class="gap-2">
                        <ArrowLeft class="h-4 w-4" />
                        Accounts
                    </Button>
                </Link>
            </div>

            <div
                v-if="!canImportGratitude"
                class="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive"
            >
                Developer access required.
            </div>

            <template v-else>
                <div class="grid gap-4 xl:grid-cols-2">
                    <div
                        v-for="action in actions"
                        :key="action.key"
                        class="flex flex-col gap-4 rounded-lg border border-border bg-card p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="flex items-start gap-3">
                            <div
                                class="rounded-md bg-primary/10 p-2 text-primary"
                            >
                                <Gift
                                    v-if="action.key === 'benefits'"
                                    class="h-5 w-5"
                                />
                                <UserRound
                                    v-else-if="
                                        action.key.startsWith('accounts')
                                    "
                                    class="h-5 w-5"
                                />
                                <Database v-else class="h-5 w-5" />
                            </div>
                            <div>
                                <h2
                                    class="text-base font-semibold text-foreground"
                                >
                                    {{ action.title }}
                                </h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ action.description }}
                                </p>
                            </div>
                        </div>
                        <Button
                            class="gap-2 sm:w-44"
                            :disabled="runningAction !== null"
                            @click="runImport(action)"
                        >
                            <RefreshCw
                                v-if="isRunning(action.key)"
                                class="h-4 w-4 animate-spin"
                            />
                            <Upload v-else class="h-4 w-4" />
                            {{
                                isRunning(action.key)
                                    ? 'Importing...'
                                    : action.buttonLabel
                            }}
                        </Button>
                    </div>
                </div>

                <div
                    class="rounded-lg border border-border bg-card p-4 shadow-sm"
                >
                    <div
                        class="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end"
                    >
                        <div>
                            <Label for="gratitude-number">Single Account</Label>
                            <Input
                                id="gratitude-number"
                                v-model="singleGratitudeNumber"
                                class="mt-2"
                                placeholder="Gratitude number"
                                @keyup.enter="importSingleAccount"
                            />
                        </div>
                        <Button
                            class="gap-2 lg:w-44"
                            :disabled="
                                runningAction !== null ||
                                !singleGratitudeNumber.trim()
                            "
                            @click="importSingleAccount"
                        >
                            <RefreshCw
                                v-if="isRunning('single-account')"
                                class="h-4 w-4 animate-spin"
                            />
                            <Upload v-else class="h-4 w-4" />
                            {{
                                isRunning('single-account')
                                    ? 'Importing...'
                                    : 'Import Account'
                            }}
                        </Button>
                    </div>
                </div>

                <div
                    v-if="result"
                    class="rounded-lg border p-4"
                    :class="
                        result.ok
                            ? 'border-green-200 bg-green-50 text-green-950'
                            : 'border-red-200 bg-red-50 text-red-950'
                    "
                >
                    <div class="flex items-start gap-3">
                        <CheckCircle2
                            v-if="result.ok"
                            class="mt-0.5 h-5 w-5 text-green-700"
                        />
                        <XCircle v-else class="mt-0.5 h-5 w-5 text-red-700" />
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-semibold">
                                {{ result.title }}
                            </h2>
                            <p class="mt-1 text-sm">{{ result.message }}</p>
                            <pre
                                v-if="formattedPayload"
                                class="mt-3 max-h-80 overflow-auto rounded-md bg-background/80 p-3 text-xs text-foreground"
                                >{{ formattedPayload }}</pre
                            >
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
