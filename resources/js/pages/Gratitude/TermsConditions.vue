<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import {
    CheckCircle2,
    Plus,
    RefreshCw,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import { onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type GratitudeTermCondition = {
    id: number;
    title: string | null;
    content: string;
    status: boolean;
    sort_order: number;
    source_key: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Gratitude Program', href: '/gratitude' },
    { title: 'Terms & Conditions', href: '/gratitude/terms-conditions' },
];

const terms = ref<GratitudeTermCondition[]>([]);
const loading = ref(false);
const saving = ref(false);
const updatingId = ref<number | null>(null);
const deletingId = ref<number | null>(null);
const errorMessage = ref('');
const form = ref({
    title: '',
    content: '',
    status: true,
});

const fetchTerms = async () => {
    loading.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.get(
            '/internal-api/gratitude/terms-conditions',
        );
        terms.value = response.data;
    } catch (error) {
        console.error('Failed to load Gratitude terms and conditions', error);
        errorMessage.value = 'Unable to load the terms and conditions.';
    } finally {
        loading.value = false;
    }
};

const createTerm = async () => {
    if (!form.value.content.trim() || saving.value) return;

    saving.value = true;
    errorMessage.value = '';

    try {
        await axios.post('/internal-api/gratitude/terms-conditions', {
            title: form.value.title.trim() || null,
            content: form.value.content.trim(),
            status: form.value.status,
        });

        form.value = {
            title: '',
            content: '',
            status: true,
        };
        await fetchTerms();
    } catch (error: any) {
        console.error('Failed to create Gratitude term and condition', error);
        errorMessage.value =
            error.response?.data?.message ||
            'Unable to create the term and condition.';
    } finally {
        saving.value = false;
    }
};

const updateStatus = async (term: GratitudeTermCondition) => {
    if (updatingId.value !== null) return;

    updatingId.value = term.id;
    errorMessage.value = '';

    try {
        const response = await axios.patch(
            `/internal-api/gratitude/terms-conditions/${term.id}/status`,
            { status: !term.status },
        );
        const index = terms.value.findIndex((item) => item.id === term.id);
        if (index !== -1) {
            terms.value[index] = response.data.term;
        }
    } catch (error: any) {
        console.error('Failed to update term and condition status', error);
        errorMessage.value =
            error.response?.data?.message ||
            'Unable to update the term and condition status.';
    } finally {
        updatingId.value = null;
    }
};

const deleteTerm = async (term: GratitudeTermCondition) => {
    if (
        deletingId.value !== null ||
        !window.confirm('Delete this term and condition?')
    ) {
        return;
    }

    deletingId.value = term.id;
    errorMessage.value = '';

    try {
        await axios.delete(
            `/internal-api/gratitude/terms-conditions/${term.id}`,
        );
        terms.value = terms.value.filter((item) => item.id !== term.id);
    } catch (error: any) {
        console.error('Failed to delete Gratitude term and condition', error);
        errorMessage.value =
            error.response?.data?.message ||
            'Unable to delete the term and condition.';
    } finally {
        deletingId.value = null;
    }
};

onMounted(fetchTerms);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Gratitude Terms & Conditions" />

        <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <div
                class="flex flex-col gap-4 border-b border-border/70 pb-5 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        Terms &amp; Conditions
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Manage the program-wide terms that apply to Gratitude
                        members.
                    </p>
                </div>
                <Button
                    variant="outline"
                    class="gap-2"
                    :disabled="loading"
                    @click="fetchTerms"
                >
                    <RefreshCw
                        class="h-4 w-4"
                        :class="{ 'animate-spin': loading }"
                    />
                    Refresh
                </Button>
            </div>

            <div
                v-if="errorMessage"
                class="rounded-md border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
            >
                {{ errorMessage }}
            </div>

            <form
                class="rounded-lg border border-border bg-card p-5 shadow-sm"
                @submit.prevent="createTerm"
            >
                <div class="mb-5">
                    <h2 class="text-lg font-semibold">Add a term</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        New terms are added after the existing terms.
                    </p>
                </div>

                <div class="grid gap-4">
                    <div>
                        <Label for="term-title">Title (optional)</Label>
                        <Input
                            id="term-title"
                            v-model="form.title"
                            class="mt-1"
                            maxlength="255"
                            placeholder="e.g. Redemption requests"
                        />
                    </div>
                    <div>
                        <Label for="term-content">Term or condition</Label>
                        <textarea
                            id="term-content"
                            v-model="form.content"
                            rows="5"
                            required
                            class="mt-1 flex w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                            placeholder="Enter the full term or condition."
                        />
                    </div>
                    <label class="flex items-center gap-2 text-sm font-medium">
                        <input
                            v-model="form.status"
                            type="checkbox"
                            class="h-4 w-4 rounded border-input text-primary"
                        />
                        Active immediately
                    </label>
                </div>

                <div class="mt-5 flex justify-end">
                    <Button
                        type="submit"
                        class="gap-2"
                        :disabled="saving || !form.content.trim()"
                    >
                        <RefreshCw v-if="saving" class="h-4 w-4 animate-spin" />
                        <Plus v-else class="h-4 w-4" />
                        {{ saving ? 'Adding...' : 'Add term' }}
                    </Button>
                </div>
            </form>

            <div
                class="overflow-hidden rounded-lg border border-border bg-card shadow-sm"
            >
                <div
                    class="flex items-center justify-between border-b border-border px-5 py-4"
                >
                    <div>
                        <h2 class="font-semibold">Program terms</h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ terms.length }}
                            {{ terms.length === 1 ? 'record' : 'records' }}
                        </p>
                    </div>
                </div>

                <div v-if="loading" class="p-8 text-center">
                    <RefreshCw
                        class="mx-auto h-5 w-5 animate-spin text-muted-foreground"
                    />
                    <p class="mt-2 text-sm text-muted-foreground">
                        Loading terms...
                    </p>
                </div>

                <div
                    v-else-if="terms.length === 0"
                    class="p-8 text-center text-sm text-muted-foreground"
                >
                    No terms and conditions have been created.
                </div>

                <div v-else class="divide-y divide-border">
                    <article
                        v-for="(term, index) in terms"
                        :key="term.id"
                        class="grid gap-4 p-5 lg:grid-cols-[auto_1fr_auto] lg:items-start"
                    >
                        <div
                            class="flex h-8 w-8 items-center justify-center rounded-full bg-muted text-sm font-semibold text-muted-foreground"
                        >
                            {{ index + 1 }}
                        </div>

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3
                                    v-if="term.title"
                                    class="font-semibold text-foreground"
                                >
                                    {{ term.title }}
                                </h3>
                                <span
                                    v-if="term.source_key"
                                    class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800"
                                >
                                    Imported
                                </span>
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        term.status
                                            ? 'bg-green-100 text-green-800'
                                            : 'bg-red-100 text-red-800'
                                    "
                                >
                                    <CheckCircle2
                                        v-if="term.status"
                                        class="h-3 w-3"
                                    />
                                    <XCircle v-else class="h-3 w-3" />
                                    {{ term.status ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                            <p
                                class="mt-2 text-sm leading-6 whitespace-pre-wrap text-foreground/90"
                            >
                                {{ term.content }}
                            </p>
                        </div>

                        <div class="flex items-center gap-2 lg:justify-end">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="updatingId === term.id"
                                @click="updateStatus(term)"
                            >
                                {{
                                    updatingId === term.id
                                        ? 'Updating...'
                                        : term.status
                                          ? 'Deactivate'
                                          : 'Activate'
                                }}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                :disabled="deletingId === term.id"
                                aria-label="Delete term and condition"
                                @click="deleteTerm(term)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </div>
                    </article>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
