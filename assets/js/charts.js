/* AutoCare Hub — Real-time Chart.js dashboards */
let charts = {};
let chartApiUrl = null;
let chartTimer = null;

function chartThemeColors() {
  const isLight = (document.documentElement.getAttribute('data-theme') || 'dark') === 'light';
  return {
    legend: isLight ? '#475569' : '#94a3b8',
    ticks: isLight ? '#64748b' : '#94a3b8',
    grid: isLight ? 'rgba(148,163,184,0.35)' : 'rgba(51,65,85,0.55)',
    tooltipBg: isLight ? '#ffffff' : '#1e293b',
    tooltipTitle: isLight ? '#0f172a' : '#f1f5f9',
    tooltipBody: isLight ? '#475569' : '#cbd5e1',
  };
}

function destroyChart(id) {
  if (charts[id]) {
    try { charts[id].destroy(); } catch (e) { /* ignore */ }
    delete charts[id];
  }
}

function waitForChart(cb, tries) {
  tries = tries || 0;
  if (typeof Chart !== 'undefined') {
    cb();
    return;
  }
  if (tries > 40) {
    console.warn('Chart.js failed to load');
    return;
  }
  setTimeout(() => waitForChart(cb, tries + 1), 50);
}

function baseChartOptions(colors) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 650, easing: 'easeOutQuart' },
    plugins: {
      legend: { labels: { color: colors.legend, boxWidth: 12, padding: 12 } },
      tooltip: {
        backgroundColor: colors.tooltipBg,
        titleColor: colors.tooltipTitle,
        bodyColor: colors.tooltipBody,
        borderColor: colors.grid,
        borderWidth: 1,
      }
    },
    scales: {
      x: { ticks: { color: colors.ticks }, grid: { color: colors.grid } },
      y: { ticks: { color: colors.ticks }, grid: { color: colors.grid }, beginAtZero: true }
    }
  };
}

async function refreshDashboardCharts() {
  if (!chartApiUrl || typeof Chart === 'undefined') return;
  const colors = chartThemeColors();
  const opts = baseChartOptions(colors);

  try {
    const res = await fetch(chartApiUrl + (chartApiUrl.includes('?') ? '&' : '?') + '_=' + Date.now(), {
      cache: 'no-store',
      credentials: 'same-origin'
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const d = await res.json();

    // Income / spending line
    destroyChart('income');
    const ctx1 = document.getElementById('chart-income');
    if (ctx1 && d.income) {
      const moneyLabel = d.income.label || 'Income (RM)';
      const datasets = [{
        label: moneyLabel,
        data: d.income.data || [],
        borderColor: '#f97316',
        backgroundColor: 'rgba(249,115,22,.12)',
        fill: true,
        tension: 0.4,
        pointRadius: 3,
        pointHoverRadius: 5,
      }];
      if (d.role === 'mechanic' && d.income.commission_data && d.income.commission_data.length) {
        datasets.push({
          label: d.income.commission_label || 'Commission (RM)',
          data: d.income.commission_data,
          borderColor: '#34d399',
          backgroundColor: 'rgba(52,211,153,.12)',
          fill: true,
          tension: 0.4,
          pointRadius: 3,
        });
      }
      charts.income = new Chart(ctx1, {
        type: 'line',
        data: { labels: d.income.labels || [], datasets },
        options: opts
      });
    }

    // Status doughnut
    destroyChart('status');
    const ctx2 = document.getElementById('chart-status');
    if (ctx2 && d.status) {
      charts.status = new Chart(ctx2, {
        type: 'doughnut',
        data: {
          labels: d.status.labels || ['No Data'],
          datasets: [{
            data: d.status.data || [0],
            backgroundColor: ['#fbbf24', '#34d399', '#a78bfa', '#38bdf8', '#f87171', '#94a3b8'],
            borderWidth: 0,
            hoverOffset: 6,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: { duration: 650 },
          plugins: {
            legend: { position: 'bottom', labels: { color: colors.legend, padding: 12, boxWidth: 12 } },
            tooltip: opts.plugins.tooltip,
          },
          cutout: '58%',
        }
      });
    }

    // Top services bar
    destroyChart('services');
    const ctx3 = document.getElementById('chart-services');
    if (ctx3 && d.services) {
      charts.services = new Chart(ctx3, {
        type: 'bar',
        data: {
          labels: d.services.labels || [],
          datasets: [{
            label: 'Bookings',
            data: d.services.data || [],
            backgroundColor: 'rgba(249,115,22,.85)',
            borderRadius: 6,
            maxBarThickness: 36,
          }]
        },
        options: {
          ...opts,
          plugins: { ...opts.plugins, legend: { display: false } },
          scales: {
            x: { ticks: { color: colors.ticks }, grid: { display: false } },
            y: { ticks: { color: colors.ticks }, grid: { color: colors.grid }, beginAtZero: true }
          }
        }
      });
    }

    // Admin P&L
    destroyChart('pnl');
    const ctxPnl = document.getElementById('chart-pnl');
    if (ctxPnl && d.pnl) {
      charts.pnl = new Chart(ctxPnl, {
        type: 'bar',
        data: {
          labels: d.pnl.labels || [],
          datasets: [
            { label: 'Revenue (RM)', data: d.pnl.revenue || [], backgroundColor: 'rgba(52,211,153,.75)', borderRadius: 4, order: 2 },
            { label: 'Costs (RM)', data: d.pnl.cost || [], backgroundColor: 'rgba(248,113,113,.75)', borderRadius: 4, order: 2 },
            {
              label: 'Profit (RM)',
              data: d.pnl.profit || [],
              type: 'line',
              borderColor: '#f97316',
              backgroundColor: 'rgba(249,115,22,.12)',
              tension: 0.35,
              fill: true,
              order: 1,
              pointRadius: 3,
            }
          ]
        },
        options: opts
      });
    }

    // Capacity bars
    const mechBar = document.getElementById('live-mech-bar');
    const elecBar = document.getElementById('live-elec-bar');
    const mechPct = document.getElementById('live-mech-pct');
    const elecPct = document.getElementById('live-elec-pct');
    if (d.capacity) {
      if (mechBar) mechBar.style.width = (d.capacity.mech || 0) + '%';
      if (elecBar) elecBar.style.width = (d.capacity.elec || 0) + '%';
      if (mechPct) mechPct.textContent = (d.capacity.mech || 0) + '%';
      if (elecPct) elecPct.textContent = (d.capacity.elec || 0) + '%';
    }

    if (d.stats) {
      ['totalClients', 'bookings', 'grossIncome', 'todayAppts', 'commissionTotal', 'monthSpent', 'monthProfit', 'monthCost'].forEach(k => {
        const el = document.getElementById('live-' + k);
        if (el && d.stats[k] !== undefined) el.textContent = d.stats[k];
      });
    }
  } catch (e) {
    console.warn('Chart refresh failed', e);
  }
}

function initDashboardCharts(apiUrl) {
  chartApiUrl = apiUrl;
  waitForChart(() => {
    refreshDashboardCharts();
    if (chartTimer) clearInterval(chartTimer);
    chartTimer = setInterval(refreshDashboardCharts, 8000);
  });
  window.addEventListener('themechange', () => {
    // Rebuild charts so colors match theme
    refreshDashboardCharts();
  });
}

// Auto-init if data attribute set
document.addEventListener('DOMContentLoaded', () => {
  const el = document.querySelector('[data-chart-api]');
  if (el && el.getAttribute('data-chart-api')) {
    initDashboardCharts(el.getAttribute('data-chart-api'));
  }
});
