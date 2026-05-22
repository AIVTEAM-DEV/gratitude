<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { BreadcrumbItem } from '@/types';
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import AddEarnedPoints from '@/components/Gratitude/AddEarnedPoints.vue';
import AddBonusPoints from '@/components/Gratitude/AddBonusPoints.vue';
import UpdateAccountStatus from '@/components/Gratitude/UpdateAccountStatus.vue';
import UpdateEarnedPoints from '@/components/Gratitude/UpdateEarnedPoints.vue';
import UpdateBonusPoints from '@/components/Gratitude/UpdateBonusPoints.vue';
import CancelPointEntry from '@/components/Gratitude/CancelPointEntry.vue';
import DeletePointEntry from '@/components/Gratitude/DeletePointEntry.vue';
import DeleteCancellation from '@/components/Gratitude/DeleteCancellation.vue';
import AddRedemption from '@/components/Gratitude/AddRedemption.vue';
import DeleteRedemption from '@/components/Gratitude/DeleteRedemption.vue';
import UpdateRedemption from '@/components/Gratitude/UpdateRedemption.vue';
import ViewEntryDetails from '@/components/Gratitude/ViewEntryDetails.vue';
import ViewRedemptionDetails from '@/components/Gratitude/ViewRedemptionDetails.vue';
import AddEarnedBenefit from '@/components/Gratitude/AddEarnedBenefit.vue';
import UpdateEarnedBenefit from '@/components/Gratitude/UpdateEarnedBenefit.vue';
import DeleteEarnedBenefit from '@/components/Gratitude/DeleteEarnedBenefit.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    ArrowLeft,
    Award,
    ChevronDown,
    Clock,
    FileText,
    Gift,
    History,
    Minus,
    Printer,
    RefreshCw,
    ScrollText,
    ShieldAlert,
    Star,
    TrendingDown,
    TrendingUp,
    Zap,
} from 'lucide-vue-next';

