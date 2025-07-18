jQuery(document).ready(function($) {
    $('.remove-mentee-button').on('click', function() {
        const menteeId = $(this).data('mentee-id');
        const menteeName = $(this).data('mentee-name');

        removeMentee(menteeId, menteeName);
    });
});

function showNoticemsg(message, type = 'success') {
    // Remove any existing notices
    jQuery('.custom-notice').remove();

    // Choose color and icon based on type
    const colors = {
        success: '#d4edda',
        error: '#f8d7da'
    };

    const borderColors = {
        success: '#28a745',
        error: '#dc3545'
    };

    const textColors = {
        success: '#155724',
        error: '#721c24'
    };

    // Create the notice element
    const notice = jQuery(`
        <div class="custom-notice" style="
            background-color: ${colors[type]};
            border: 1px solid ${borderColors[type]};
            color: ${textColors[type]};
            padding: 10px 15px;
            border-radius: 4px;
            margin-top: 15px;
            font-weight: 500;
        ">
            ${message}
        </div>
    `);

    // Append it after .btn-strava
    jQuery('.btn-strava').after(notice);

    // Auto-remove after 5 seconds
    setTimeout(() => {
        notice.fadeOut(300, function () {
            jQuery(this).remove();
        });
    }, 5000);
}

jQuery(document).ready(function ($) {
    console.log('Strava Coaching Admin JS loaded - Phase 3');

    // Show success/error messages based on URL parameters
    const urlParams = new URLSearchParams(window.location.search);

    if (urlParams.get('connected') === '1') {
        showNoticemsg('🎉 Successfully connected to Strava! Your activities are being synced.', 'success');
    }

    if (urlParams.get('error') === '1') {
        showNoticemsg('❌ Failed to connect to Strava. Please try again.', 'error');
    }

    // Initialize all functionality
    initializeTrainingPlanCreator();
    initializeMenteeManagement();
    // initializeActivityScoring();
});


/**
 * TRAINING PLAN FUNCTIONALITY
 */
function initializeTrainingPlanCreator() {
    // Show/hide workout details based on workout type selection
    jQuery(document).on('change', '.workout-type', function () {
        const workoutDetails = jQuery(this).siblings('.workout-details');
        if (jQuery(this).val() && jQuery(this).val() !== '') {
            workoutDetails.show();
        } else {
            workoutDetails.hide();
        }
    });

    // Close modals when clicking outside
    jQuery(document).on('click', '.strava-modal', function (e) {
        if (e.target === this) {
            jQuery(this).hide();
        }
    });

    // Handle training plan form submission
    jQuery('#trainingPlanForm').on('submit', function (e) {
        if (!validateTrainingPlanForm()) {
            e.preventDefault();
            return false;
        }

        showLoading('Creating training plan...');
    });
}

function showTrainingPlanModal() {
    jQuery('#trainingPlanModal').show();

    // Set default week start date to next Monday
    const nextMonday = getNextMonday();
    jQuery('#week_start').val(nextMonday.toISOString().split('T')[0]);

    // Focus on plan name
    setTimeout(() => {
        jQuery('#plan_name').focus();
    }, 100);
}

function validateTrainingPlanForm() {
    const planName = jQuery('#plan_name').val().trim();
    const menteeId = jQuery('#mentee_id').val();
    const weekStart = jQuery('#week_start').val();

    if (!planName) {
        showNotice('Please enter a plan name', 'error');
        return false;
    }

    if (!menteeId) {
        showNotice('Please select a mentee', 'error');
        return false;
    }

    if (!weekStart) {
        showNotice('Please select a week start date', 'error');
        return false;
    }

    // Check if at least one workout is planned
    let hasWorkout = false;
    jQuery('.workout-type').each(function () {
        if (jQuery(this).val() && jQuery(this).val() !== '') {
            hasWorkout = true;
        }
    });

    if (!hasWorkout) {
        showNotice('Please plan at least one workout for the week', 'warning');
        return false;
    }

    return true;
}

function createPlanForMentee(menteeId) {
    showTrainingPlanModal();
    jQuery('#mentee_id').val(menteeId);
}

function createTrainingPlan(menteeId, coachId) {
    const form = document.getElementById('trainingPlanForm');
    const formData = new FormData(form);


    // Add extra data
    formData.append('action', 'create_training_plan');
    formData.append('security', stravaPublic2.create_training_plan_nonce);
    formData.append('mentee_id', menteeId);
    formData.append('coach_id', coachId); // Optional, if you want to override get_current_user_id()

    showLoading('Creating training plan...');


    alert('inside training plan');

    jQuery.ajax({
        url: stravaPublic2.ajaxurl,
        type: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        success: function (response) {
            hideLoading();
            if (response.success) {
                showNoticemsg('✅ Training plan created successfully!', 'success');
                closeModal('trainingPlanModal');
                setTimeout(() => location.reload(), 1500);
            } else {
                showNoticemsg('❌ ' + (response.data?.message || 'Failed to create plan'), 'error');
            }
        },
        error: function () {
            hideLoading();
            showNoticemsg('❌ Network error occurred', 'error');
        }
    });
}


