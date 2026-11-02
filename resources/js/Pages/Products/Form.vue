<script setup lang="ts">
import ProductForm from '@/Components/ProductForm.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });
defineProps<{ product: any | null; showCost: boolean; canManageImages?: boolean; images?: any }>();
</script>

<template>
    <!-- Inertia reuses this component when redirecting create -> edit; the key forces fresh form state. -->
    <ProductForm :key="product?.id ?? 'new'" :product="product" :show-cost="showCost" :can-manage-images="canManageImages" :images="images" />
</template>
