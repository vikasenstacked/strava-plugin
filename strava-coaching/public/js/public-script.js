/**
 * Public-facing JavaScript for Strava Coaching Plugin
 * File: public/js/public-script.js
 */

jQuery(document).ready(function ($) {
    console.log('Strava Coaching Public JS loaded');

    // Initialize charts if they exist on the page
    initializePublicCharts();

    // Initialize chart controls
    initializeChartControls();
});

/**
 * Initialize all public charts
 */
function initializePublicCharts() {
    // Progress charts from shortcodes
    jQuery('.strava-progress-chart').each(function () {
        const canvas = this;
        const type = jQuery(canvas).data('type');
        const days = jQuery(canvas).data('days');
        const userId = jQuery(canvas).parent().data('user-id');

        loadProgressChart(canvas, userId, type, days);
    });

    // Activity charts from shortcodes
    jQuery('.strava-activity-chart').each(function () {
        const canvas = this;
        const menteeId = jQuery(canvas).data('mentee-id');
        const weekStart = jQuery(canvas).data('week-start');
        const showPlan = jQuery(canvas).data('show-plan') === 'yes';

        loadActivityChart(canvas, menteeId, weekStart, showPlan);
    });

    // Dashboard charts
    if (jQuery('#public-coach-chart').length) {
        loadCoachDashboardChart();
    }

    if (jQuery('#public-mentee-progress-chart').length) {
        loadMenteeDashboardChart();
    }

    // Mini charts for mentee cards
    jQuery('.mentee-mini-chart').each(function () {
        const canvas = this;
        const menteeId = jQuery(canvas).data('mentee-id');
        loadMiniChart(canvas, menteeId);
    });
}

/**
 * Initialize chart control interactions
 */
function initializeChartControls() {
    // Mentee selector for coach dashboard
    jQuery('#public-mentee-selector').on('change', function () {
        const selectedMentee = jQuery(this).val();
        updateCoachDashboardChart(selectedMentee);
    });

    // Date range selector
    jQuery('#public-date-range').on('change', function () {
        const days = jQuery(this).val();
        const selectedMentee = jQuery('#public-mentee-selector').val();
        updateCoachDashboardChart(selectedMentee, days);
    });
}

/**
 * Load progress chart
 */
function loadProgressChart(canvas, userId, type, days) {
    jQuery.post(stravaPublic.ajaxurl, {
        action: 'get_public_chart_data',
        chart_type: 'progress',
        user_id: userId,
        date_range: days,
        nonce: stravaPublic.nonce
    }, function (response) {
        if (response.success) {
            renderProgressChart(canvas, response.data, type);
        } else {
            console.error('Failed to load progress chart:', response.data.message);
        }
    });
}

/**
 * Render progress chart
 */
function renderProgressChart(canvas, data, type) {
    const ctx = canvas.getContext('2d');

    let datasets = [];

    if (type === 'distance' || type === 'all') {
        datasets.push({
            label: 'Distance (km)',
            data: data.distance,
            borderColor: '#FC4C02',
            backgroundColor: 'rgba(252, 76, 2, 0.1)',
            tension: 0.4
        });
    }

    if (type === 'pace' || type === 'all') {
        datasets.push({
            label: 'Pace (min/km)',
            data: data.pace,
            borderColor: '#2E86AB',
            backgroundColor: 'rgba(46, 134, 171, 0.1)',
            tension: 0.4,
            yAxisID: datasets.length > 0 ? 'y1' : 'y'
        });
    }

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: datasets.length > 1
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }

                            if (context.dataset.label.includes('Pace')) {
                                const totalSeconds = Math.round(context.parsed.y * 60);
                                const minutes = Math.floor(totalSeconds / 60);
                                const seconds = totalSeconds % 60;
                                label += minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
                            } else {
                                label += context.parsed.y.toFixed(2);
                            }

                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left'
                },
                y1: datasets.length > 1 ? {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    grid: {
                        drawOnChartArea: false
                    }
                } : undefined
            }
        }
    });
}

/**
 * Load activity chart (plan vs actual)
 */
function loadActivityChart(canvas, menteeId, weekStart, showPlan) {
    // For now, use dummy data - you'll implement the AJAX call similar to admin
    const dummyData = {
        labels: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
        planned: showPlan ? [5, 0, 8, 0, 10, 15, 0] : [],
        actual: [4.5, 0, 7.8, 0, 9.2, 14.5, 0]
    };

    renderActivityChart(canvas, dummyData, showPlan);
}

/**
 * Render activity chart
 */
