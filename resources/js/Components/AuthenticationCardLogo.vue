<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import WorkLogdLockup from '@/Components/Brand/WorkLogdLockup.vue';
</script>

<template>
    <Link :href="'/'">
        <!-- Auth surfaces take the lockup at size 32. There is no stacked variant. -->
        <WorkLogdLockup :size="32" />
    </Link>
</template>
