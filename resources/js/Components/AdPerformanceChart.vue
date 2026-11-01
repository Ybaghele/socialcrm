<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    LinearScale,
    Tooltip,
} from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip);

const props = defineProps({
    series: { type: Array, default: () => [] },
});

const canvas = ref(null);
let chart = null;

const compact = new Intl.NumberFormat('en-IN', { notation: 'compact', maximumFractionDigits: 1 });
const full = new Intl.NumberFormat('en-IN');

function renderChart() {
    if (!canvas.value) {
        return;
    }

    chart?.destroy();

    chart = new Chart(canvas.value, {
        type: 'bar',
        data: {
            labels: props.series.map((row) => row.label),
            datasets: [
                {
                    label: 'Impressions',
                    data: props.series.map((row) => row.impressions),
                    yAxisID: 'y',
                    backgroundColor: 'rgba(99, 102, 241, 0.78)',
                    hoverBackgroundColor: 'rgba(79, 70, 229, 0.95)',
                    borderRadius: 4,
                    maxBarThickness: 16,
                },
                {
                    label: 'Clicks',
                    data: props.series.map((row) => row.clicks),
                    yAxisID: 'y1',
                    backgroundColor: 'rgba(16, 185, 129, 0.78)',
                    hoverBackgroundColor: 'rgba(5, 150, 105, 0.95)',
                    borderRadius: 4,
                    maxBarThickness: 16,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 250 },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f172a',
                    padding: 10,
                    titleFont: { size: 11, weight: '600' },
                    bodyFont: { size: 11 },
                    callbacks: {
                        label: (context) => ` ${context.dataset.label}: ${full.format(context.parsed.y)}`,
                    },
                },
            },
            scales: {
                x: {
                    type: 'category',
                    grid: { display: false },
                    ticks: {
                        color: '#64748b',
                        font: { size: 10 },
                        maxRotation: 0,
                        autoSkip: true,
                        maxTicksLimit: 12,
                    },
                },
                y: {
                    type: 'linear',
                    position: 'left',
                    beginAtZero: true,
                    grid: { color: 'rgba(148, 163, 184, 0.16)' },
                    ticks: {
                        color: '#64748b',
                        font: { size: 10 },
                        callback: (value) => compact.format(Number(value)),
                    },
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    beginAtZero: true,
                    grid: { drawOnChartArea: false },
                    ticks: {
                        color: '#64748b',
                        font: { size: 10 },
                        callback: (value) => compact.format(Number(value)),
                    },
                },
            },
        },
    });
}

onMounted(renderChart);
watch(() => props.series, renderChart, { deep: true });
onBeforeUnmount(() => {
    chart?.destroy();
    chart = null;
});
</script>

<template>
    <div class="relative h-72 w-full">
        <canvas ref="canvas" role="img" aria-label="Daily impressions and clicks chart"></canvas>
    </div>
</template>
