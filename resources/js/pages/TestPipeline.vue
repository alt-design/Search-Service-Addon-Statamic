<script setup>
import { computed, getCurrentInstance, ref, watch } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { Badge, Button, Card, Description, Header, PublishContainer, PublishFields, PublishFieldsProvider } from '@statamic/cms/ui';

const props = defineProps({
    blueprint: Object,
    values: Object,
    meta: Object,
    documentUrl: String,
    runUrl: String,
});

// The CP's axios (with CSRF header) is an app global property, not on the Statamic global
const { $axios } = getCurrentInstance().appContext.config.globalProperties;

const values = ref(props.values);
const meta = ref(props.meta);
const sent = ref(null);
const result = ref(null);
const running = ref(false);

// Inline because the CP stylesheet has no utilities for these
const valueStyle = { whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' };
const cell = 'bg-gray-50 px-3 py-2 dark:bg-white/4';
const rowCell = 'border-t border-gray-200 dark:border-gray-700';

watch(
    () => values.value.entry?.[0],
    async (entry) => {
        result.value = null;
        sent.value = entry ? (await $axios.get(props.documentUrl, { params: { entry } })).data : null;
    },
);

async function run() {
    running.value = true;

    try {
        result.value = (await $axios.post(props.runUrl, { entry: sent.value.reference })).data;
    } finally {
        running.value = false;
    }
}

const ran = computed(() => result.value && !result.value.error);

// One row per field, sent order first, so each sent value sits beside what came back
const rows = computed(() => {
    const returned = Object.fromEntries((ran.value ? result.value.fields : []).map((field) => [field.field, field]));
    const names = [...new Set([...Object.keys(sent.value?.fields ?? {}), ...Object.keys(returned)])];

    return names.map((name) => ({ name, sent: sent.value?.fields[name], returned: returned[name] }));
});
</script>

<template>
    <Head title="Test Pipeline" />
    <Header title="Test Pipeline" icon="magnifying-glass" />

    <Card>
        <div class="flex items-end gap-4">
            <div class="flex-1 min-w-0">
                <PublishContainer :blueprint="blueprint" v-model="values" v-model:meta="meta">
                    <PublishFieldsProvider :fields="blueprint.tabs[0].sections[0].fields">
                        <PublishFields />
                    </PublishFieldsProvider>
                </PublishContainer>
            </div>

            <Button class="shrink-0" variant="primary" text="Run Pipeline" :disabled="!sent" :loading="running" @click="run" />
        </div>

        <Description class="mt-2">Runs the saved pipeline on the search service. Nothing is stored. Limited to 5 runs a minute.</Description>

        <p v-if="result?.error" class="mt-4 text-red-600">{{ result.error }}</p>
        <p v-for="error in ran ? result.errors : []" :key="error" class="mt-4 text-red-600">{{ error }}</p>

        <!-- Two tinted columns in one grid, so each sent value stays level with what came back -->
        <div v-if="sent" class="mt-6 grid grid-cols-2 gap-x-4 text-sm">
            <div :class="[cell, 'rounded-t-lg font-medium']">Sent</div>
            <div :class="[cell, 'rounded-t-lg font-medium']">
                Returned
                <span class="font-normal text-gray-500 dark:text-gray-400">{{ ran ? `in ${result.duration_ms}ms` : '· run the pipeline to compare' }}</span>
            </div>

            <template v-for="(row, index) in rows" :key="row.name">
                <div :class="[cell, rowCell, { 'rounded-b-lg': index === rows.length - 1 }]">
                    <div class="mb-1 flex h-6 items-center font-mono text-xs text-gray-500 dark:text-gray-400">{{ row.name }}</div>
                    <div :style="valueStyle">{{ row.sent }}</div>
                </div>

                <div :class="[cell, rowCell, { 'rounded-b-lg': index === rows.length - 1 }]">
                    <div class="mb-1 flex h-6 items-center gap-2">
                        <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ row.name }}</span>
                        <template v-if="ran && row.returned">
                            <Badge size="sm">Weight {{ row.returned.weight }}</Badge>
                            <Badge v-if="row.returned.value !== row.sent" size="sm" color="green">Changed</Badge>
                        </template>
                        <Badge v-else-if="ran" size="sm" color="red">Removed</Badge>
                    </div>
                    <div :style="valueStyle">{{ row.returned?.value }}</div>
                </div>
            </template>
        </div>
    </Card>
</template>
