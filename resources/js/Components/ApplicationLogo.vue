<script setup>
defineProps({
    alt: {
        type: String,
        default: 'SocialDesk',
    },
});
</script>

<template>
    <img src="/images/logo.png" :alt="alt" class="h-9 w-auto" />
</template>
