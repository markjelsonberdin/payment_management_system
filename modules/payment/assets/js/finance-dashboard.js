document.addEventListener("DOMContentLoaded", function() {
    fetchDashboardData();
});

let charts = {};

function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function fetchDashboardData() {
    fetch(API_URL)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error("Dashboard Error:", data.error);
                return;
            }
            updateKPIs(data.kpis);
            renderCategoryChart(data.collections_by_category);
            renderTrendChart(data.collection_trend);
            renderStatusChart(data.payment_status);
            renderChannelChart(data.payment_channels);
            renderRecentActivity(data.recent_activity);
        })
        .catch(err => console.error("Error fetching dashboard data:", err));
}

function updateKPIs(kpis) {
    document.getElementById('kpi-collections-today').innerText = formatCurrency(kpis.collections_today);
    document.getElementById('kpi-pending-payments').innerText = kpis.pending_payments;
    document.getElementById('kpi-collected-month').innerText = formatCurrency(kpis.collected_month);
    document.getElementById('kpi-outstanding-balance').innerText = formatCurrency(kpis.outstanding_balance);
}

function renderCategoryChart(categories) {
    const ctx = document.getElementById("categoryChart");
    if (charts.category) charts.category.destroy();

    if (categories.length === 0) {
        // Render Empty State
        charts.category = new Chart(ctx, {
            type: 'doughnut',
            data: { labels: ['No Data'], datasets: [{ data: [1], backgroundColor: ['#eaecf4'], hoverBackgroundColor: ['#eaecf4'], hoverBorderColor: "rgba(234, 236, 244, 1)" }] },
            options: { maintainAspectRatio: false, tooltips: { enabled: false }, cutout: '80%' }
        });
        document.getElementById('categoryLegend').innerHTML = '<span class="text-muted">No collection data available.</span>';
        return;
    }

    const labels = categories.map(c => c.category_name);
    const data = categories.map(c => parseFloat(c.total));
    const colors = ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#858796'];

    charts.category = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors.slice(0, data.length),
                hoverBorderColor: "rgba(234, 236, 244, 1)",
            }],
        },
        options: {
            maintainAspectRatio: false,
            cutout: '80%',
            plugins: {
                legend: { display: false }
            },
            tooltips: {
                callbacks: {
                    label: function(tooltipItem, data) {
                        return data.labels[tooltipItem.index] + ': ' + formatCurrency(data.datasets[0].data[tooltipItem.index]);
                    }
                }
            }
        }
    });

    // Build Custom Legend
    let legendHtml = '';
    labels.forEach((label, i) => {
        const color = colors[i % colors.length];
        legendHtml += `<span class="me-3"><i class="fas fa-circle" style="color:${color}"></i> ${label}</span>`;
    });
    document.getElementById('categoryLegend').innerHTML = legendHtml;
}

function renderTrendChart(trend) {
    const ctx = document.getElementById("trendChart");
    if (charts.trend) charts.trend.destroy();

    const isEmpty = trend.data.every(val => val === 0);

    charts.trend = new Chart(ctx, {
        type: 'line',
        data: {
            labels: trend.labels,
            datasets: [{
                label: "Collected",
                lineTension: 0.3,
                backgroundColor: "rgba(78, 115, 223, 0.05)",
                borderColor: "rgba(78, 115, 223, 1)",
                pointRadius: 3,
                pointBackgroundColor: "rgba(78, 115, 223, 1)",
                pointBorderColor: "rgba(78, 115, 223, 1)",
                pointHoverRadius: 3,
                pointHoverBackgroundColor: "rgba(78, 115, 223, 1)",
                pointHoverBorderColor: "rgba(78, 115, 223, 1)",
                pointHitRadius: 10,
                pointBorderWidth: 2,
                data: trend.data,
            }],
        },
        options: {
            maintainAspectRatio: false,
            layout: { padding: { left: 10, right: 25, top: 25, bottom: 0 } },
            scales: {
                x: {
                    grid: { display: false, drawBorder: false }
                },
                y: {
                    ticks: {
                        maxTicksLimit: 5,
                        padding: 10,
                        callback: function(value) { return '₱' + value; }
                    },
                    grid: {
                        color: "rgb(234, 236, 244)",
                        zeroLineColor: "rgb(234, 236, 244)",
                        drawBorder: false,
                        borderDash: [2],
                        zeroLineBorderDash: [2]
                    }
                }
            },
            plugins: { legend: { display: false } },
            tooltips: {
                callbacks: {
                    label: function(tooltipItem, chart) {
                        return 'Collections: ' + formatCurrency(tooltipItem.yLabel);
                    }
                }
            }
        }
    });

    if (isEmpty) {
        // Just add a text overlay or something, but Chart.js will gracefully draw a flat line at 0 which is fine.
    }
}

function renderStatusChart(status) {
    const ctx = document.getElementById("statusChart");
    if (charts.status) charts.status.destroy();

    charts.status = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: status.labels,
            datasets: [{
                label: "Payments",
                backgroundColor: ['#1cc88a', '#f6c23e', '#e74a3b', '#858796'],
                hoverBackgroundColor: ['#17a673', '#dda20a', '#be2617', '#60616f'],
                borderColor: "#4e73df",
                data: status.data,
            }],
        },
        options: {
            maintainAspectRatio: false,
            scales: {
                x: { grid: { display: false, drawBorder: false } },
                y: {
                    ticks: { maxTicksLimit: 5, stepSize: 1 },
                    grid: { color: "rgb(234, 236, 244)", zeroLineColor: "rgb(234, 236, 244)", drawBorder: false, borderDash: [2] }
                }
            },
            plugins: { legend: { display: false } }
        }
    });
}

function renderChannelChart(channels) {
    const ctx = document.getElementById("channelChart");
    if (charts.channel) charts.channel.destroy();

    if (channels.length === 0) {
        charts.channel = new Chart(ctx, {
            type: 'doughnut',
            data: { labels: ['No Data'], datasets: [{ data: [1], backgroundColor: ['#eaecf4'] }] },
            options: { maintainAspectRatio: false, cutout: '80%', plugins: { legend: { display: false } } }
        });
        return;
    }

    const labels = channels.map(c => c.method);
    const data = channels.map(c => parseFloat(c.total));
    
    charts.channel = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: ['#4e73df', '#36b9cc', '#f6c23e'],
                hoverBorderColor: "rgba(234, 236, 244, 1)",
            }],
        },
        options: {
            maintainAspectRatio: false,
            cutout: '80%',
            plugins: {
                legend: { position: 'bottom' }
            },
            tooltips: {
                callbacks: {
                    label: function(tooltipItem, data) {
                        return data.labels[tooltipItem.index] + ': ' + formatCurrency(data.datasets[0].data[tooltipItem.index]);
                    }
                }
            }
        }
    });
}

function renderRecentActivity(activities) {
    const tbody = document.getElementById('recentActivityTable');
    tbody.innerHTML = '';

    if (activities.length === 0) {
        tbody.innerHTML = '<tr><td colspan="2" class="text-center py-4 text-muted">No recent activity found.</td></tr>';
        return;
    }

    activities.forEach(act => {
        const dateObj = new Date(act.created_at);
        const dateStr = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        const timeStr = dateObj.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="ps-4 fw-medium text-dark"><i class="fas fa-check-circle text-success me-2"></i> ${act.detail}</td>
            <td class="text-muted small">${dateStr} &bull; ${timeStr}</td>
        `;
        tbody.appendChild(tr);
    });
}