const props = defineProps({
    gratitudeNumber: {
        type: String,
        required: true,
    },
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Gratitude Program', href: '/gratitude' },
    { title: 'Accounts', href: '/gratitude/accounts' },
    {
        title: `Gratitude #${props.gratitudeNumber}`,
        href: `/gratitude/account/show/${props.gratitudeNumber}`,
    },
];

const loading = ref(true);
const data = ref<any>({
    gratitude: null,
    guests: [],
    journeys: [],
    levels: [],
    earned_points: [],
    bonus_points: [],
    cancellations: [],
    redemptions: [],
    next_level: null,
    points_to_next_level: 0,
    rolling_tier_points: 0,
    level_benefits: [],
    points_per_dollar: 35,
    partner_points_per_dollar: 35,
    points_history: [],
    interval_start: null,
    interval_end: null,
    interval_years: 2,
    current_level_min: 0,
    earned_benefits: [],
    available_benefits: [],
});

const fetchDetails = async () => {
    loading.value = true;
    try {
        const response = await axios.get(
            `/internal-api/gratitude/account/show/${props.gratitudeNumber}`,
        );
        data.value = response.data;
    } catch (error) {
        console.error('Failed to load gratitude details', error);
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchDetails();
});

// Computed Values for Summary
const tierPointsSum = computed(() => {
    return data.value.earned_points.reduce(
        (sum: number, p: any) => sum + Number(p.points || 0),
        0,
    );
});
const bonusPointsSum = computed(() => {
    return data.value.bonus_points.reduce(
        (sum: number, p: any) => sum + Number(p.points || 0),
        0,
    );
});
const cancellationsSum = computed(() => {
    return data.value.cancellations.reduce(
        (sum: number, p: any) => sum + Number(p.points || 0),
        0,
    );
});
const redemptionsSum = computed(() => {
    return data.value.redemptions.reduce(
        (sum: number, p: any) => sum + Number(p.points || 0),
        0,
    );
});
const totalPoints = computed(() => {
    return (
        data.value.gratitude?.totalRemainingPoints ??
        Math.max(
            0,
            tierPointsSum.value +
                bonusPointsSum.value -
                cancellationsSum.value -
                redemptionsSum.value -
                expiredPointsSum.value,
        )
    );
});
const usablePoints = computed(() => {
    return data.value.gratitude?.useablePoints || 0;
});
const earnedExpireDays = computed(() =>
    Number(data.value.level_info?.earned_expire_days || 730),
);
const bonusExpireDays = computed(() =>
    Number(data.value.level_info?.bonus_expire_days || 730),
);
const expiredPointsSum = computed(() => {
    const now = new Date();
    const earnedExpired = data.value.earned_points
        .filter(
            (p: any) =>
                !p.cancel_id && p.expires_at && new Date(p.expires_at) <= now,
        )
        .reduce((sum: number, p: any) => sum + pointRemaining(p), 0);
    const bonusExpired = data.value.bonus_points
        .filter(
            (p: any) =>
                !p.cancel_id && p.expires_at && new Date(p.expires_at) <= now,
        )
        .reduce((sum: number, p: any) => sum + pointRemaining(p), 0);
    return earnedExpired + bonusExpired;
});

const pointCancelled = (p: any) => Number(p.cancelled_points || 0);
const pointRemaining = (p: any) =>
    p.remaining_points !== undefined && p.remaining_points !== null
        ? Math.max(0, Number(p.remaining_points || 0))
        : Math.max(
              0,
              Number(p.points || 0) -
                  Number(p.redeemed_points || 0) -
                  pointCancelled(p),
          );
const pointIsFullyCancelled = (p: any) =>
    pointCancelled(p) > 0 && pointRemaining(p) === 0;
const cancellationList = (p: any) => p.cancellations_list || [];
const hasAllocatedCancellation = (c: any) =>
    Array.isArray(c.points_breakdown) && c.points_breakdown.length > 0;
const effectiveDateValue = (p: any) =>
    p?.usable_date ||
    p?.useable_date ||
    p?.displayDate ||
    p?.date ||
    p?.created_at ||
    '';
const formatDate = (date: any, fallback = 'N/A') => {
    if (!date) return fallback;

    if (typeof date === 'string') {
        const match = date.match(/^(\d{4}-\d{2}-\d{2})/);
        if (match) return match[1];
    }

    const parsed = new Date(date);

    if (Number.isNaN(parsed.getTime())) {
        return fallback;
    }

    return parsed.toISOString().split('T')[0];
};
const redemptionRecordDate = (redemption: any) =>
    formatDate(
        redemption?.points_breakdown?.redemption_date ||
            redemption?.points_breakdown?.imported_redemption_date ||
            redemption?.points_breakdown?.date ||
            redemption?.created_at,
    );
const redemptionDetailDate = (detail: any) =>
    formatDate(
        detail?.points_breakdown?.date ||
            detail?.redeem_point?.points_breakdown?.redemption_date ||
            detail?.redeem_point?.points_breakdown?.imported_redemption_date ||
            detail?.redeem_point?.points_breakdown?.date ||
            detail?.created_at,
        '',
    );
const guestOwnership = (guest: any) =>
    String(
        guest?.ownership ||
            guest?.gratitude_ownership ||
            guest?.gratitudeOwnership ||
            guest?.role ||
            guest?.type ||
            '',
    ).toLowerCase();
const isPrimaryGuest = (guest: any) =>
    guestOwnership(guest) === 'primary' ||
    guest?.is_primary === true ||
    guest?.isPrimary === true;
const guestDisplayName = (guest: any) => {
    const name = [guest?.preferred_name, guest?.first_name, guest?.last_name]
        .filter(Boolean)
        .join(' ')
        .trim();

    return (
        name ||
        guest?.name ||
        guest?.full_name ||
        guest?.email ||
        'Unnamed guest'
    );
};
const guestKey = (guest: any, index: number) =>
    guest?.guest_id ||
    guest?.guestId ||
    guest?.id ||
    guest?.email ||
    `guest-${index}`;
const primaryGuests = computed(() =>
    (data.value.guests || []).filter((guest: any) => isPrimaryGuest(guest)),
);
const secondaryGuests = computed(() =>
    (data.value.guests || []).filter((guest: any) => !isPrimaryGuest(guest)),
);

// Combine Tier Points + Cancellations
const combinedTierPoints = computed(() => {
    const now = new Date();
    const earned = data.value.earned_points.map((p: any) => {
        let isCancelled = p.cancel_id && p.cancellation;
        let hasCancellation = isCancelled || pointCancelled(p) > 0;
        let isExpired =
            !isCancelled && !!p.expires_at && new Date(p.expires_at) <= now;
        return {
            ...p,
            rowType: 'earned',
            isExpired,
            displayDate: effectiveDateValue(p),
            displayProject: p.project_data
                ? `${p.project_data.projectNumber || p.project_data.number || ''} - ${p.project_data.name || p.project_data.title || p.category}`
                : p.category,
            displaySubtitle: p.project_data
                ? p.project_data.subtitle || p.project_data.type || ''
                : '',
            displayPoints: p.points,
            displayExpiresOn: p.expires_at || '',
            displayDescription: p.description || 'Tier Points earned',
            hasCancellation,
            isFullyCancelled: pointIsFullyCancelled(p),
            cancellationData: p.cancellation || null,
            cancellationsList: cancellationList(p),
            redemptionsList: buildRedemptionsList(p),
            sortDate: effectiveDateValue(p),
        };
    });

    const linkedCancelIds = new Set(
        data.value.earned_points.map((p: any) => p.cancel_id).filter(Boolean),
    );
    const standaloneCancels = data.value.cancellations
        .filter(
            (c: any) =>
                !linkedCancelIds.has(c.id) && !hasAllocatedCancellation(c),
        )
        .map((c: any) => ({
            ...c,
            rowType: 'cancel',
            displayDate: c.date,
            displayProject: c.category || 'Point Cancellation',
            displaySubtitle: '',
            displayPoints: c.points,
            displayExpiresOn: '',
            displayDescription: c.description || 'Cancellation',
            sortDate: c.date,
            redemptionsList: [],
        }));

    return [...earned, ...standaloneCancels].sort((a, b) => {
        const dateA = new Date(a.sortDate || a.displayDate || 0).getTime();
        const dateB = new Date(b.sortDate || b.displayDate || 0).getTime();
        return dateB - dateA;
    });
});

const combinedBonusPoints = computed(() => {
    const now = new Date();
    return data.value.bonus_points
        .map((p: any) => {
            let isCancelled = p.cancel_id && p.cancellation;
            let hasCancellation = isCancelled || pointCancelled(p) > 0;
            let isExpired =
                !isCancelled && !!p.expires_at && new Date(p.expires_at) <= now;
            return {
                ...p,
                rowType: 'bonus',
                isExpired,
                hasCancellation,
                isFullyCancelled: pointIsFullyCancelled(p),
                displayDate: effectiveDateValue(p),
                cancellationData: p.cancellation || null,
                cancellationsList: cancellationList(p),
                redemptionsList: buildRedemptionsList(p),
            };
        })
        .sort(
            (a: any, b: any) =>
                new Date(effectiveDateValue(b)).getTime() -
                new Date(effectiveDateValue(a)).getTime(),
        );
});

// Normalize redemption history from both RedeemPointsDetails records and the legacy redemption_history JSON column
const buildRedemptionsList = (p: any) => {
    const fromDetails = (p.redemptions || []).map((r: any) => ({
        _key: `detail-${r.id}`,
        points: r.points,
        reason: r.redeem_point?.reason ?? 'Redemption',
        date: redemptionDetailDate(r),
        redeem_id: r.redeem_id,
    }));

    const existingRedeemIds = new Set(
        fromDetails.map((r: any) => r.redeem_id).filter(Boolean),
    );

    const fromHistory = (p.redemption_history || [])
        .filter((h: any) => !existingRedeemIds.has(h.redemption_id))
        .map((h: any, i: number) => ({
            _key: `history-${i}`,
            points: h.points,
            reason: h.reason ?? 'Redemption',
            date: h.date ?? '',
        }));

    return [...fromDetails, ...fromHistory];
};

// Collapsible UI State
const isTierOpen = ref(false);
const isBonusOpen = ref(false);
const isRedeemOpen = ref(false);
const isBenefitsOpen = ref(false);
const isPointsHistoryOpen = ref(false);
const isLevelHistoryOpen = ref(false);
const isEarnedBenefitsOpen = ref(false);

// Activity log modal
const activityLog = ref<any[]>([]);
const activityLogLoading = ref(false);
const activityLogBenefitId = ref<number | null>(null);
const isActivityLogOpen = ref(false);

const viewActivityLog = async (benefit: any) => {
    activityLogBenefitId.value = benefit.id;
    activityLog.value = [];
    isActivityLogOpen.value = true;
    activityLogLoading.value = true;
    try {
        const res = await axios.get(
            `/internal-api/gratitude/${props.gratitudeNumber}/earned-benefits/${benefit.id}/log`,
        );
        activityLog.value = res.data.log ?? [];
    } catch {
        activityLog.value = [];
    } finally {
        activityLogLoading.value = false;
    }
};

// Level progress helpers
const levelProgressPct = computed(() => {
    const rolling = data.value.rolling_tier_points ?? 0;
    const nextMin = data.value.next_level
        ? rolling + (data.value.points_to_next_level ?? 0)
        : rolling;
    const currentMin = data.value.current_level_min ?? 0;
    const range = nextMin - currentMin;
    if (range <= 0) return 100;
    return Math.min(100, Math.round(((rolling - currentMin) / range) * 100));
});

const changeTypeIcon = (type: string) => {
    if (type === 'upgrade') return TrendingUp;
    if (type === 'downgrade') return TrendingDown;
    return Minus;
};
const changeTypeClass = (type: string) => {
    if (type === 'upgrade') return 'text-green-600 dark:text-green-400';
    if (type === 'downgrade') return 'text-red-600 dark:text-red-400';
    return 'text-muted-foreground';
};

const syncing = ref(false);
const syncBalance = async () => {
    syncing.value = true;
    try {
        await axios.post(
            `/internal-api/gratitude/${props.gratitudeNumber}/sync-balance`,
        );
        await fetchDetails();
    } catch (error) {
        console.error('Failed to sync balance', error);
    } finally {
        syncing.value = false;
    }
};

const formatNumber = (num: number) => {
    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(num);
};

const mediaUrl = (url?: string | null, path?: string | null) => {
    if (url) return url;
    if (!path) return '';
    if (path.startsWith('http://') || path.startsWith('https://')) return path;
    if (path.startsWith('/storage/')) return path;
    if (path.startsWith('storage/')) return `/${path}`;
    return `/storage/${path}`;
};

const currentLevelIconUrl = computed(() =>
    mediaUrl(
        data.value.level_info?.level_icon_url,
        data.value.level_info?.level_icon,
    ),
);

const escapeHtml = (value: any) =>
    String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

const pointsHistorySource = (entry: any) => {
    if (entry.source_type === 'LevelHistory') {
        return `${entry.level_from || ''} to ${entry.level_to || ''}`;
    }

    return `${entry.source_type || ''}${entry.source_id ? ` #${entry.source_id}` : ''}`.trim();
};

const downgradeLevelDescription = (
    fromLevel?: string | null,
    toLevel?: string | null,
) =>
    `You're back in ${toLevel || 'Explorer'} mode — your ${fromLevel || 'previous level'} badge is taking a short vacation until your next qualifying adventure`;

const levelHistoryDescription = (entry: any) => {
    const changeType = entry.change_type || entry.changeType;

    if (changeType === 'downgrade') {
        return downgradeLevelDescription(
            entry.level_from || entry.fromLevel,
            entry.level_to || entry.toLevel,
        );
    }

    return entry.description || entry.reason || 'Level changed';
};

const pointsHistoryDescription = (entry: any) => {
    if (entry.source_type === 'LevelHistory') {
        return `${levelHistoryDescription(entry)} (${formatNumber(entry.earned_points_at_change || 0)} pts)`;
    }

    return entry.description || '';
};

const buildPointsHistoryPrintHtml = () => {
    const rows = (data.value.points_history || [])
        .map(
            (entry: any) => `
            <tr>
                <td>${escapeHtml(formatDate(entry.date))}</td>
                <td>${escapeHtml(entry.source_type === 'LevelHistory' ? `level ${entry.change_type}` : entry.type)}</td>
                <td>${escapeHtml(pointsHistorySource(entry))}</td>
                <td>${escapeHtml(pointsHistoryDescription(entry))}</td>
                <td style="text-align:right;">${escapeHtml(entry.points >= 0 ? `+${formatNumber(entry.points)}` : formatNumber(entry.points))}</td>
            </tr>
        `,
        )
        .join('');

    return `
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Source</th>
                    <th>Description</th>
                    <th style="text-align:right;">Points</th>
                </tr>
            </thead>
            <tbody>${rows || '<tr><td colspan="5" style="text-align:center;">No point history recorded.</td></tr>'}</tbody>
        </table>
    `;
};

const openPointsHistoryPrintWindow = () => {
    const printWindow = window.open('', '_blank', 'width=1200,height=800');
    if (!printWindow) return;

    printWindow.document.write(`
        <html>
            <head>
                <title>Points History - ${escapeHtml(props.gratitudeNumber)}</title>
                <style>
                    @page { size: auto; margin: 16px; }
                    body { margin: 16px; color: #0f172a; font-family: ui-sans-serif, system-ui, sans-serif; }
                    h1 { font-size: 18px; margin: 0 0 4px; }
                    p { margin: 0 0 14px; color: #475569; font-size: 12px; }
                    table { width: 100%; border-collapse: collapse; font-size: 12px; }
                    th { text-align: left; padding: 8px; border-bottom: 1px solid #e5e7eb; background: #f8fafc; }
                    td { padding: 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
                    tr { page-break-inside: avoid; }
                </style>
            </head>
            <body>
                <h1>Points History</h1>
                <p>Gratitude ${escapeHtml(props.gratitudeNumber)}</p>
                ${buildPointsHistoryPrintHtml()}
            </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
    printWindow.close();
};

const printPointsHistory = () => openPointsHistoryPrintWindow();
const exportPointsHistoryPdf = () => openPointsHistoryPrintWindow();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Gratitude #${gratitudeNumber}`" />

        <div class="space-y-6 px-4 py-6 pb-20 sm:px-6 lg:px-8">
            <!-- Header Row -->
            <div
                class="mb-6 flex items-center justify-between border-b border-border/50 pb-6"
            >
                <div>
                    <h1
                        class="flex items-center gap-3 text-3xl font-bold tracking-tight text-foreground"
                    >
                        Gratitude
                        <span
                            class="text-4xl leading-none font-light text-muted-foreground/30"
                            >|</span
                        >
                        <span class="text-primary">{{ gratitudeNumber }}</span>
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Manage points, benefits, and guest status for this
                        account
                    </p>
                </div>
                <div>
                    <Link href="/gratitude/accounts">
                        <Button
                            variant="outline"
                            size="sm"
                            class="flex h-9 items-center gap-2 rounded-full border-border/50 px-4 text-muted-foreground shadow-sm transition-all hover:bg-muted/50 hover:text-foreground"
                        >
                            <ArrowLeft class="h-4 w-4" /> Back to Accounts
                        </Button>
                    </Link>
                </div>
            </div>

            <div
                v-if="loading && !data.gratitude"
                class="rounded-lg border border-border bg-card p-6 text-sm text-muted-foreground"
            >
                Loading account...
            </div>

            <!-- Dashboard Row 1: Guests & Actions in a Card -->
            <Card
                v-if="data.gratitude"
                class="mb-8 overflow-hidden border-border bg-gradient-to-r from-card to-muted/10 shadow-sm"
            >
                <div
                    class="flex flex-col justify-between gap-6 px-6 xl:flex-row xl:items-center"
                >
                    <!-- Guests -->
                    <div class="min-w-0 flex-1 space-y-3">
                        <template v-if="data.guests && data.guests.length">
                            <div class="space-y-1.5">
                                <p
                                    class="text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                >
                                    Primary Guest
                                </p>
                                <div class="flex flex-wrap items-center gap-2">
                                    <div
                                        v-for="(guest, index) in primaryGuests"
                                        :key="guestKey(guest, index)"
                                        class="flex items-center gap-2 rounded-full border border-blue-600/20 bg-blue-600/10 px-4 py-1.5 text-blue-700 shadow-sm dark:text-blue-400"
                                    >
                                        <span class="text-sm font-bold">{{
                                            guestDisplayName(guest)
                                        }}</span>
                                        <span
                                            v-if="guest.email"
                                            class="text-xs opacity-75"
                                            >{{ guest.email }}</span
                                        >
                                    </div>
                                    <span
                                        v-if="primaryGuests.length === 0"
                                        class="text-sm text-muted-foreground italic"
                                        >Not set</span
                                    >
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <p
                                    class="text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                >
                                    Secondary Guests
                                </p>
                                <div class="flex flex-wrap items-center gap-2">
                                    <div
                                        v-for="(
                                            guest, index
                                        ) in secondaryGuests"
                                        :key="guestKey(guest, index)"
                                        class="flex items-center gap-2 rounded-full border border-emerald-600/20 bg-emerald-600/10 px-4 py-1.5 text-emerald-700 shadow-sm dark:text-emerald-400"
                                    >
                                        <span class="text-sm font-bold">{{
                                            guestDisplayName(guest)
                                        }}</span>
                                        <span
                                            v-if="guest.email"
                                            class="text-xs opacity-75"
                                            >{{ guest.email }}</span
                                        >
                                    </div>
                                    <span
                                        v-if="secondaryGuests.length === 0"
                                        class="text-sm text-muted-foreground italic"
                                        >None</span
                                    >
                                </div>
                            </div>
                        </template>
                        <span
                            v-else
                            class="text-sm text-muted-foreground italic"
                            >No guests found</span
                        >
                    </div>

                    <!-- Actions & Level -->
                    <div
                        class="flex flex-col gap-4 sm:flex-row sm:items-center sm:gap-6"
                    >
                        <div class="flex flex-wrap items-center gap-3">
                            <Button
                                variant="outline"
                                class="flex h-10 items-center gap-2 rounded-lg px-4 text-xs font-bold tracking-wider uppercase shadow-md transition-all"
                                :disabled="syncing"
                                @click="syncBalance"
                            >
                                <RefreshCw
                                    class="h-3.5 w-3.5"
                                    :class="{ 'animate-spin': syncing }"
                                />
                                {{ syncing ? 'Syncing...' : 'Sync Balance' }}
                            </Button>
                            <UpdateAccountStatus
                                :gratitudeNumber="gratitudeNumber"
                                :gratitude="data.gratitude"
                                :levels="data.levels"
                                @saved="fetchDetails"
                            />
                        </div>
                        <!-- Level only icon -->
                        <div
                            class="flex min-w-[100px] items-center justify-center border-l border-border/50 pl-6"
                        >
                            <img
                                v-if="currentLevelIconUrl"
                                :src="currentLevelIconUrl"
                                class="h-20 w-20 object-contain drop-shadow-xl xl:h-24 xl:w-24"
                                :alt="data.gratitude.level"
                            />
                            <span
                                v-else
                                class="max-w-32 text-center text-xl leading-tight font-bold text-foreground"
                                >{{ data.gratitude.level }}</span
                            >
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Dashboard Row 1b: Level Status Card -->
            <Card
                v-if="data.gratitude"
                class="mb-8 overflow-hidden border-border shadow-sm"
            >
                <!-- Header / toggle -->
                <div
                    class="flex cursor-pointer items-center justify-between border-b border-transparent px-6 py-4 transition-colors hover:bg-muted/30"
                    :class="{ 'border-border': isLevelHistoryOpen }"
                    @click="isLevelHistoryOpen = !isLevelHistoryOpen"
                >
                    <div class="flex min-w-0 flex-1 items-center gap-4">
                        <!-- Level icon / badge -->
                        <div class="shrink-0">
                            <img
                                v-if="currentLevelIconUrl"
                                :src="currentLevelIconUrl"
                                class="h-10 w-10 object-contain drop-shadow"
                                :alt="data.gratitude.level"
                            />
                            <span
                                v-else
                                class="inline-flex min-h-10 items-center rounded-md bg-muted px-2 text-xs font-bold text-foreground"
                                >{{ data.gratitude.level }}</span
                            >
                        </div>
                        <!-- Level name + window label -->
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="text-base font-bold text-foreground"
                                    >{{ data.gratitude.level }}</span
                                >
                                <span
                                    v-if="data.gratitude.statusChange"
                                    :class="[
                                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold tracking-wider uppercase',
                                        data.gratitude.statusChange ===
                                        'upgrade'
                                            ? 'bg-green-100 text-green-700 dark:bg-green-950/40 dark:text-green-400'
                                            : data.gratitude.statusChange ===
                                                'downgrade'
                                              ? 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-400'
                                              : 'bg-muted text-muted-foreground',
                                    ]"
                                >
                                    <component
                                        :is="
                                            changeTypeIcon(
                                                data.gratitude.statusChange,
                                            )
                                        "
                                        class="h-3 w-3"
                                    />
                                    {{ data.gratitude.statusChange }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ data.interval_years }}-year window:
                                {{ data.interval_start }} →
                                {{ data.interval_end }}
                            </p>
                        </div>
                        <!-- Progress bar + points -->
                        <div
                            class="hidden max-w-xs min-w-[120px] flex-1 px-4 sm:block"
                        >
                            <div
                                class="mb-1 flex items-center justify-between text-xs text-muted-foreground"
                            >
                                <span
                                    >{{
                                        formatNumber(data.rolling_tier_points)
                                    }}
                                    pts</span
                                >
                                <span v-if="data.next_level"
                                    >{{
                                        formatNumber(
                                            data.rolling_tier_points +
                                                data.points_to_next_level,
                                        )
                                    }}
                                    for {{ data.next_level }}</span
                                >
                                <span
                                    v-else
                                    class="font-semibold text-green-600 dark:text-green-400"
                                    >Top tier</span
                                >
                            </div>
                            <div
                                class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    class="h-full rounded-full bg-primary transition-all duration-500"
                                    :style="{ width: levelProgressPct + '%' }"
                                />
                            </div>
                            <p
                                v-if="data.next_level"
                                class="mt-1 text-[10px] text-muted-foreground"
                            >
                                {{ formatNumber(data.points_to_next_level) }}
                                pts to {{ data.next_level }}
                            </p>
                        </div>
                    </div>
                    <ChevronDown
                        class="ml-4 h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200"
                        :class="{ 'rotate-180': isLevelHistoryOpen }"
                    />
                </div>

                <!-- Level History (collapsible) -->
                <div
                    v-show="isLevelHistoryOpen"
                    class="border-t border-border bg-card"
                >
                    <div class="p-4">
                        <p
                            class="mb-3 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Level History
                        </p>
                        <div
                            v-if="
                                data.gratitude.levelHistory &&
                                data.gratitude.levelHistory.length
                            "
                            class="space-y-2"
                        >
                            <div
                                v-for="(entry, i) in [
                                    ...data.gratitude.levelHistory,
                                ].reverse()"
                                :key="i"
                                class="flex items-start gap-3 rounded-md border border-border bg-muted/10 px-4 py-3"
                            >
                                <component
                                    :is="changeTypeIcon(entry.changeType)"
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                    :class="changeTypeClass(entry.changeType)"
                                />
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="text-sm font-semibold text-foreground"
                                        >
                                            {{ entry.fromLevel }}
                                            <span
                                                class="mx-1 font-normal text-muted-foreground"
                                                >→</span
                                            >
                                            {{ entry.toLevel }}
                                        </span>
                                        <span
                                            :class="[
                                                'inline-flex items-center rounded px-1.5 py-0.5 text-[9px] font-bold tracking-wider uppercase',
                                                entry.changeType === 'upgrade'
                                                    ? 'bg-green-100 text-green-700 dark:bg-green-950/40 dark:text-green-400'
                                                    : entry.changeType ===
                                                        'downgrade'
                                                      ? 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-400'
                                                      : 'bg-muted text-muted-foreground',
                                            ]"
                                        >
                                            {{ entry.changeType }}
                                        </span>
                                    </div>
                                    <p
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{ levelHistoryDescription(entry) }}
                                    </p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p
                                        class="text-xs font-medium text-foreground"
                                    >
                                        {{ formatDate(entry.date) }}
                                    </p>
                                    <p
                                        class="text-[10px] text-muted-foreground"
                                    >
                                        {{ formatNumber(entry.earnedPoints) }}
                                        pts
                                    </p>
                                </div>
                            </div>
                        </div>
                        <p
                            v-else
                            class="rounded-md border border-dashed border-border py-4 text-center text-sm text-muted-foreground"
                        >
                            No level changes recorded yet.
                        </p>
                    </div>
                </div>
            </Card>

            <!-- Dashboard Row 2: Equation Summary -->
            <Card class="mb-8 overflow-hidden border-border shadow-sm">
                <div
                    class="flex w-full flex-col divide-y divide-border lg:flex-row lg:items-stretch lg:divide-x lg:divide-y-0"
                >
                    <div
                        class="flex flex-1 flex-col justify-center bg-card p-4 transition-colors hover:bg-muted/30"
                    >
                        <span
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            ><Zap class="h-3.5 w-3.5 text-blue-500" /> Tier
                            Points</span
                        >
                        <span class="text-2xl font-bold text-foreground">{{
                            formatNumber(tierPointsSum)
                        }}</span>
                    </div>
                    <div
                        class="flex w-full items-center justify-center bg-muted/50 py-2 text-xl font-light text-muted-foreground lg:w-16 lg:bg-transparent lg:py-0"
                    >
                        +
                    </div>
                    <div
                        class="flex flex-1 flex-col justify-center bg-card p-4 transition-colors hover:bg-muted/30"
                    >
                        <span
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            ><Gift class="h-3.5 w-3.5 text-green-500" /> Bonus
                            Points</span
                        >
                        <span class="text-2xl font-bold text-foreground">{{
                            formatNumber(bonusPointsSum)
                        }}</span>
                    </div>
                    <div
                        class="flex w-full items-center justify-center bg-muted/50 py-2 text-xl font-light text-muted-foreground lg:w-16 lg:bg-transparent lg:py-0"
                    >
                        -
                    </div>
                    <div
                        class="flex flex-1 flex-col justify-center bg-card p-4 transition-colors hover:bg-muted/30"
                    >
                        <span
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            ><History class="h-3.5 w-3.5 text-amber-500" />
                            Redeemed</span
                        >
                        <span class="text-2xl font-bold text-foreground">{{
                            formatNumber(redemptionsSum)
                        }}</span>
                    </div>
                    <div
                        class="flex w-full items-center justify-center bg-muted/50 py-2 text-xl font-light text-muted-foreground lg:w-16 lg:bg-transparent lg:py-0"
                    >
                        -
                    </div>
                    <div
                        class="flex flex-1 flex-col justify-center bg-card p-4 transition-colors hover:bg-muted/30"
                    >
                        <span
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            ><ShieldAlert
                                class="h-3.5 w-3.5 text-destructive"
                            />
                            Cancelled</span
                        >
                        <span class="text-2xl font-bold text-foreground">{{
                            formatNumber(cancellationsSum)
                        }}</span>
                    </div>
                    <div
                        class="flex w-full items-center justify-center bg-muted/50 py-2 text-xl font-light text-muted-foreground lg:w-16 lg:bg-transparent lg:py-0"
                    >
                        -
                    </div>
                    <div
                        class="flex flex-1 flex-col justify-center bg-card p-4 transition-colors hover:bg-muted/30"
                    >
                        <span
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                            ><Clock class="h-3.5 w-3.5 text-orange-500" />
                            Expired</span
                        >
                        <span class="text-2xl font-bold text-foreground">{{
                            formatNumber(expiredPointsSum)
                        }}</span>
                    </div>
                    <div
                        class="flex w-full items-center justify-center bg-muted/50 py-2 text-xl font-light text-muted-foreground lg:w-16 lg:bg-transparent lg:py-0"
                    >
                        =
                    </div>
                    <div
                        class="flex flex-1 flex-col justify-center bg-blue-50/50 p-4 dark:bg-blue-950/20"
                    >
                        <span
                            class="mb-1 text-xs font-bold tracking-wider text-blue-600 uppercase dark:text-blue-400"
                            >Remaining Points</span
                        >
                        <span
                            class="text-2xl font-bold text-blue-700 dark:text-blue-300"
                            >{{ formatNumber(totalPoints) }}</span
                        >
                    </div>
                    <div
                        class="flex flex-1 flex-col justify-center bg-green-50/50 p-4 shadow-[inset_4px_0_0_0_rgba(34,197,94,0.2)] dark:bg-green-950/20"
                    >
                        <span
                            class="mb-1 text-xs font-bold tracking-wider text-green-600 uppercase dark:text-green-400"
                            >Usable Points</span
                        >
                        <span
                            class="text-2xl font-bold text-green-700 dark:text-green-300"
                            >{{ formatNumber(usablePoints) }}</span
                        >
                        <span
                            class="mt-0.5 text-xs font-medium text-green-600/70 dark:text-green-400/70"
                            >(${{
                                (
                                    usablePoints /
                                    (data.points_per_dollar || 35)
                                ).toFixed(2)
                            }})</span
                        >
                    </div>
                </div>
            </Card>

            <!-- Collapsible Sections -->
            <div class="space-y-4 pt-4">
                <!-- Level Benefits Card -->
                <Card
                    class="overflow-hidden border-border shadow-sm transition-all duration-300"
                >
                    <div
                        class="flex cursor-pointer items-center justify-between border-b border-transparent bg-card px-6 py-4 transition-colors hover:bg-muted/30"
                        :class="{ 'border-border': isBenefitsOpen }"
                        @click="isBenefitsOpen = !isBenefitsOpen"
                    >
                        <div class="flex items-center gap-3">
                            <div class="rounded-md bg-primary/10 p-2">
                                <Award class="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <h2
                                    class="text-base font-semibold tracking-tight text-foreground"
                                >
                                    My Tiers Benefits
                                </h2>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    View active benefits for your current
                                    program level
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <ChevronDown
                                class="h-5 w-5 text-muted-foreground transition-transform duration-200"
                                :class="{ 'rotate-180': isBenefitsOpen }"
                            />
                        </div>
                    </div>
                    <div
                        v-show="isBenefitsOpen"
                        class="border-t border-border bg-card p-0"
                    >
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-border">
                                <thead class="bg-muted/30">
                                    <tr>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Benefit Name
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Value
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Description
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border bg-card">
                                    <tr
                                        v-for="benefit in data.level_benefits"
                                        :key="benefit.id"
                                        class="transition-colors hover:bg-muted/30"
                                    >
                                        <td
                                            class="px-6 py-4 text-sm font-semibold whitespace-nowrap text-foreground"
                                        >
                                            {{ benefit.name }}
                                        </td>
                                        <td
                                            class="px-6 py-4 text-sm font-medium whitespace-nowrap"
                                        >
                                            <span
                                                class="inline-flex items-center rounded-full border border-primary/20 bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary"
                                            >
                                                {{
                                                    benefit.value || 'Included'
                                                }}
                                            </span>
                                        </td>
                                        <td
                                            class="px-6 py-4 text-sm text-foreground/80"
                                        >
                                            {{
                                                benefit.level_description ||
                                                benefit.benefit_description
                                            }}
                                        </td>
                                    </tr>
                                    <tr
                                        v-if="
                                            !data.level_benefits ||
                                            data.level_benefits.length === 0
                                        "
                                    >
                                        <td
                                            colspan="3"
                                            class="px-6 py-8 text-center text-sm text-muted-foreground"
                                        >
                                            No benefits mapped to this tier.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </Card>

                <!-- Full Points History Card -->
                <Card
                    class="overflow-hidden border-border shadow-sm transition-all duration-300"
                >
                    <div
                        class="flex cursor-pointer items-center justify-between border-b border-transparent bg-card px-6 py-4 transition-colors hover:bg-muted/30"
                        :class="{ 'border-border': isPointsHistoryOpen }"
                        @click="isPointsHistoryOpen = !isPointsHistoryOpen"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="rounded-md bg-slate-500/10 p-2 dark:bg-slate-500/20"
                            >
                                <History
                                    class="h-5 w-5 text-slate-600 dark:text-slate-300"
                                />
                            </div>
                            <div>
                                <h2
                                    class="text-base font-semibold tracking-tight text-foreground"
                                >
                                    Points History
                                </h2>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    Earned, bonus, redeemed, cancelled, and
                                    expired events
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="flex items-center gap-2" @click.stop>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="h-8 px-3 text-xs"
                                    @click="exportPointsHistoryPdf"
                                >
                                    <FileText class="h-3.5 w-3.5" />
                                    PDF
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="h-8 px-3 text-xs"
                                    @click="printPointsHistory"
                                >
                                    <Printer class="h-3.5 w-3.5" />
                                    Print
                                </Button>
                            </div>
                            <ChevronDown
                                class="h-5 w-5 text-muted-foreground transition-transform duration-200"
                                :class="{ 'rotate-180': isPointsHistoryOpen }"
                            />
                        </div>
                    </div>
                    <div
                        v-show="isPointsHistoryOpen"
                        class="border-t border-border bg-card p-0"
                    >
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-border">
                                <thead class="bg-muted/30">
                                    <tr>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Date
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Type
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Source
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Description
                                        </th>
                                        <th
                                            class="px-6 py-3 text-right text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Points
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border bg-card">
                                    <template
                                        v-for="entry in data.points_history"
                                        :key="`${entry.type}-${entry.source_type}-${entry.source_id}-${entry.date}-${entry.points}`"
                                    >
                                        <!-- Level change row -->
                                        <tr
                                            v-if="
                                                entry.source_type ===
                                                'LevelHistory'
                                            "
                                            :class="[
                                                'border-l-4 transition-colors',
                                                entry.change_type === 'upgrade'
                                                    ? 'border-l-green-500 bg-green-50/60 dark:bg-green-950/20'
                                                    : entry.change_type ===
                                                        'downgrade'
                                                      ? 'border-l-red-500 bg-red-50/60 dark:bg-red-950/20'
                                                      : 'border-l-blue-400 bg-blue-50/40 dark:bg-blue-950/10',
                                            ]"
                                        >
                                            <td
                                                class="px-6 py-3 text-sm font-medium whitespace-nowrap text-foreground"
                                            >
                                                {{ formatDate(entry.date) }}
                                            </td>
                                            <td
                                                class="px-6 py-3 text-sm whitespace-nowrap"
                                            >
                                                <span
                                                    class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-bold tracking-wider uppercase"
                                                    :class="
                                                        entry.change_type ===
                                                        'upgrade'
                                                            ? 'bg-green-100 text-green-700 dark:bg-green-950/40 dark:text-green-400'
                                                            : entry.change_type ===
                                                                'downgrade'
                                                              ? 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-400'
                                                              : 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400'
                                                    "
                                                >
                                                    <component
                                                        :is="
                                                            entry.change_type ===
                                                            'upgrade'
                                                                ? TrendingUp
                                                                : entry.change_type ===
                                                                    'downgrade'
                                                                  ? TrendingDown
                                                                  : Star
                                                        "
                                                        class="h-3 w-3"
                                                    />
                                                    Level
                                                    {{ entry.change_type }}
                                                </span>
                                            </td>
                                            <td
                                                class="px-6 py-3 text-sm whitespace-nowrap"
                                            >
                                                <span
                                                    class="flex items-center gap-1.5 font-semibold"
                                                    :class="
                                                        entry.change_type ===
                                                        'upgrade'
                                                            ? 'text-green-700 dark:text-green-400'
                                                            : entry.change_type ===
                                                                'downgrade'
                                                              ? 'text-red-700 dark:text-red-400'
                                                              : 'text-blue-700 dark:text-blue-400'
                                                    "
                                                >
                                                    {{ entry.level_from }}
                                                    <TrendingUp
                                                        v-if="
                                                            entry.change_type ===
                                                            'upgrade'
                                                        "
                                                        class="h-3.5 w-3.5"
                                                    />
                                                    <TrendingDown
                                                        v-else-if="
                                                            entry.change_type ===
                                                            'downgrade'
                                                        "
                                                        class="h-3.5 w-3.5"
                                                    />
                                                    <Minus
                                                        v-else
                                                        class="h-3.5 w-3.5"
                                                    />
                                                    {{ entry.level_to }}
                                                </span>
                                            </td>
                                            <td
                                                class="px-6 py-3 text-sm text-foreground/70 italic"
                                            >
                                                {{
                                                    levelHistoryDescription(
                                                        entry,
                                                    )
                                                }}
                                            </td>
                                            <td
                                                class="px-6 py-3 text-right text-sm font-semibold whitespace-nowrap text-muted-foreground"
                                            >
                                                {{
                                                    formatNumber(
                                                        entry.earned_points_at_change,
                                                    )
                                                }}
                                                pts
                                            </td>
                                        </tr>
                                        <!-- Normal point event row -->
                                        <tr
                                            v-else
                                            class="transition-colors hover:bg-muted/30"
                                        >
                                            <td
                                                class="px-6 py-4 text-sm font-medium whitespace-nowrap text-foreground"
                                            >
                                                {{ formatDate(entry.date) }}
                                            </td>
                                            <td
                                                class="px-6 py-4 text-sm whitespace-nowrap"
                                            >
                                                <span
                                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-bold tracking-wider uppercase"
                                                    :class="
                                                        entry.points >= 0
                                                            ? 'bg-green-100 text-green-700 dark:bg-green-950/40 dark:text-green-400'
                                                            : 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-400'
                                                    "
                                                >
                                                    {{ entry.type }}
                                                </span>
                                            </td>
                                            <td
                                                class="px-6 py-4 text-sm whitespace-nowrap text-muted-foreground"
                                            >
                                                {{ entry.source_type }} #{{
                                                    entry.source_id
                                                }}
                                            </td>
                                            <td
                                                class="px-6 py-4 text-sm text-foreground/80"
                                            >
                                                {{ entry.description }}
                                            </td>
                                            <td
                                                class="px-6 py-4 text-right text-sm font-bold whitespace-nowrap"
                                                :class="
                                                    entry.points >= 0
                                                        ? 'text-green-600 dark:text-green-400'
                                                        : 'text-red-600 dark:text-red-400'
                                                "
                                            >
                                                {{ entry.points >= 0 ? '+' : ''
                                                }}{{
                                                    formatNumber(entry.points)
                                                }}
                                            </td>
                                        </tr>
                                    </template>
                                    <tr
                                        v-if="
                                            !data.points_history ||
                                            data.points_history.length === 0
                                        "
                                    >
                                        <td
                                            colspan="5"
                                            class="px-6 py-8 text-center text-sm text-muted-foreground"
                                        >
                                            No point history recorded.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </Card>

                <!-- Earned Benefits Card -->
                <Card
                    class="overflow-hidden border-border shadow-sm transition-all duration-300"
                >
                    <div
                        class="flex cursor-pointer items-center justify-between border-b border-transparent bg-card px-6 py-4 transition-colors hover:bg-muted/30"
                        :class="{ 'border-border': isEarnedBenefitsOpen }"
                        @click="isEarnedBenefitsOpen = !isEarnedBenefitsOpen"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="rounded-md bg-violet-500/10 p-2 dark:bg-violet-500/20"
                            >
                                <ScrollText
                                    class="h-5 w-5 text-violet-600 dark:text-violet-400"
                                />
                            </div>
                            <div>
                                <h2
                                    class="text-base font-semibold tracking-tight text-foreground"
                                >
                                    Earned Benefits
                                </h2>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    Benefits used or earned on this account
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div @click.stop>
                                <AddEarnedBenefit
                                    :gratitudeNumber="gratitudeNumber"
                                    :availableBenefits="data.available_benefits"
                                    @saved="fetchDetails"
                                />
                            </div>
                            <ChevronDown
                                class="h-5 w-5 text-muted-foreground transition-transform duration-200"
                                :class="{ 'rotate-180': isEarnedBenefitsOpen }"
                            />
                        </div>
                    </div>
                    <div
                        v-show="isEarnedBenefitsOpen"
                        class="border-t border-border bg-card p-0"
                    >
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-border">
                                <thead class="bg-muted/30">
                                    <tr>
                                        <th
                                            class="px-5 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Date
                                        </th>
                                        <th
                                            class="px-5 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Benefit
                                        </th>
                                        <th
                                            class="px-5 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Value
                                        </th>
                                        <th
                                            class="px-5 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Journey ID
                                        </th>
                                        <th
                                            class="px-5 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Project
                                        </th>
                                        <th
                                            class="px-5 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Description
                                        </th>
                                        <th
                                            class="px-5 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Status
                                        </th>
                                        <th
                                            class="px-5 py-3 text-right text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border bg-card">
                                    <tr
                                        v-for="eb in data.earned_benefits"
                                        :key="eb.id"
                                        class="transition-colors hover:bg-muted/30"
                                    >
                                        <td
                                            class="px-5 py-3.5 text-sm font-medium whitespace-nowrap text-foreground"
                                        >
                                            {{ formatDate(eb.date) }}
                                        </td>
                                        <td
                                            class="px-5 py-3.5 whitespace-nowrap"
                                        >
                                            <p
                                                class="text-sm font-semibold text-foreground"
                                            >
                                                {{ eb.benefit_name }}
                                            </p>
                                            <p
                                                v-if="eb.benefit_key"
                                                class="mt-0.5 font-mono text-[10px] text-muted-foreground"
                                            >
                                                {{ eb.benefit_key }}
                                            </p>
                                        </td>
                                        <td
                                            class="px-5 py-3.5 whitespace-nowrap"
                                        >
                                            <span
                                                v-if="eb.benefit_value"
                                                class="inline-flex items-center gap-1 rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-bold tracking-wider text-violet-700 uppercase dark:bg-violet-950/40 dark:text-violet-400"
                                            >
                                                {{ eb.benefit_value }}
                                                <span
                                                    v-if="eb.value_type"
                                                    class="opacity-70"
                                                    >· {{ eb.value_type }}</span
                                                >
                                            </span>
                                            <span
                                                v-else
                                                class="text-xs text-muted-foreground"
                                                >—</span
                                            >
                                        </td>
                                        <td
                                            class="px-5 py-3.5 text-sm whitespace-nowrap text-muted-foreground"
                                        >
                                            <span v-if="eb.journey_id">{{
                                                eb.journey_id
                                            }}</span>
                                            <span v-else class="text-xs"
                                                >—</span
                                            >
                                        </td>
                                        <td
                                            class="max-w-[140px] truncate px-5 py-3.5 text-sm whitespace-nowrap text-muted-foreground"
                                        >
                                            <template v-if="eb.project_data">
                                                <span
                                                    class="font-medium text-foreground/80"
                                                    >{{
                                                        eb.project_data
                                                            .projectNumber ||
                                                        eb.project_data
                                                            .number ||
                                                        ''
                                                    }}</span
                                                >
                                                <span
                                                    v-if="
                                                        eb.project_data.name ||
                                                        eb.project_data.title
                                                    "
                                                    class="ml-1 text-xs"
                                                    >{{
                                                        eb.project_data.name ||
                                                        eb.project_data.title
                                                    }}</span
                                                >
                                            </template>
                                            <span v-else class="text-xs"
                                                >—</span
                                            >
                                        </td>
                                        <td
                                            class="max-w-[200px] px-5 py-3.5 text-sm text-foreground/80"
                                        >
                                            <p class="truncate">
                                                {{ eb.description }}
                                            </p>
                                            <p
                                                v-if="eb.notes"
                                                class="mt-0.5 truncate text-xs text-muted-foreground italic"
                                            >
                                                {{ eb.notes }}
                                            </p>
                                        </td>
                                        <td
                                            class="px-5 py-3.5 whitespace-nowrap"
                                        >
                                            <span
                                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold tracking-wider uppercase"
                                                :class="{
                                                    'bg-green-100 text-green-700 dark:bg-green-950/40 dark:text-green-400':
                                                        eb.status === 'active',
                                                    'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400':
                                                        eb.status === 'used',
                                                    'bg-orange-100 text-orange-700 dark:bg-orange-950/40 dark:text-orange-400':
                                                        eb.status === 'expired',
                                                    'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-400':
                                                        eb.status ===
                                                        'cancelled',
                                                    'bg-muted text-muted-foreground':
                                                        ![
                                                            'active',
                                                            'used',
                                                            'expired',
                                                            'cancelled',
                                                        ].includes(eb.status),
                                                }"
                                            >
                                                {{ eb.status }}
                                            </span>
                                        </td>
                                        <td
                                            class="px-5 py-3.5 text-right whitespace-nowrap"
                                        >
                                            <div
                                                class="flex items-center justify-end gap-2"
                                            >
                                                <button
                                                    @click.stop="
                                                        viewActivityLog(eb)
                                                    "
                                                    class="inline-flex h-7 items-center gap-1 rounded border border-border/50 bg-muted/60 px-2 text-[10px] font-bold tracking-wider text-muted-foreground uppercase transition-colors hover:bg-muted hover:text-foreground"
                                                >
                                                    <ScrollText
                                                        class="h-3 w-3"
                                                    />
                                                    Log
                                                </button>
                                                <UpdateEarnedBenefit
                                                    :gratitudeNumber="
                                                        gratitudeNumber
                                                    "
                                                    :benefit="eb"
                                                    :availableBenefits="
                                                        data.available_benefits
                                                    "
                                                    @saved="fetchDetails"
                                                />
                                                <DeleteEarnedBenefit
                                                    :gratitudeNumber="
                                                        gratitudeNumber
                                                    "
                                                    :benefit="eb"
                                                    @saved="fetchDetails"
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                    <tr
                                        v-if="
                                            !data.earned_benefits ||
                                            data.earned_benefits.length === 0
                                        "
                                    >
                                        <td
                                            colspan="8"
                                            class="px-6 py-8 text-center text-sm text-muted-foreground"
                                        >
                                            No earned benefits recorded yet.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </Card>

                <!-- Activity Log Modal -->
                <div
                    v-if="isActivityLogOpen"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4 backdrop-blur-sm"
                >
                    <div
                        class="flex max-h-[80vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-border bg-card shadow-2xl"
                    >
                        <div
                            class="flex shrink-0 items-center justify-between border-b border-border bg-muted/80 px-6 py-4"
                        >
                            <h2
                                class="flex items-center gap-2 text-sm font-semibold tracking-wide text-foreground"
                            >
                                <ScrollText
                                    class="h-4 w-4 text-muted-foreground"
                                />
                                Activity Log — Benefit #{{
                                    activityLogBenefitId
                                }}
                            </h2>
                            <button
                                @click="isActivityLogOpen = false"
                                class="text-muted-foreground transition-colors hover:text-foreground"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-5 w-5"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                        clip-rule="evenodd"
                                    />
                                </svg>
                            </button>
                        </div>
                        <div class="flex-1 overflow-y-auto p-4">
                            <div
                                v-if="activityLogLoading"
                                class="py-8 text-center text-sm text-muted-foreground"
                            >
                                Loading…
                            </div>
                            <div
                                v-else-if="activityLog.length === 0"
                                class="py-8 text-center text-sm text-muted-foreground"
                            >
                                No activity recorded for this entry.
                            </div>
                            <div v-else class="space-y-3">
                                <div
                                    v-for="entry in activityLog"
                                    :key="entry.id"
                                    class="rounded-lg border border-border bg-muted/10 px-4 py-3 text-sm"
                                >
                                    <div
                                        class="mb-1.5 flex items-center justify-between gap-3"
                                    >
                                        <span
                                            class="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-bold tracking-wider uppercase"
                                            :class="{
                                                'bg-green-100 text-green-700 dark:bg-green-950/40 dark:text-green-400':
                                                    entry.event === 'created',
                                                'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400':
                                                    entry.event === 'updated',
                                                'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-400':
                                                    entry.event === 'deleted',
                                                'bg-muted text-muted-foreground':
                                                    ![
                                                        'created',
                                                        'updated',
                                                        'deleted',
                                                    ].includes(entry.event),
                                            }"
                                            >{{ entry.event }}</span
                                        >
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >{{ entry.created_at }}</span
                                        >
                                    </div>
                                    <p class="text-xs text-foreground/80">
                                        {{ entry.description }}
                                    </p>
                                    <div
                                        v-if="
                                            entry.properties?.attributes ||
                                            entry.properties?.old
                                        "
                                        class="mt-2 space-y-1"
                                    >
                                        <div
                                            v-if="
                                                entry.properties.old &&
                                                Object.keys(
                                                    entry.properties.old,
                                                ).length
                                            "
                                            class="rounded border border-red-200/50 bg-red-50/50 px-2 py-1.5 font-mono text-[10px] text-red-700 dark:border-red-800/30 dark:bg-red-950/20 dark:text-red-400"
                                        >
                                            <span
                                                class="text-[9px] font-bold tracking-wider uppercase not-italic"
                                                >Before: </span
                                            >{{
                                                JSON.stringify(
                                                    entry.properties.old,
                                                )
                                            }}
                                        </div>
                                        <div
                                            v-if="
                                                entry.properties.attributes &&
                                                Object.keys(
                                                    entry.properties.attributes,
                                                ).length
                                            "
                                            class="rounded border border-green-200/50 bg-green-50/50 px-2 py-1.5 font-mono text-[10px] text-green-700 dark:border-green-800/30 dark:bg-green-950/20 dark:text-green-400"
                                        >
                                            <span
                                                class="text-[9px] font-bold tracking-wider uppercase not-italic"
                                                >After: </span
                                            >{{
                                                JSON.stringify(
                                                    entry.properties.attributes,
                                                )
                                            }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div
                            class="flex shrink-0 justify-end border-t border-border px-6 py-3"
                        >
                            <button
                                @click="isActivityLogOpen = false"
                                class="h-8 rounded-md border border-border px-5 text-sm font-semibold transition-colors hover:bg-muted"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Tier Points Card -->
                <Card
                    class="overflow-hidden border-border shadow-sm transition-all duration-300"
                >
                    <div
                        class="flex cursor-pointer items-center justify-between border-b border-transparent bg-card px-6 py-4 transition-colors hover:bg-muted/30"
                        :class="{ 'border-border': isTierOpen }"
                        @click="isTierOpen = !isTierOpen"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="rounded-md bg-blue-500/10 p-2 dark:bg-blue-500/20"
                            >
                                <Zap
                                    class="h-5 w-5 text-blue-600 dark:text-blue-400"
                                />
                            </div>
                            <div>
                                <h2
                                    class="text-base font-semibold tracking-tight text-foreground"
                                >
                                    Tier Points
                                </h2>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    Base point earnings from interactions
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div @click.stop class="flex space-x-2">
                                <AddEarnedPoints
                                    :gratitudeNumber="gratitudeNumber"
                                    :expireDays="earnedExpireDays"
                                    :journeys="data.journeys"
                                    @saved="fetchDetails"
                                />
                            </div>
                            <ChevronDown
                                class="h-5 w-5 text-muted-foreground transition-transform duration-200"
                                :class="{ 'rotate-180': isTierOpen }"
                            />
                        </div>
                    </div>
                    <div
                        v-show="isTierOpen"
                        class="border-t border-border bg-card p-0"
                    >
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-border">
                                <thead class="bg-muted/30">
                                    <tr>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Effective Date
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Project
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Points
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Redeemed
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Cancelled
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Remaining
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-orange-600/80 uppercase dark:text-orange-400/80"
                                        >
                                            Expired
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Expires On
                                        </th>
                                        <th
                                            class="w-1/3 px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Description
                                        </th>
                                        <th
                                            class="px-6 py-3 text-right text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border bg-card">
                                    <template
                                        v-for="item in combinedTierPoints"
                                        :key="item.rowType + '-' + item.id"
                                    >
                                        <tr
                                            class="transition-colors hover:bg-muted/30"
                                            :class="{
                                                'border-l-4 border-l-rose-500 bg-rose-50/90 dark:bg-rose-950/30':
                                                    item.hasCancellation ||
                                                    item.rowType === 'cancel',
                                            }"
                                        >
                                            <td
                                                class="px-6 py-4 text-sm font-medium whitespace-nowrap text-foreground"
                                            >
                                                <span>
                                                    {{
                                                        formatDate(
                                                            item.displayDate,
                                                            '',
                                                        )
                                                    }}
                                                </span>
                                            </td>
                                            <td
                                                class="px-6 py-4 whitespace-nowrap"
                                            >
                                                <div
                                                    class="text-sm font-bold text-foreground/90"
                                                >
                                                    <span
                                                        v-if="
                                                            item.rowType ===
                                                            'cancel'
                                                        "
                                                        class="mr-1.5 inline-flex items-center rounded border border-destructive/30 bg-destructive/10 px-1.5 py-0.5 text-[9px] font-bold tracking-wider text-destructive uppercase"
                                                        >Cancellation</span
                                                    >
                                                    {{ item.displayProject }}
                                                </div>
                                                <div
                                                    v-if="item.displaySubtitle"
                                                    class="mt-0.5 text-xs text-muted-foreground"
                                                >
                                                    {{ item.displaySubtitle }}
                                                </div>
                                            </td>
                                            <td
                                                class="px-6 py-4 text-sm font-bold whitespace-nowrap"
                                                :class="
                                                    item.hasCancellation ||
                                                    item.rowType === 'cancel'
                                                        ? 'text-destructive'
                                                        : 'text-foreground'
                                                "
                                            >
                                                <template
                                                    v-if="
                                                        item.rowType ===
                                                        'cancel'
                                                    "
                                                    >-{{
                                                        formatNumber(
                                                            item.displayPoints,
                                                        )
                                                    }}</template
                                                >
                                                <template v-else>{{
                                                    formatNumber(
                                                        item.displayPoints,
                                                    )
                                                }}</template>
                                            </td>
                                            <td
                                                class="px-6 py-4 text-sm font-medium whitespace-nowrap text-amber-600 dark:text-amber-400"
                                            >
                                                <template
                                                    v-if="
                                                        item.rowType !==
                                                        'cancel'
                                                    "
                                                    >{{
                                                        formatNumber(
                                                            item.redeemed_points ||
                                                                0,
                                                        )
                                                    }}</template
                                                >
                                                <span
                                                    v-else
                                                    class="text-muted-foreground"
                                                    >—</span
                                                >
                                            </td>
                                            <td
                                                class="px-6 py-4 text-sm font-medium whitespace-nowrap text-destructive"
                                            >
                                                <template
                                                    v-if="
                                                        item.rowType !==
                                                        'cancel'
                                                    "
                                                    >{{
                                                        formatNumber(
                                                            pointCancelled(
                                                                item,
                                                            ),
                                                        )
                                                    }}</template
                                                >
                                                <span
                                                    v-else
                                                    class="text-muted-foreground"
                                                    >—</span
                                                >
                                            </td>
                                            <td
                                                class="px-6 py-4 text-sm font-bold whitespace-nowrap"
                                                :class="
                                                    item.hasCancellation
                                                        ? 'text-destructive'
                                                        : 'text-green-600 dark:text-green-400'
                                                "
                                            >
                                                <template
                                                    v-if="
                                                        item.rowType !==
                                                        'cancel'
                                                    "
                                                    >{{
                                                        formatNumber(
                                                            pointRemaining(
                                                                item,
                                                            ),
                                                        )
                                                    }}</template
                                                >
                                                <span
                                                    v-else
                                                    class="text-muted-foreground"
                                                    >—</span
                                                >
                                            </td>
                                            <td
                                                class="px-6 py-4 text-sm font-bold whitespace-nowrap"
                                            >
                                                <template
                                                    v-if="
                                                        item.rowType ===
                                                            'earned' &&
                                                        item.isExpired
                                                    "
                                                >
                                                    <span
                                                        class="inline-flex items-center gap-1 rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold tracking-wider text-orange-700 uppercase dark:bg-orange-950/40 dark:text-orange-400"
                                                    >
                                                        <Clock
                                                            class="h-3 w-3"
                                                        />{{
                                                            formatNumber(
                                                                pointRemaining(
                                                                    item,
                                                                ),
                                                            )
                                                        }}
                                                    </span>
                                                </template>
                                                <span
                                                    v-else
                                                    class="text-xs text-muted-foreground"
                                                    >—</span
                                                >
                                            </td>
                                            <td
                                                class="px-6 py-4 text-sm whitespace-nowrap text-foreground/80"
                                            >
                                                <span
                                                    v-if="
                                                        item.displayExpiresOn ||
                                                        item.expires_at
                                                    "
                                                >
                                                    {{
                                                        new Date(
                                                            item.displayExpiresOn ||
                                                                item.expires_at,
                                                        )
                                                            .toISOString()
                                                            .split('T')[0]
                                                    }}
                                                    <span
                                                        v-if="
                                                            item.expires_at_manual
                                                        "
                                                        class="ml-1 inline-flex items-center gap-0.5 rounded border border-violet-300/50 bg-violet-100 px-1.5 py-0.5 text-[9px] font-bold tracking-wider text-violet-700 uppercase dark:bg-violet-950/40 dark:text-violet-400"
                                                        >Manual</span
                                                    >
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-sm">
                                                <span
                                                    class="text-foreground/80"
                                                    >{{
                                                        item.displayDescription
                                                    }}</span
                                                >
                                                <!-- Inline cancellation block for rows with cancel_id -->
                                                <div
                                                    v-if="
                                                        item.cancellationData &&
                                                        item.cancellationsList
                                                            .length === 0
                                                    "
                                                    class="mt-1.5 flex items-start gap-1.5 rounded border border-red-200/60 bg-red-50 px-2 py-1 dark:border-red-800/40 dark:bg-red-950/30"
                                                >
                                                    <span
                                                        class="mt-0.5 inline-flex shrink-0 items-center rounded bg-destructive px-1.5 py-0.5 text-[9px] font-bold tracking-wider text-white uppercase"
                                                        >Cancelled</span
                                                    >
                                                    <div class="min-w-0">
                                                        <p
                                                            class="text-xs font-semibold text-destructive"
                                                        >
                                                            {{
                                                                item
                                                                    .cancellationData
                                                                    ?.description
                                                            }}
                                                        </p>
                                                        <p
                                                            class="mt-0.5 text-[10px] text-red-500/70 dark:text-red-400/60"
                                                        >
                                                            {{
                                                                formatDate(
                                                                    item
                                                                        .cancellationData
                                                                        ?.date,
                                                                    '',
                                                                )
                                                            }}
                                                            <span
                                                                v-if="
                                                                    item
                                                                        .cancellationData
                                                                        ?.points
                                                                "
                                                                class="ml-1 font-bold"
                                                                >· -{{
                                                                    formatNumber(
                                                                        item
                                                                            .cancellationData
                                                                            .points,
                                                                    )
                                                                }}
                                                                pts</span
                                                            >
                                                        </p>
                                                    </div>
                                                </div>
                                                <div
                                                    v-for="cancel in item.cancellationsList"
                                                    :key="cancel.id"
                                                    class="mt-1.5 flex items-start gap-1.5 rounded border border-red-200/60 bg-red-50 px-2 py-1 dark:border-red-800/40 dark:bg-red-950/30"
                                                >
                                                    <span
                                                        class="mt-0.5 inline-flex shrink-0 items-center rounded bg-destructive px-1.5 py-0.5 text-[9px] font-bold tracking-wider text-white uppercase"
                                                        >Cancelled</span
                                                    >
                                                    <div class="min-w-0">
                                                        <p
                                                            class="text-xs font-semibold text-destructive"
                                                        >
                                                            {{
                                                                cancel.description
                                                            }}
                                                        </p>
                                                        <p
                                                            class="mt-0.5 text-[10px] text-red-500/70 dark:text-red-400/60"
                                                        >
                                                            {{
                                                                formatDate(
                                                                    cancel.date,
                                                                    '',
                                                                )
                                                            }}
                                                            <span
                                                                class="ml-1 font-bold"
                                                                >· -{{
                                                                    formatNumber(
                                                                        cancel.points,
                                                                    )
                                                                }}
                                                                pts</span
                                                            >
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>

                                            <td
                                                class="px-6 py-4 text-right text-sm whitespace-nowrap"
                                            >
                                                <div
                                                    class="flex items-center justify-end gap-2"
                                                >
                                                    <ViewEntryDetails
                                                        :item="item"
                                                    />
                                                    <!-- If it's pure earned without cancellation, show actions -->
                                                    <template
                                                        v-if="
                                                            item.rowType ===
                                                            'earned'
                                                        "
                                                    >
                                                        <span
                                                            v-if="
                                                                item.isExpired
                                                            "
                                                            class="inline-flex items-center gap-1 rounded-full border border-orange-300/50 bg-orange-100 px-2 py-0.5 text-[10px] font-bold tracking-wider text-orange-700 uppercase dark:bg-orange-950/40 dark:text-orange-400"
                                                        >
                                                            <Clock
                                                                class="h-3 w-3"
                                                            />
                                                            Expired
                                                        </span>
                                                        <UpdateEarnedPoints
                                                            :gratitudeNumber="
                                                                gratitudeNumber
                                                            "
                                                            :point="item"
                                                            :expireDays="
                                                                earnedExpireDays
                                                            "
                                                            @saved="
                                                                fetchDetails
                                                            "
                                                        />
                                                        <template
                                                            v-if="
                                                                !item.isExpired &&
                                                                pointRemaining(
                                                                    item,
                                                                ) > 0
                                                            "
                                                        >
                                                            <CancelPointEntry
                                                                :gratitudeNumber="
                                                                    gratitudeNumber
                                                                "
                                                                :point="item"
                                                                pointType="earned"
                                                                @saved="
                                                                    fetchDetails
                                                                "
                                                            />
                                                            <DeletePointEntry
                                                                :gratitudeNumber="
                                                                    gratitudeNumber
                                                                "
                                                                :point="item"
                                                                pointType="earned"
                                                                @saved="
                                                                    fetchDetails
                                                                "
                                                            />
                                                        </template>
                                                    </template>
                                                    <!-- If it is purely a cancellation row or completed_cancel, lock actions -->
                                                    <template
                                                        v-else-if="
                                                            item.rowType ===
                                                            'cancel'
                                                        "
                                                    >
                                                        <DeleteCancellation
                                                            :gratitudeNumber="
                                                                gratitudeNumber
                                                            "
                                                            :cancellation="item"
                                                            @saved="
                                                                fetchDetails
                                                            "
                                                        />
                                                    </template>
                                                </div>
                                            </td>
                                        </tr>
                                        <!-- Nested Redemption History Row -->
                                        <tr
                                            v-if="
                                                item.redemptionsList &&
                                                item.redemptionsList.length > 0
                                            "
                                            class="border-t-0 border-b border-border bg-muted/10 shadow-inner"
                                        >
                                            <td colspan="10" class="px-8 py-3">
                                                <div
                                                    class="flex flex-col gap-1.5 rounded-r-md border-l-2 border-amber-500 bg-amber-50/50 px-4 py-2 pl-4 dark:bg-amber-950/20"
                                                >
                                                    <div
                                                        v-for="red in item.redemptionsList"
                                                        :key="red._key"
                                                        class="flex items-center gap-3 text-xs text-muted-foreground"
                                                    >
                                                        <History
                                                            class="h-3.5 w-3.5"
                                                        />
                                                        <span
                                                            class="font-bold text-amber-600 dark:text-amber-400"
                                                            >-{{
                                                                formatNumber(
                                                                    red.points,
                                                                )
                                                            }}
                                                            pts</span
                                                        >
                                                        <span
                                                            >Redeemed for:
                                                            <strong
                                                                class="mx-1 rounded border border-border bg-card px-2 py-0.5 text-[10px] tracking-widest text-foreground/90 uppercase shadow-sm"
                                                                >{{
                                                                    red.reason
                                                                }}</strong
                                                            ></span
                                                        >
                                                        <span v-if="red.date"
                                                            >on
                                                            {{ red.date }}</span
                                                        >
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr v-if="combinedTierPoints.length === 0">
                                        <td
                                            colspan="10"
                                            class="px-6 py-8 text-center text-sm text-muted-foreground"
                                        >
                                            No tier points recorded.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </Card>

                <!-- Bonus Points Card -->
                <Card
                    class="overflow-hidden border-border shadow-sm transition-all duration-300"
                >
                    <div
                        class="flex cursor-pointer items-center justify-between border-b border-transparent bg-card px-6 py-4 transition-colors hover:bg-muted/30"
                        :class="{ 'border-border': isBonusOpen }"
                        @click="isBonusOpen = !isBonusOpen"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="rounded-md bg-green-500/10 p-2 dark:bg-green-500/20"
                            >
                                <Gift
                                    class="h-5 w-5 text-green-600 dark:text-green-400"
                                />
                            </div>
                            <div>
                                <h2
                                    class="text-base font-semibold tracking-tight text-foreground"
                                >
                                    Bonus Points
                                </h2>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    Promotional or extra points awarded
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div @click.stop class="flex space-x-2">
                                <AddBonusPoints
                                    :gratitudeNumber="gratitudeNumber"
                                    :expireDays="bonusExpireDays"
                                    :guests="data.guests"
                                    :journeys="data.journeys"
                                    @saved="fetchDetails"
                                />
                            </div>
                            <ChevronDown
                                class="h-5 w-5 text-muted-foreground transition-transform duration-200"
                                :class="{ 'rotate-180': isBonusOpen }"
                            />
                        </div>
                    </div>
                    <div
                        v-show="isBonusOpen"
                        class="border-t border-border bg-card p-0"
                    >
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-border">
                                <thead class="bg-muted/30">
                                    <tr>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Effective Date
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Expires On
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Description
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Points
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Redeemed
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Cancelled
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Remaining
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-orange-600/80 uppercase dark:text-orange-400/80"
                                        >
                                            Expired
                                        </th>
                                        <th
                                            class="px-6 py-3 text-right text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border bg-card">
                                    <template
                                        v-for="point in combinedBonusPoints"
                                        :key="point.id"
                                    >
                                        <tr
                                            class="transition-colors hover:bg-muted/30"
                                            :class="{
                                                'border-l-4 border-l-rose-500 bg-rose-50/90 dark:bg-rose-950/30':
                                                    point.hasCancellation,
                                            }"
                                        >
                                            <td
                                                class="px-6 py-4 text-sm font-medium whitespace-nowrap text-foreground"
                                            >
                                                <span>
                                                    {{
                                                        formatDate(
                                                            point.displayDate ||
                                                                effectiveDateValue(
                                                                    point,
                                                                ),
                                                        )
                                                    }}
                                                </span>
                                            </td>
                                            <td
                                                class="px-6 py-4 text-sm whitespace-nowrap"
                                            >
                                                <template
                                                    v-if="point.expires_at"
                                                >
                                                    <span
                                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold tracking-wider uppercase"
                                                        :class="[
                                                            new Date(
                                                                point.expires_at,
                                                            ) < new Date()
                                                                ? 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-400'
                                                                : 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400',
                                                        ]"
                                                    >
                                                        {{
                                                            new Date(
                                                                point.expires_at,
                                                            )
                                                                .toISOString()
                                                                .split('T')[0]
                                                        }}
                                                    </span>
                                                    <span
                                                        v-if="
                                                            point.expires_at_manual
                                                        "
                                                        class="ml-1 inline-flex items-center gap-0.5 rounded border border-violet-300/50 bg-violet-100 px-1.5 py-0.5 text-[9px] font-bold tracking-wider text-violet-700 uppercase dark:bg-violet-950/40 dark:text-violet-400"
                                                        >Manual</span
                                                    >
                                                </template>
                                                <span
                                                    v-else
                                                    class="text-xs text-muted-foreground"
                                                    >No Expiry</span
                                                >
                                            </td>
                                            <td class="px-6 py-4 text-sm">
                                                <span
                                                    class="text-foreground/80"
                                                >
                                                    {{
                                                        point.description ||
                                                        point.category
                                                    }}
                                                </span>
                                                <!-- Inline cancellation block -->
                                                <div
                                                    v-if="
                                                        point.cancellationData &&
                                                        point.cancellationsList
                                                            .length === 0
                                                    "
                                                    class="mt-1.5 flex items-start gap-1.5 rounded border border-red-200/60 bg-red-50 px-2 py-1 dark:border-red-800/40 dark:bg-red-950/30"
                                                >
                                                    <span
                                                        class="mt-0.5 inline-flex shrink-0 items-center rounded bg-destructive px-1.5 py-0.5 text-[9px] font-bold tracking-wider text-white uppercase"
                                                        >Cancelled</span
                                                    >
                                                    <div class="min-w-0">
                                                        <p
                                                            class="text-xs font-semibold text-destructive"
                                                        >
                                                            {{
                                                                point
                                                                    .cancellationData
                                                                    ?.description
                                                            }}
                                                        </p>
                                                        <p
                                                            class="mt-0.5 text-[10px] text-red-500/70 dark:text-red-400/60"
                                                        >
                                                            {{
                                                                formatDate(
                                                                    point
                                                                        .cancellationData
                                                                        ?.date,
                                                                    '',
                                                                )
                                                            }}
                                                            <span
                                                                v-if="
                                                                    point
                                                                        .cancellationData
                                                                        ?.points
                                                                "
                                                                class="ml-1 font-bold"
                                                                >· -{{
                                                                    formatNumber(
                                                                        point
                                                                            .cancellationData
                                                                            .points,
                                                                    )
                                                                }}
                                                                pts</span
                                                            >
                                                        </p>
                                                    </div>
                                                </div>
                                                <div
                                                    v-for="cancel in point.cancellationsList"
                                                    :key="cancel.id"
                                                    class="mt-1.5 flex items-start gap-1.5 rounded border border-red-200/60 bg-red-50 px-2 py-1 dark:border-red-800/40 dark:bg-red-950/30"
                                                >
                                                    <span
                                                        class="mt-0.5 inline-flex shrink-0 items-center rounded bg-destructive px-1.5 py-0.5 text-[9px] font-bold tracking-wider text-white uppercase"
                                                        >Cancelled</span
                                                    >
                                                    <div class="min-w-0">
                                                        <p
                                                            class="text-xs font-semibold text-destructive"
                                                        >
                                                            {{
                                                                cancel.description
                                                            }}
                                                        </p>
                                                        <p
                                                            class="mt-0.5 text-[10px] text-red-500/70 dark:text-red-400/60"
                                                        >
                                                            {{
                                                                formatDate(
                                                                    cancel.date,
                                                                    '',
                                                                )
                                                            }}
                                                            <span
                                                                class="ml-1 font-bold"
                                                                >· -{{
                                                                    formatNumber(
                                                                        cancel.points,
                                                                    )
                                                                }}
                                                                pts</span
                                                            >
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td
                                                class="px-6 py-4 text-sm font-bold whitespace-nowrap"
                                                :class="
                                                    point.hasCancellation
                                                        ? 'text-destructive'
                                                        : 'text-foreground'
                                                "
                                            >
                                                {{ formatNumber(point.points) }}
                                            </td>
                                            <td
                                                class="px-6 py-4 text-sm font-medium whitespace-nowrap text-amber-600 dark:text-amber-400"
                                            >
                                                {{
                                                    formatNumber(
                                                        point.redeemed_points ||
                                                            0,
                                                    )
                                                }}
                                            </td>
                                            <td
                                                class="px-6 py-4 text-sm font-medium whitespace-nowrap text-destructive"
                                            >
                                                {{
                                                    formatNumber(
                                                        pointCancelled(point),
                                                    )
                                                }}
                                            </td>
                                            <td
                                                class="px-6 py-4 text-sm font-bold whitespace-nowrap"
                                                :class="
                                                    point.hasCancellation
                                                        ? 'text-destructive'
                                                        : 'text-green-600 dark:text-green-400'
                                                "
                                            >
                                                {{
                                                    formatNumber(
                                                        pointRemaining(point),
                                                    )
                                                }}
                                            </td>
                                            <td
                                                class="px-6 py-4 text-sm font-bold whitespace-nowrap"
                                            >
                                                <template
                                                    v-if="point.isExpired"
                                                >
                                                    <span
                                                        class="inline-flex items-center gap-1 rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold tracking-wider text-orange-700 uppercase dark:bg-orange-950/40 dark:text-orange-400"
                                                    >
                                                        <Clock
                                                            class="h-3 w-3"
                                                        />{{
                                                            formatNumber(
                                                                pointRemaining(
                                                                    point,
                                                                ),
                                                            )
                                                        }}
                                                    </span>
                                                </template>
                                                <span
                                                    v-else
                                                    class="text-xs text-muted-foreground"
                                                    >—</span
                                                >
                                            </td>
                                            <td
                                                class="px-6 py-4 text-right text-sm whitespace-nowrap"
                                            >
                                                <div
                                                    class="flex items-center justify-end gap-2"
                                                >
                                                    <ViewEntryDetails
                                                        :item="point"
                                                    />
                                                    <template
                                                        v-if="
                                                            point.rowType ===
                                                            'bonus'
                                                        "
                                                    >
                                                        <span
                                                            v-if="
                                                                point.isExpired
                                                            "
                                                            class="inline-flex items-center gap-1 rounded-full border border-orange-300/50 bg-orange-100 px-2 py-0.5 text-[10px] font-bold tracking-wider text-orange-700 uppercase dark:bg-orange-950/40 dark:text-orange-400"
                                                        >
                                                            <Clock
                                                                class="h-3 w-3"
                                                            />
                                                            Expired
                                                        </span>
                                                        <UpdateBonusPoints
                                                            :gratitudeNumber="
                                                                gratitudeNumber
                                                            "
                                                            :point="point"
                                                            :expireDays="
                                                                bonusExpireDays
                                                            "
                                                            @saved="
                                                                fetchDetails
                                                            "
                                                        />
                                                        <template
                                                            v-if="
                                                                !point.isExpired &&
                                                                pointRemaining(
                                                                    point,
                                                                ) > 0
                                                            "
                                                        >
                                                            <CancelPointEntry
                                                                :gratitudeNumber="
                                                                    gratitudeNumber
                                                                "
                                                                :point="point"
                                                                pointType="bonus"
                                                                @saved="
                                                                    fetchDetails
                                                                "
                                                            />
                                                            <DeletePointEntry
                                                                :gratitudeNumber="
                                                                    gratitudeNumber
                                                                "
                                                                :point="point"
                                                                pointType="bonus"
                                                                @saved="
                                                                    fetchDetails
                                                                "
                                                            />
                                                        </template>
                                                    </template>
                                                </div>
                                            </td>
                                        </tr>
                                        <!-- Nested Redemption History Row -->
                                        <tr
                                            v-if="
                                                point.redemptionsList &&
                                                point.redemptionsList.length > 0
                                            "
                                            class="border-t-0 border-b border-border bg-muted/10 shadow-inner"
                                        >
                                            <td colspan="9" class="px-8 py-3">
                                                <div
                                                    class="flex flex-col gap-1.5 rounded-r-md border-l-2 border-amber-500 bg-amber-50/50 px-4 py-2 pl-4 dark:bg-amber-950/20"
                                                >
                                                    <div
                                                        v-for="red in point.redemptionsList"
                                                        :key="red._key"
                                                        class="flex items-center gap-3 text-xs text-muted-foreground"
                                                    >
                                                        <History
                                                            class="h-3.5 w-3.5"
                                                        />
                                                        <span
                                                            class="font-bold text-amber-600 dark:text-amber-400"
                                                            >-{{
                                                                formatNumber(
                                                                    red.points,
                                                                )
                                                            }}
                                                            pts</span
                                                        >
                                                        <span
                                                            >Redeemed for:
                                                            <strong
                                                                class="mx-1 rounded border border-border bg-card px-2 py-0.5 text-[10px] tracking-widest text-foreground/90 uppercase shadow-sm"
                                                                >{{
                                                                    red.reason
                                                                }}</strong
                                                            ></span
                                                        >
                                                        <span v-if="red.date"
                                                            >on
                                                            {{ red.date }}</span
                                                        >
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr v-if="combinedBonusPoints.length === 0">
                                        <td
                                            colspan="9"
                                            class="px-6 py-8 text-center text-sm text-muted-foreground"
                                        >
                                            No bonus points recorded.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </Card>

                <!-- Redeemed Points Card -->
                <Card
                    class="overflow-hidden border-border shadow-sm transition-all duration-300"
                >
                    <div
                        class="flex cursor-pointer items-center justify-between border-b border-transparent bg-card px-6 py-4 transition-colors hover:bg-muted/30"
                        :class="{ 'border-border': isRedeemOpen }"
                        @click="isRedeemOpen = !isRedeemOpen"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="rounded-md bg-amber-500/10 p-2 dark:bg-amber-500/20"
                            >
                                <History
                                    class="h-5 w-5 text-amber-600 dark:text-amber-400"
                                />
                            </div>
                            <div>
                                <h2
                                    class="text-base font-semibold tracking-tight text-foreground"
                                >
                                    Redeemed Points
                                </h2>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    Journey:
                                    <strong
                                        >{{
                                            data.redemption_points_per_dollar ||
                                            data.points_per_dollar
                                        }}
                                        pts = $1</strong
                                    >
                                    · Partner:
                                    <strong
                                        >{{
                                            data.partner_points_per_dollar ||
                                            data.points_per_dollar
                                        }}
                                        pts = $1</strong
                                    >
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div @click.stop>
                                <AddRedemption
                                    :gratitudeNumber="gratitudeNumber"
                                    :usablePoints="usablePoints"
                                    :pointsPerDollar="
                                        data.redemption_points_per_dollar ||
                                        data.points_per_dollar
                                    "
                                    :partnerPointsPerDollar="
                                        data.partner_points_per_dollar ||
                                        data.points_per_dollar
                                    "
                                    :level="data.gratitude?.level || 'Explorer'"
                                    :journeys="data.journeys"
                                    @saved="fetchDetails"
                                />
                            </div>
                            <ChevronDown
                                class="h-5 w-5 text-muted-foreground transition-transform duration-200"
                                :class="{ 'rotate-180': isRedeemOpen }"
                            />
                        </div>
                    </div>
                    <div
                        v-show="isRedeemOpen"
                        class="border-t border-border bg-card p-0"
                    >
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-border">
                                <thead class="bg-muted/30">
                                    <tr>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Date
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Reason
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Points Used
                                        </th>
                                        <th
                                            class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Value ($)
                                        </th>
                                        <th
                                            class="px-6 py-3 text-right text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border bg-card">
                                    <tr
                                        v-for="redemption in data.redemptions"
                                        :key="redemption.id"
                                        class="transition-colors hover:bg-muted/30"
                                    >
                                        <td
                                            class="px-6 py-4 text-sm font-medium whitespace-nowrap text-foreground"
                                        >
                                            {{
                                                redemptionRecordDate(redemption)
                                            }}
                                        </td>
                                        <td
                                            class="px-6 py-4 text-sm whitespace-nowrap text-foreground/80"
                                        >
                                            {{ redemption.reason }}
                                        </td>
                                        <td
                                            class="px-6 py-4 text-sm font-bold whitespace-nowrap text-foreground"
                                        >
                                            {{
                                                formatNumber(redemption.points)
                                            }}
                                            pts
                                        </td>
                                        <td
                                            class="px-6 py-4 text-sm font-bold whitespace-nowrap text-green-600 dark:text-green-400"
                                        >
                                            ${{
                                                redemption.amount > 0
                                                    ? Number(
                                                          redemption.amount,
                                                      ).toFixed(2)
                                                    : (
                                                          redemption.points /
                                                          data.points_per_dollar
                                                      ).toFixed(2)
                                            }}
                                        </td>
                                        <td
                                            class="px-6 py-4 text-right text-sm whitespace-nowrap"
                                        >
                                            <div
                                                class="flex items-center justify-end gap-2"
                                            >
                                                <ViewRedemptionDetails
                                                    :redemption="redemption"
                                                    :pointsPerDollar="
                                                        data.points_per_dollar
                                                    "
                                                />
                                                <UpdateRedemption
                                                    :gratitudeNumber="
                                                        gratitudeNumber
                                                    "
                                                    :redemption="redemption"
                                                    :pointsPerDollar="
                                                        data.points_per_dollar
                                                    "
                                                    @saved="fetchDetails"
                                                />
                                                <DeleteRedemption
                                                    :gratitudeNumber="
                                                        gratitudeNumber
                                                    "
                                                    :redemptionId="
                                                        redemption.id
                                                    "
                                                    @saved="fetchDetails"
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                    <tr
                                        v-if="
                                            !data.redemptions ||
                                            data.redemptions.length === 0
                                        "
                                    >
                                        <td
                                            colspan="5"
                                            class="px-6 py-8 text-center text-sm text-muted-foreground"
                                        >
                                            No redemptions recorded.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
