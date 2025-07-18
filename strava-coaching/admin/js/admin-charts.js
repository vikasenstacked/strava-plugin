/**
 * Chart functionality for Strava Coaching Plugin
 * File: admin/js/admin-charts.js
 */

jQuery(document).ready(function ($) {
    // Initialize charts on page load
    if ($('.strava-chart-container').length) {
        initializeCharts();
    }
});

// Global chart instances
let stravaCharts = {};

/**
 * Initialize all charts on the page
 */
function initializeCharts() {
    // Activity Progress Chart
    if (jQuery('#activityProgressChart').length) {
        loadActivityProgressChart();
    }

    // Weekly Summary Chart
    if (jQuery('#weeklySummaryChart').length) {
        loadWeeklySummaryChart();
    }

    // Heart Rate Distribution Chart
    if (jQuery('#heartRateChart').length) {
        loadHeartRateChart();
    }

    // Plan vs Actual Chart
    if (jQuery('#planVsActualChart').length) {
        loadPlanVsActualChart();
    }
}

/**
 * Load Activity Progress Chart
 */
function loadActivityProgressChart(filters = {}) {
    const ctx = document.getElementById('activityProgressChart');
    if (!ctx) return;

    // Show loading
    showChartLoading('activityProgressChart');

    jQuery.post(ajaxurl, {
        action: 'get_activity_chart_data',
        user_id: filters.user_id || stravaCoaching.currentUserId,
        activity_type: filters.activity_type || 'all',
        date_range: filters.date_range || '30days',
        nonce: stravaCoaching.chartNonce
    }, function (response) {
        if (response.success) {
            renderActivityProgressChart(ctx, response.data);
        } else {
            showChartError('activityProgressChart', response.data.message);
        }
    });
}

/**
 * Render Activity Progress Chart
 */
function renderActivityProgressChart(ctx, data) {
    // Destroy existing chart
    if (stravaCharts.activityProgress) {
        stravaCharts.activityProgress.destroy();
    }

    stravaCharts.activityProgress = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Distance (km)',
                data: data.distance,
                borderColor: '#FC4C02',
                backgroundColor: 'rgba(252, 76, 2, 0.1)',
                yAxisID: 'y-distance',
                tension: 0.4
            }, {
                label: 'Pace (min/km)',
                data: data.pace,
                borderColor: '#2E86AB',
                backgroundColor: 'rgba(46, 134, 171, 0.1)',
                yAxisID: 'y-pace',
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                title: {
                    display: true,
                    text: 'Activity Progress Over Time'
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                if (context.dataset.label === 'Pace (min/km)') {
                                    const totalSeconds = Math.round(context.parsed.y * 60);
                                    const minutes = Math.floor(totalSeconds / 60);
                                    const seconds = totalSeconds % 60;
                                    label += minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
                                } else {
                                    label += context.parsed.y.toFixed(2);
                                }
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                'y-distance': {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Distance (km)'
                    }
                },
                'y-pace': {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Pace (min/km)'
                    },
                    grid: {
                        drawOnChartArea: false,
                    },
                    ticks: {
                        callback: function (value) {
                            const totalSeconds = Math.round(value * 60);
                            const minutes = Math.floor(totalSeconds / 60);
                            const seconds = totalSeconds % 60;
                            return minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
                        }
                    }
                }
            }
        }
    });
}

/**
 * Load Weekly Summary Chart
 */
function loadWeeklySummaryChart(userId = null) {
    const ctx = document.getElementById('weeklySummaryChart');
    if (!ctx) return;

    showChartLoading('weeklySummaryChart');

    jQuery.post(ajaxurl, {
        action: 'get_weekly_summary_data',
        user_id: userId || stravaCoaching.currentUserId,
        nonce: stravaCoaching.chartNonce
    }, function (response) {
        if (response.success) {
            renderWeeklySummaryChart(ctx, response.data);
        } else {
            showChartError('weeklySummaryChart', response.data.message);
        }
    });
}

/**
 * Render Weekly Summary Chart
 */
function renderWeeklySummaryChart(ctx, data) {
    if (stravaCharts.weeklySummary) {
        stravaCharts.weeklySummary.destroy();
    }

    stravaCharts.weeklySummary = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Distance (km)',
                data: data.distance,
                backgroundColor: '#FC4C02',
                yAxisID: 'y-distance',
            }, {
                label: 'Duration (hours)',
                data: data.duration,
                backgroundColor: '#2E86AB',
                yAxisID: 'y-duration',
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Weekly Training Summary'
                }
            },
            scales: {
                'y-distance': {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Distance (km)'
                    }
                },
                'y-duration': {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Duration (hours)'
                    },
                    grid: {
                        drawOnChartArea: false,
                    }
                }
            }
        }
    });
}

/**
 * Load Heart Rate Distribution Chart
 */