function renderActivityChart(canvas, data, showPlan) {
    const ctx = canvas.getContext('2d');

    const datasets = [{
        label: 'Actual',
        data: data.actual,
        backgroundColor: 'rgba(46, 134, 171, 0.7)',
        borderColor: '#2E86AB',
        borderWidth: 2
    }];

    if (showPlan && data.planned.length > 0) {
        datasets.unshift({
            label: 'Planned',
            data: data.planned,
            backgroundColor: 'rgba(252, 76, 2, 0.3)',
            borderColor: '#FC4C02',
            borderWidth: 2
        });
    }

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                title: {
                    display: true,
                    text: 'Weekly Training Overview'
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
 * Load coach dashboard chart
 */
function loadCoachDashboardChart() {
    const menteeId = jQuery('#public-mentee-selector').val() || '';
    const days = jQuery('#public-date-range').val() || 30;

    jQuery.post(stravaPublic.ajaxurl, {
        action: 'get_public_chart_data',
        chart_type: 'weekly',
        user_id: menteeId || stravaPublic.currentUserId,
        date_range: days,
        nonce: stravaPublic.nonce
    }, function (response) {
        if (response.success) {
            renderCoachDashboardChart(response.data);
        }
    });
}

/**
 * Render coach dashboard chart
 */
function renderCoachDashboardChart(data) {
    const canvas = document.getElementById('public-coach-chart');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');

    // Destroy existing chart if it exists
    if (window.coachDashboardChart) {
        window.coachDashboardChart.destroy();
    }

    window.coachDashboardChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Distance (km)',
                data: data.distance,
                backgroundColor: '#FC4C02',
                yAxisID: 'y'
            }, {
                label: 'Activities',
                data: data.activities,
                backgroundColor: '#2E86AB',
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                title: {
                    display: true,
                    text: 'Weekly Training Summary'
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Distance (km)'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Number of Activities'
                    },
                    grid: {
                        drawOnChartArea: false
                    }
                }
            }
        }
    });
}

/**
 * Load mentee dashboard chart
 */
function loadMenteeDashboardChart() {
    jQuery.post(stravaPublic.ajaxurl, {
        action: 'get_public_chart_data',
        chart_type: 'progress',
        user_id: stravaPublic.currentUserId,
        date_range: 30,
        nonce: stravaPublic.nonce
    }, function (response) {
        if (response.success) {
            renderMenteeDashboardChart(response.data);
        }
    });
}

/**
 * Render mentee dashboard chart
 */
function renderMenteeDashboardChart(data) {
    const canvas = document.getElementById('public-mentee-progress-chart');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Distance (km)',
                data: data.distance,
                borderColor: '#FC4C02',
                backgroundColor: 'rgba(252, 76, 2, 0.1)',
                tension: 0.4,
                yAxisID: 'y'
            }, {
                label: 'Pace (min/km)',
                data: data.pace,
                borderColor: '#2E86AB',
                backgroundColor: 'rgba(46, 134, 171, 0.1)',
                tension: 0.4,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                title: {
                    display: true,
                    text: 'My Training Progress'
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Distance (km)'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Pace (min/km)'
                    },
                    grid: {
                        drawOnChartArea: false
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
 * Load mini chart for mentee cards
 */
function loadMiniChart(canvas, menteeId) {
    // Simple sparkline-style chart
    jQuery.post(stravaPublic.ajaxurl, {
        action: 'get_public_chart_data',
        chart_type: 'progress',
        user_id: menteeId,
        date_range: 7,
        nonce: stravaPublic.nonce
    }, function (response) {
        if (response.success) {
            renderMiniChart(canvas, response.data);
        }
    });
}

/**
 * Render mini chart
 */
function renderMiniChart(canvas, data) {
    const ctx = canvas.getContext('2d');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [{
                data: data.distance,
                borderColor: '#FC4C02',
                borderWidth: 2,
                pointRadius: 0,
                fill: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    enabled: false
                }
            },
            scales: {
                x: {
                    display: false
                },
                y: {
                    display: false
                }
            },
            elements: {
                line: {
                    tension: 0.4
                }
            }
        }
    });
}

/**
 * Update coach dashboard chart
 */
function updateCoachDashboardChart(menteeId, days) {
    const dateRange = days || jQuery('#public-date-range').val() || 30;

    jQuery.post(stravaPublic.ajaxurl, {
        action: 'get_public_chart_data',
        chart_type: 'weekly',
        user_id: menteeId || stravaPublic.currentUserId,
        date_range: dateRange,
        nonce: stravaPublic.nonce
    }, function (response) {
        if (response.success) {
            renderCoachDashboardChart(response.data);
        }
    });
}