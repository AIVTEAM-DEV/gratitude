<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { Trash2 } from 'lucide-vue-next';
import { ref, onMounted } from 'vue';
import AddLevel from '@/components/Gratitude/AddLevel.vue';
import UpdateLevel from '@/components/Gratitude/UpdateLevel.vue';
import ViewLevel from '@/components/Gratitude/ViewLevel.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

// ...
const deleteLevel = async (id: number) => {
    if (confirm('Are you sure you want to delete this level?')) {
        try {
            await axios.delete(`/internal-api/gratitude/levels/${id}`);
            fetchLevels();
        } catch (error) {
            console.error('Failed to delete level', error);
        }
    }
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Gratitude Program', href: '/gratitude' },
    { title: 'Levels', href: '/gratitude/levels' },
];

const levels = ref<any[]>([]);

const fetchLevels = async () => {
    try {
        const response = await axios.get('/internal-api/gratitude/levels');
        levels.value = response.data;
    } catch (error) {
        console.error('Failed to load gratitude levels', error);
    }
};

onMounted(() => {
    fetchLevels();
});

const levelMediaUrl = (level: any, type: 'icon' | 'image') => {
    const url = type === 'icon' ? level.level_icon_url : level.level_image_url;
    const path = type === 'icon' ? level.level_icon : level.level_image;

    if (url) return url;
    if (!path) return '';
    if (path.startsWith('http://') || path.startsWith('https://')) return path;
    if (path.startsWith('/storage/')) return path;
    if (path.startsWith('storage/')) return `/${path}`;
    return `/storage/${path}`;
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Gratitude Levels" />

        <div class="px-4 py-6 sm:px-6 lg:px-8">
            <div class="mb-8 sm:flex sm:items-center">
                <div class="sm:flex-auto">
                    <h1
                        class="text-3xl font-bold tracking-tight text-foreground"
                    >
                        Gratitude Levels
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Manage the different tiers and point thresholds for the
                        gratitude program.
                    </p>
                </div>
                <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
                    <AddLevel @saved="fetchLevels" />
                </div>
            </div>

            <div
                class="overflow-x-auto rounded-lg border border-border bg-card"
            >
                <table class="min-w-full divide-y divide-border">
                    <thead class="bg-muted/50">
                        <tr>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Name
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Media
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Min Points
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Max Points
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Journey Pts / $
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Partner Pts / $
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Gift Card Pts / $
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Airline Miles Pts / $
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Cycle
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Journeys
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Earned Expiry
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Bonus Expiry
                            </th>
                            <th
                                class="px-6 py-3 text-left text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Status
                            </th>
                            <th
                                class="px-6 py-3 text-right text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border bg-card">
                        <tr v-for="level in levels" :key="level.id">
                            <td
                                class="px-6 py-4 font-medium whitespace-nowrap text-foreground"
                            >
                                {{ level.name }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center gap-2">
                                    <img
                                        v-if="levelMediaUrl(level, 'icon')"
                                        :src="levelMediaUrl(level, 'icon')"
                                        :alt="`${level.name} icon`"
                                        class="h-8 w-8 rounded border border-border bg-background object-contain"
                                    />
                                    <img
                                        v-if="levelMediaUrl(level, 'image')"
                                        :src="levelMediaUrl(level, 'image')"
                                        :alt="`${level.name} image`"
                                        class="h-8 w-12 rounded border border-border bg-background object-cover"
                                    />
                                    <span
                                        v-if="
                                            !levelMediaUrl(level, 'icon') &&
                                            !levelMediaUrl(level, 'image')
                                        "
                                        class="text-xs text-muted-foreground"
                                        >—</span
                                    >
                                </div>
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-muted-foreground"
                            >
                                {{ level.min_points }}
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-muted-foreground"
                            >
                                {{ level.max_points || '∞' }}
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-muted-foreground"
                            >
                                {{ level.redemption_points_per_dollar || 35 }}
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-muted-foreground"
                            >
                                {{
                                    level.partner_points_per_dollar ||
                                    level.redemption_points_per_dollar ||
                                    35
                                }}
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-muted-foreground"
                            >
                                {{
                                    level.gift_card_points_per_dollar ||
                                    level.redemption_points_per_dollar ||
                                    35
                                }}
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-muted-foreground"
                            >
                                {{
                                    level.airline_miles_points_per_dollar ||
                                    level.redemption_points_per_dollar ||
                                    35
                                }}
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-muted-foreground"
                            >
                                {{ level.level_interval_years || 2 }} years
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-muted-foreground"
                            >
                                {{ level.min_journeys || 0 }}
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-muted-foreground"
                            >
                                {{ level.earned_expire_days || 730 }} days
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-muted-foreground"
                            >
                                {{ level.bonus_expire_days || 730 }} days
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-muted-foreground"
                            >
                                <span
                                    v-if="level.status"
                                    class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800"
                                    >Active</span
                                >
                                <span
                                    v-else
                                    class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800"
                                    >Inactive</span
                                >
                            </td>
                            <td
                                class="px-6 py-4 text-right text-sm font-medium whitespace-nowrap"
                            >
                                <div
                                    class="flex items-center justify-end space-x-2"
                                >
                                    <ViewLevel :level="level" />
                                    <UpdateLevel
                                        :level="level"
                                        @saved="fetchLevels"
                                    />
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        @click="deleteLevel(level.id)"
                                        class="h-8 w-8 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="levels.length === 0">
                            <td
                                colspan="14"
                                class="px-6 py-4 text-center text-muted-foreground"
                            >
                                No levels established yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