// Remove mentee
function removeMentee(menteeId, menteeName) {
    if (confirm(`Are you sure you want to remove ${menteeName} as your mentee? This will delete all their training plans and scores.`)) {
        showLoading('Removing mentee...');

        jQuery.post(stravaPublic2.ajaxurl, {
            action: 'remove_mentee',
            mentee_id: menteeId,
            nonce: stravaPublic2.removeMenteeNonce
        }, function (response) {
            hideLoading();
            if (response.success) {
                showNoticemsg('✅ Mentee removed successfully', 'success');
                setTimeout(() => location.reload(), 500);
            } else {
                showNoticemsg('❌ ' + response.data.message, 'error');
            }
        });
    }
}


/**
 * UI UTILITY FUNCTIONS
 */
function showLoading(message = 'Loading...') {
    if (jQuery('#strava-loading-overlay').length === 0) {
        const overlay = jQuery(`
            <div id="strava-loading-overlay" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.7);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 9999;
                color: white;
                font-size: 18px;
            ">
                <div style="text-align: center;">
                    <div class="strava-loading" style="margin-bottom: 20px;"></div>
                    <div>${message}</div>
                </div>
            </div>
        `);

        jQuery('body').append(overlay);
    }
}

function hideLoading() {
    jQuery('#strava-loading-overlay').fadeOut(() => {
        jQuery('#strava-loading-overlay').remove();
    });
}


/**
 * MENTEE MANAGEMENT FUNCTIONALITY
 */
function initializeMenteeManagement() {
    let searchTimeout;

    // Mentee search functionality
    jQuery(document).on('input', '#mentee_search', function () {        
        clearTimeout(searchTimeout);
        const query = jQuery(this).val();

        if (query.length < 2) {
            jQuery('#mentee_search_results').html('');
            return;
        }

        searchTimeout = setTimeout(() => {
            searchUsers(query);
        }, 300);
    });
}

function showAddMenteeModal() {
    jQuery('#addMenteeModal').show();
    setTimeout(() => {
        jQuery('#mentee_search').focus();
    }, 100);
}

/**
 * MODAL MANAGEMENT
 */
function closeModal(modalId) {
    jQuery('#' + modalId).hide();

    // Reset form if it's a form modal
    const form = jQuery('#' + modalId).find('form');
    if (form.length) {
        form[0].reset();
        // Hide all workout details
        jQuery('#' + modalId).find('.workout-details').hide();
    }

    // Remove dynamically created modals
    if (modalId === 'scoreActivityModal' || modalId === 'progressModal') {
        jQuery('#' + modalId).remove();
    }
}

function sendInvitation() {
    const email = jQuery('#invite_email').val();
    if (!email) {
        showNoticemsg('Please enter an email address', 'warning');
        return;
    }

    showNoticemsg('Email invitation feature coming next!', 'info');
}



// Add Mentee & Search

function searchUsers(query) {
    if (query.length < 2) {
        jQuery('#mentee_search_results').html('');
        return;
    }

    jQuery.post(stravaPublic2.ajaxurl, {
        action: 'search_users_for_mentee',
        query: query,
        nonce: stravaPublic2.searchNonce
    }, function (response) {
        if (response.success) {
            displaySearchResults(response.data.users);
        } else {
            jQuery('#mentee_search_results').html('<div class="no-results">Search failed</div>');
        }
    }).fail(function () {
        jQuery('#mentee_search_results').html('<div class="no-results">Network error</div>');
    });
}

function displaySearchResults(users) {
    const resultsDiv = jQuery('#mentee_search_results');

    if (users.length === 0) {
        resultsDiv.html('<div class="no-results">No users found</div>');
        return;
    }

    let html = '<div class="search-results-list">';
    users.forEach(user => {
        html += `
            <div class="search-result-item" onclick="addUserAsMentee(${user.ID})">
                <img src="${user.avatar}" alt="${user.display_name}" class="search-avatar">
                <div class="search-info">
                    <div class="search-name">${user.display_name}</div>
                    <div class="search-email">${user.user_email}</div>
                </div>
                <button class="btn-small btn-primary" type="button">Add</button>
            </div>
        `;
    });
    html += '</div>';

    resultsDiv.html(html);
}

function addUserAsMentee(userId) {
    closeModal('addMenteeModal');
    showLoading('Adding mentee...');

    jQuery.post(stravaPublic2.ajaxurl, {
        action: 'add_mentee',
        mentee_id: userId,
        nonce: stravaPublic2.addMenteeNonce
    }, function (response) {
        hideLoading();
        if (response.success) {
            showNoticemsg('✅ Mentee added successfully!', 'success');
            closeModal('addMenteeModal');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNoticemsg('❌ ' + response.data.message, 'error');
        }
    }).fail(function () {
        hideLoading();
        showNoticemsg('❌ Network error occurred', 'error');
    });
}


/**
 * PROGRESS TRACKING FUNCTIONALITY
 */
