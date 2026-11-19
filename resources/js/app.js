import Chart from 'chart.js/auto';

function initCashFlowCharts() {
    document.querySelectorAll('[data-cash-flow-chart]').forEach((container) => {
        if (container.dataset.chartReady === 'true') return;

        const dataElement = container.querySelector('[data-cash-flow-data]');
        const canvas = container.querySelector('canvas');
        if (!dataElement || !canvas) return;

        try {
            const payload = JSON.parse(dataElement.textContent);
            const history = payload.history ?? [];
            const forecast = payload.forecast ?? [];
            const labels = [...history.map((day) => day.date), ...forecast.map((day) => day.date)];
            const series = (key) => [
                ...history.map((day) => Number(day[key] ?? 0)),
                ...forecast.map((day) => Number(day[key] ?? 0)),
            ];
            const projectedCash = [
                ...history.map(() => null),
                ...forecast.map((day) => day.projectedCash === null ? null : Number(day.projectedCash)),
            ];
            const threshold = payload.threshold === null ? null : Number(payload.threshold);
            const datasets = [
                { type: 'bar', label: 'Confirmed inflow', data: series('inflow'), backgroundColor: '#2563eb', borderColor: '#2563eb', borderWidth: 2 },
                { type: 'bar', label: 'Confirmed outflow', data: series('outflow').map((value) => -value), backgroundColor: '#dc2626', borderColor: '#dc2626', borderWidth: 2 },
                { type: 'bar', label: 'Expected outflow', data: [...history.map(() => 0), ...forecast.map((day) => -Number(day.expectedOutflow ?? 0))], backgroundColor: '#d97706', borderColor: '#d97706', borderWidth: 2 },
                { type: 'bar', label: 'Uncertain collection', data: [...history.map(() => 0), ...forecast.map((day) => Number(day.uncertainInflow ?? 0))], backgroundColor: '#7c3aed', borderColor: '#7c3aed', borderWidth: 2 },
                { label: 'Projected balance', data: projectedCash, type: 'line', borderColor: '#0f172a', backgroundColor: '#0f172a', borderWidth: 3, pointRadius: 2, spanGaps: false, yAxisID: 'balance' },
            ];
            if (threshold !== null) {
                datasets.push({ label: 'Safety threshold', data: labels.map(() => threshold), type: 'line', borderColor: '#059669', borderDash: [6, 4], borderWidth: 2, pointRadius: 0, yAxisID: 'balance' });
            }

            new Chart(canvas, {
                data: { labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'bottom', labels: { usePointStyle: true, padding: 16 } },
                        tooltip: { callbacks: { label: (context) => `${context.dataset.label}: ${context.parsed.y === null ? 'Unavailable' : `PHP ${context.parsed.y.toFixed(2)}`}` } },
                    },
                    scales: {
                        x: { title: { display: true, text: 'Business date' }, ticks: { maxTicksLimit: 8 } },
                        y: { title: { display: true, text: 'Cash movement (PHP)' }, beginAtZero: true },
                        balance: { position: 'right', title: { display: true, text: 'Projected balance (PHP)' }, grid: { drawOnChartArea: false } },
                    },
                },
            });
            container.dataset.chartReady = 'true';
            container.querySelector('[data-chart-loading]')?.classList.add('hidden');
            container.querySelector('[data-chart-error]')?.classList.add('hidden');
        } catch (error) {
            console.error('Unable to render the cash-flow chart.', error);
            container.querySelector('[data-chart-loading]')?.classList.add('hidden');
            container.querySelector('[data-chart-error]')?.classList.remove('hidden');
        }
    });
}

document.addEventListener('DOMContentLoaded', initCashFlowCharts);
document.addEventListener('livewire:navigated', initCashFlowCharts);

// Flux owns the preference and system-theme listener. Animate only an explicit choice.
window.agosChangeAppearance = function (mode, button, apply) {
    const root = document.documentElement;
    const dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
    if (root.classList.contains('agos-theme-changing')) return;

    if (root.classList.contains('dark') === dark || !document.startViewTransition ||
        matchMedia('(prefers-reduced-motion: reduce)').matches) {
        apply(mode);
        return;
    }

    const { left, top, width, height } = button.getBoundingClientRect();
    const x = left + width / 2;
    const y = top + height / 2;
    const radius = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));

    root.classList.add('agos-theme-changing');
    const transition = document.startViewTransition(() => apply(mode));
    transition.ready.then(() => root.animate({
        clipPath: [`circle(0px at ${x}px ${y}px)`, `circle(${radius}px at ${x}px ${y}px)`],
    }, {
        duration: 760,
        easing: 'cubic-bezier(0.4, 0, 0.2, 1)',
        pseudoElement: '::view-transition-new(root)',
    })).catch(() => {}); // The browser may skip the animation on navigation.
    transition.finished.finally(() => root.classList.remove('agos-theme-changing'));
};
