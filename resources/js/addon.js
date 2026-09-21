import TestPipeline from './pages/TestPipeline.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('search-service::TestPipeline', TestPipeline);
});