function viewMenteeProgress(menteeId) {
    showLoading('Loading mentee progress...');

    jQuery.post(stravaPublic2.ajaxurl, {
        action: 'get_mentee_progress',
        mentee_id: menteeId,
        nonce: stravaPublic2.progressNonce
    }, function (response) {
        hideLoading();
        if (response.success) {
            showProgressModal(response.data);
        } else {
            showNoticemsg('❌ Failed to load progress data', 'error');
        }
    }).fail(function () {
        hideLoading();
        showNoticemsg('❌ Network error occurred', 'error');
    });
}

function showProgressModal(progressData) {
    const modal = createProgressModal(progressData);
    jQuery('body').append(modal);
    jQuery('#progressModal').show();
}

function createProgressModal(data) {
    return `
        <div id="progressModal" class="strava-modal">
            <div class="modal-content large-modal">
                <div class="modal-header">
                    <h2>📊 ${data.mentee_info.display_name}'s Progress</h2>
                    <button class="modal-close" onclick="closeProgressModal()">&times;</button>
                </div>
                
                <div class="modal-body">
                    ${generateProgressContent(data)}
                </div>
                
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeProgressModal()">Close</button>
                    <button class="btn-strava" onclick="generateProgressReport(${data.mentee_info.ID})">
                        📊 Generate Report
                    </button>
                </div>
            </div>
        </div>
    `;
}

function generateProgressContent(data) {
    let content = '<div class="progress-tabs">';

    // Weekly Stats
    content += '<div class="progress-section">';
    content += '<h3>📈 Weekly Statistics</h3>';
    content += '<div class="stats-grid">';
    content += `<div class="stat-item"><span class="stat-label">Activities:</span><span class="stat-value">${data.weekly_stats.activity_count || 0}</span></div>`;
    content += `<div class="stat-item"><span class="stat-label">Distance:</span><span class="stat-value">${(data.weekly_stats.total_distance / 1000).toFixed(1)} km</span></div>`;
    content += `<div class="stat-item"><span class="stat-label">Total Time:</span><span class="stat-value">${formatDuration(data.weekly_stats.total_time || 0)}</span></div>`;
    content += `<div class="stat-item"><span class="stat-label">Avg Speed:</span><span class="stat-value">${(data.weekly_stats.avg_speed || 0).toFixed(2)} m/s</span></div>`;
    content += '</div>';
    content += '</div>';

    // Recent Activities
    content += '<div class="progress-section">';
    content += '<h3>🏃‍♂️ Recent Activities</h3>';
    content += '<div class="activities-table">';

    if (data.activities && data.activities.length > 0) {
        content += '<table class="progress-activities-table">';
        content += '<thead><tr><th>Date</th><th>Activity</th><th>Distance</th><th>Time</th><th>Score</th><th>Action</th></tr></thead>';
        content += '<tbody>';

        data.activities.forEach(activity => {
            const date = new Date(activity.start_date).toLocaleDateString();
            const distance = activity.distance ? (activity.distance / 1000).toFixed(1) + ' km' : '--';
            const time = activity.moving_time ? formatDuration(activity.moving_time) : '--';
            const score = activity.overall_score ? activity.overall_score + '/10' : 'Unscored';
            const scoreClass = activity.overall_score ? getScoreClass(activity.overall_score) : 'unscored';

            content += `
                <tr>
                    <td>${date}</td>
                    <td>${getActivityIcon(activity.activity_type)} ${activity.name}</td>
                    <td>${distance}</td>
                    <td>${time}</td>
                    <td><span class="score-badge ${scoreClass}">${score}</span></td>
                    <td>
                        ${!activity.overall_score ?
                    `<button class="btn-mini" onclick="scoreActivityModal(${activity.id})">⭐ Score</button>` :
                    `<button class="btn-mini" onclick="editActivityScore(${activity.id})">✏️ Edit</button>`
                }
                    </td>
                </tr>
            `;
        });

        content += '</tbody></table>';
    } else {
        content += '<div class="empty-state">No recent activities found</div>';
    }

    content += '</div>';
    content += '</div>';

    return content;
}

function closeProgressModal() {
    jQuery('#progressModal').remove();
}

/**
 * UTILITY FUNCTIONS
 */
function getNextMonday() {
    const today = new Date();
    const dayOfWeek = today.getDay();
    const daysUntilMonday = dayOfWeek === 0 ? 1 : 8 - dayOfWeek; // 0 = Sunday
    const nextMonday = new Date(today);
    nextMonday.setDate(today.getDate() + daysUntilMonday);
    return nextMonday;
}

function formatDuration(seconds) {
    if (!seconds) return '0:00';

    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;

    if (hours > 0) {
        return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    } else {
        return `${minutes}:${secs.toString().padStart(2, '0')}`;
    }
}

function getActivityIcon(activityType) {
    const icons = {
        'Run': '🏃‍♂️',
        'Ride': '🚴‍♂️',
        'Swim': '🏊‍♂️',
        'Walk': '🚶‍♂️',
        'Hike': '🥾'
    };
    return icons[activityType] || '🏃‍♂️';
}

function getScoreClass(score) {
    if (score >= 8) return 'score-excellent';
    if (score >= 6) return 'score-good';
    if (score >= 4) return 'score-average';
    return 'score-poor';
}