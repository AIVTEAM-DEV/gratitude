<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Eye } from 'lucide-vue-next';

const props = defineProps({
    benefit: { type: Object, required: true },
    levels: { type: Array, required: true },
});

const isOpen = ref(false);

const openModal = () => {
    isOpen.value = true;
};
</script>

<template>
    <div>
        <Button
            @click="openModal"
            variant="ghost"
            size="icon"
            class="h-8 w-8 hover:bg-muted"
            title="View Benefit"
        >
            <Eye class="h-4 w-4" />
        </Button>

        <div
            v-if="isOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 text-left"
        >
            <div
                class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg border border-border bg-card p-6 shadow-lg"
            >
                <div class="mb-4">
                    <h2 class="mb-1 text-xl font-bold">
                        View Benefit: {{ benefit.name }}
                    </h2>
                    <p class="mb-4 text-sm text-foreground">
                        {{ benefit.description || 'No description provided.' }}
                    </p>
                    <span
                        v-if="benefit.status || benefit.is_active"
                        class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800"
                        >Active</span
                    >
                    <span
                        v-else
                        class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800"
                        >Inactive</span
                    >
                </div>

                <div class="mt-4 border-t pt-4">
                    <h3 class="mb-2 text-lg font-semibold">
                        Level Assignments
                    </h3>
                    <div
                        v-for="level in levels as any[]"
                        :key="level.id"
                        class="mb-2 rounded-md border border-border bg-muted/10 p-4"
                    >
                        <div class="mb-2 flex items-center justify-between">
                            <span
                                class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                                >{{ level.name }}</span
                            >
                            <span
                                v-if="benefit.levels[level.id]?.has_benefit"
                                class="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary"
                                >Enabled</span
                            >
                            <span
                                v-else
                                class="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                                >Disabled</span
                            >
                        </div>
                        <div
                            v-if="benefit.levels[level.id]?.has_benefit"
                            class="mt-2 grid grid-cols-12 gap-2"
                        >
                            <div class="col-span-3">
                                <span
                                    class="block text-[10px] font-semibold text-muted-foreground uppercase"
                                    >Value</span
                                >
                                <span class="text-sm font-medium">{{
                                    benefit.levels[level.id]?.formatted_value ||
                                    benefit.levels[level.id]?.value ||
                                    '-'
                                }}</span>
                            </div>
                            <div class="col-span-2">
                                <span
                                    class="block text-[10px] font-semibold text-muted-foreground uppercase"
                                    >Rule</span
                                >
                                <span class="text-sm font-medium">{{
                                    (
                                        benefit.levels[level.id]?.rule_key ||
                                        'text'
                                    ).replaceAll('_', ' ')
                                }}</span>
                            </div>
                            <div class="col-span-3">
                                <span
                                    class="block text-[10px] font-semibold text-muted-foreground uppercase"
                                    >Details</span
                                >
                                <span class="text-sm">{{
                                    benefit.levels[level.id]?.description || '-'
                                }}</span>
                            </div>
                            <div class="col-span-2">
                                <span
                                    class="block text-[10px] font-semibold text-muted-foreground uppercase"
                                    >System Status</span
                                >
                                <span
                                    class="text-sm font-medium"
                                    :class="
                                        benefit.levels[level.id]?.is_active
                                            ? 'text-green-600'
                                            : 'text-red-500'
                                    "
                                    >{{
                                        benefit.levels[level.id]?.is_active
                                            ? 'Active'
                                            : 'Inactive'
                                    }}</span
                                >
                            </div>
                            <div class="col-span-2">
                                <span
                                    class="block text-[10px] font-semibold text-muted-foreground uppercase"
                                    >Web Status</span
                                >
                                <span
                                    class="text-sm font-medium"
                                    :class="
                                        benefit.levels[level.id]?.web_status
                                            ? 'text-green-600'
                                            : 'text-red-500'
                                    "
                                    >{{
                                        benefit.levels[level.id]?.web_status
                                            ? 'Active'
                                            : 'Inactive'
                                    }}</span
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end border-t pt-4">
                    <Button
                        type="button"
                        variant="outline"
                        @click="isOpen = false"
                        >Close</Button
                    >
                </div>
            </div>
        </div>
    </div>
</template>