function loadHeartRateChart(filters = {}) {
    const ctx = document.getElementById('heartRateChart');
    if (!ctx) return;

    showChartLoading('heartRateChart');

    jQuery.post(ajaxurl, {
        action: 'get_heart_rate_data',
        user_id: filters.user_id || stravaCoaching.currentUserId,
        date_range: filters.date_range || '30days',
        nonce: stravaCoaching.chartNonce
    }, function (response) {
        if (response.success) {
            renderHeartRateChart(ctx, response.data);
        } else {
            showChartError('heartRateChart', response.data.message);
        }
    });
}

/**
 * Render Heart Rate Distribution Chart
 */
function renderHeartRateChart(ctx, data) {
    if (stravaCharts.heartRate) {
        stravaCharts.heartRate.destroy();
    }

    // Calculate heart rate zones
    const zones = calculateHeartRateZones(data);

    stravaCharts.heartRate = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Zone 1 (Recovery)', 'Zone 2 (Easy)', 'Zone 3 (Moderate)', 'Zone 4 (Hard)', 'Zone 5 (Max)'],
            datasets: [{
                data: zones,
                backgroundColor: [
                    '#4CAF50',
                    '#8BC34A',
                    '#FFC107',
                    '#FF9800',
                    '#F44336'
                ]
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Heart Rate Zone Distribution'
                },
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            let label = context.label || '';
                            if (label) {
                                label += ': ';
                            }
                            const value = context.parsed;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            label += percentage + '%';
                            return label;
                        }
                    }
                }
            }
        }
    });
}

/**
 * Load Plan vs Actual Chart
 */
function loadPlanVsActualChart(menteeId, weekStart) {
    const ctx = document.getElementById('planVsActualChart');
    if (!ctx) return;

    showChartLoading('planVsActualChart');

    jQuery.post(ajaxurl, {
        action: 'get_plan_vs_actual_data',
        mentee_id: menteeId,
        week_start: weekStart,
        nonce: stravaCoaching.chartNonce
    }, function (response) {
        if (response.success) {
            renderPlanVsActualChart(ctx, response.data);
        } else {
            showChartError('planVsActualChart', response.data.message);
        }
    });
}

/**
 * Render Plan vs Actual Chart
 */
function renderPlanVsActualChart(ctx, data) {
    if (stravaCharts.planVsActual) {
        stravaCharts.planVsActual.destroy();
    }

    stravaCharts.planVsActual = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Planned',
                data: data.planned,
                backgroundColor: 'rgba(252, 76, 2, 0.5)',
                borderColor: '#FC4C02',
                borderWidth: 2
            }, {
                label: 'Actual',
                data: data.actual,
                backgroundColor: 'rgba(46, 134, 171, 0.5)',
                borderColor: '#2E86AB',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Training Plan vs Actual Performance'
                },
                tooltip: {
                    callbacks: {
                        afterLabel: function (context) {
                            if (context.datasetIndex === 1) { // Actual dataset
                                const planned = data.planned[context.dataIndex];
                                const actual = context.parsed.y;
                                const diff = actual - planned;
                                const percentage = planned > 0 ? ((actual / planned) * 100).toFixed(0) : 0;
                                return `${percentage}% of plan (${diff >= 0 ? '+' : ''}${diff.toFixed(1)} km)`;
                            }
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Distance (km)'
                    }
                }
            }
        }
    });
}

/**
 * Chart Filter Controls
 */
function initializeChartFilters() {
    // Activity type filter
    jQuery('#activityTypeFilter').on('change', function () {
        const filters = {
            activity_type: jQuery(this).val(),
            date_range: jQuery('#dateRangeFilter').val()
        };
        loadActivityProgressChart(filters);
    });

    // Date range filter
    jQuery('#dateRangeFilter').on('change', function () {
        const filters = {
            activity_type: jQuery('#activityTypeFilter').val(),
            date_range: jQuery(this).val()
        };
        loadActivityProgressChart(filters);
        loadHeartRateChart(filters);
    });

    // Mentee selector for coaches
    jQuery('#chartMenteeSelector').on('change', function () {
        const menteeId = jQuery(this).val();
        if (menteeId) {
            loadActivityProgressChart({ user_id: menteeId });
            loadWeeklySummaryChart(menteeId);
            loadHeartRateChart({ user_id: menteeId });
        }
    });
}

/**
 * Utility Functions
 */
function showChartLoading(chartId) {
    const container = jQuery('#' + chartId).parent();
    container.append('<div class="chart-loading">Loading chart data...</div>');
}

function showChartError(chartId, message) {
    const container = jQuery('#' + chartId).parent();
    container.find('.chart-loading').remove();
    container.append('<div class="chart-error">Error: ' + message + '</div>');
}

function calculateHeartRateZones(heartRateData) {
    // Basic zone calculation (you can make this more sophisticated)
    const zones = [0, 0, 0, 0, 0];

    heartRateData.forEach(hr => {
        if (hr < 120) zones[0]++;
        else if (hr < 140) zones[1]++;
        else if (hr < 160) zones[2]++;
        else if (hr < 180) zones[3]++;
        else zones[4]++;
    });

    return zones;
}

// Initialize filters when DOM is ready
jQuery(document).ready(function () {
    initializeChartFilters();
});