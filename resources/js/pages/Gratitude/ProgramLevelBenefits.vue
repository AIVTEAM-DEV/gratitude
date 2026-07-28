<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Trash2 } from 'lucide-vue-next';
import UpdateProgramLevelBenefit from '@/components/Gratitude/UpdateProgramLevelBenefit.vue';
import AddProgramLevelBenefit from '@/components/Gratitude/AddProgramLevelBenefit.vue';
import ViewProgramLevelBenefit from '@/components/Gratitude/ViewProgramLevelBenefit.vue';
import DataTable from '@/components/DataTable.vue';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Gratitude Program', href: '/gratitude' },
    {
        title: 'Program Level Benefits',
        href: '/gratitude/program-level-benefits',
    },
];

const gridData = ref<any>({ levels: [], grid: [] });
const loading = ref(true);

const fetchProgramBenefits = async () => {
    try {
        const response = await axios.get(
            '/internal-api/gratitude/program-benefits',
        );
        gridData.value = response.data;
    } catch (error) {
        console.error('Failed to load gratitude program benefits', error);
    } finally {
        loading.value = false;
    }
};

const deleteBenefit = async (id: number) => {
    if (
        confirm(
            'Are you sure you want to delete this benefit? This will remove all its level assignments.',
        )
    ) {
        try {
            await axios.delete(`/internal-api/gratitude/benefits/${id}`);
            fetchProgramBenefits();
        } catch (error) {
            console.error('Failed to delete program level benefit', error);
        }
    }
};

const tableColumns = computed(() => {
    const cols: any[] = [
        { key: 'benefit', label: 'Base Benefit', sortable: true },
    ];
    gridData.value.levels.forEach((level: any) => {
        cols.push({
            key: `level_${level.id}`,
            label: level.name,
            align: 'center',
            sortable: false,
        });
    });
    cols.push({
        key: 'actions',
        label: 'Actions',
        align: 'right',
        exportable: false,
        sortable: false,
    });
    return cols;
});

onMounted(() => {
    fetchProgramBenefits();
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Program Level Benefits" />

        <div class="px-4 py-6 sm:px-6 lg:px-8">
            <div class="mb-8 sm:flex sm:items-center">
                <div class="sm:flex-auto">
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        Program Level Benefits
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Manage which benefits are assigned to each tier level
                        and specify tier-specific values.
                    </p>
                </div>
                <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
                    <AddProgramLevelBenefit
                        :levels="gridData.levels"
                        :benefits="gridData.grid"
                        @saved="fetchProgramBenefits"
                    />
                </div>
            </div>

            <div
                class="w-full rounded-lg border border-border bg-card p-4 shadow-sm"
            >
                <DataTable
                    :columns="tableColumns"
                    :rows="gridData.grid"
                    :busy="loading"
                    title="Program Level Benefits"
                    class="w-full"
                >
                    <!-- Custom rendering for Benefit info -->
                    <template #cell-benefit="{ row }">
                        <div class="font-medium text-foreground">
                            {{ (row as any).name }}
                        </div>
                        <div
                            class="max-w-xs truncate text-xs text-muted-foreground"
                            v-if="(row as any).description"
                        >
                            {{ (row as any).description }}
                        </div>
                        <span
                            v-if="(row as any).benefit_key"
                            class="mt-0.5 inline-flex items-center rounded-md bg-muted px-1.5 py-0.5 font-mono text-xs text-muted-foreground"
                            >{{ (row as any).benefit_key }}</span
                        >
                    </template>

                    <!-- Dynamic slots for each level mapped to its pivot data -->
                    <template
                        v-for="level in gridData.levels"
                        :key="'col_' + level.id"
                        #[`cell-level_${level.id}`]="{ row }"
                    >
                        <template
                            v-if="(row as any).levels[level.id]?.has_benefit"
                        >
                            <div
                                class="flex flex-col items-center justify-center"
                            >
                                <span
                                    class="inline-flex items-center rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary"
                                >
                                    {{
                                        (row as any).levels[level.id]
                                            .formatted_value ||
                                        (row as any).levels[level.id].value ||
                                        'Enabled'
                                    }}
                                </span>
                                <span
                                    class="mt-1 text-[10px] text-muted-foreground"
                                    v-if="
                                        (row as any).levels[level.id]
                                            .description
                                    "
                                >
                                    {{
                                        (row as any).levels[level.id]
                                            .description
                                    }}
                                </span>
                            </div>
                        </template>
                        <span v-else class="text-sm text-muted-foreground/30"
                            >-</span
                        >
                    </template>

                    <!-- Row actions -->
                    <template #cell-actions="{ row }">
                        <div class="flex items-center justify-end space-x-2">
                            <ViewProgramLevelBenefit
                                :benefit="row as any"
                                :levels="gridData.levels"
                            />
                            <UpdateProgramLevelBenefit
                                :benefit="row as any"
                                :levels="gridData.levels"
                                @saved="fetchProgramBenefits"
                            />
                            <Button
                                variant="ghost"
                                size="icon"
                                @click="deleteBenefit((row as any).id)"
                                class="h-8 w-8 text-destructive hover:bg-destructive/10 hover:text-destructive"
                            >
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </div>
                    </template>

                    <template #empty>
                        No benefits established yet. Create a Base Benefit
                        first.
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
