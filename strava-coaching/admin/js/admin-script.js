/**
 * Enhanced Admin JavaScript for Strava Coaching Plugin
 * File: admin/js/admin-script.js
 * 
 * REPLACE YOUR EXISTING admin-script.js with this enhanced version
 */

jQuery(document).ready(function ($) {
    console.log('Strava Coaching Admin JS loaded - Phase 3');

    // Show success/error messages based on URL parameters
    const urlParams = new URLSearchParams(window.location.search);

    if (urlParams.get('connected') === '1') {
        showNotice('🎉 Successfully connected to Strava! Your activities are being synced.', 'success');
    }

    if (urlParams.get('error') === '1') {
        showNotice('❌ Failed to connect to Strava. Please try again.', 'error');
    }

    // Initialize all functionality
    initializeTrainingPlanCreator();
    initializeMenteeManagement();
    initializeActivityScoring();
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

function searchUsers(query) {
    if (query.length < 2) {
        jQuery('#mentee_search_results').html('');
        return;
    }

    jQuery.post(ajaxurl, {
        action: 'search_users_for_mentee',
        query: query,
        nonce: stravaCoaching.searchNonce
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
    showLoading('Adding mentee...');

    jQuery.post(ajaxurl, {
        action: 'add_mentee',
        mentee_id: userId,
        nonce: stravaCoaching.addMenteeNonce
    }, function (response) {
        hideLoading();
        if (response.success) {
            showNotice('✅ Mentee added successfully!', 'success');
            closeModal('addMenteeModal');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotice('❌ ' + response.data.message, 'error');
        }
    }).fail(function () {
        hideLoading();
        showNotice('❌ Network error occurred', 'error');
    });
}

function removeMentee(menteeId, menteeName) {
    if (confirm(`Are you sure you want to remove ${menteeName} as your mentee? This will delete all their training plans and scores.`)) {
        showLoading('Removing mentee...');

        jQuery.post(ajaxurl, {
            action: 'remove_mentee',
            mentee_id: menteeId,
            nonce: stravaCoaching.removeMenteeNonce
        }, function (response) {
            hideLoading();
            if (response.success) {
                showNoticemsg('✅ Mentee removed successfully', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showNoticemsg('❌ ' + response.data.message, 'error');
            }
        });
    }
}

function sendInvitation() {
    const email = jQuery('#invite_email').val();
    if (!email) {
        showNotice('Please enter an email address', 'warning');
        return;
    }

    showNotice('Email invitation feature coming next!', 'info');
}

/**
 * ACTIVITY SCORING FUNCTIONALITY
 */
function initializeActivityScoring() {
    // Score range validation
    jQuery(document).on('input', '.score-input', function () {
        const value = parseInt(jQuery(this).val());
        if (value < 1 || value > 10) {
            jQuery(this).addClass('invalid');
        } else {
            jQuery(this).removeClass('invalid');
        }
    });
}

function scoreActivity(activityId) {
    scoreActivityModal(activityId);
}

function scoreActivities(menteeId) {
    showNotice('Activity scoring interface coming next!', 'info');
}

function scoreActivityModal(activityId) {
    const modal = `
        <div id="scoreActivityModal" class="strava-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>⭐ Score Activity</h2>
                    <button class="modal-close" onclick="closeModal('scoreActivityModal')">&times;</button>
                </div>
                
                <form id="scoreActivityForm">
                    <div class="modal-body">
                        <div class="score-section">
                            <h3>Rate Performance (1-10)</h3>
                            
                            <div class="score-group">
                                <label for="pace_score">🏃‍♂️ Pace Score</label>
                                <input type="number" id="pace_score" name="pace_score" min="1" max="10" required class="score-input">
                                <span class="score-help">Rate the pace relative to target</span>
                            </div>
                            
                            <div class="score-group">
                                <label for="distance_score">📏 Distance Score</label>
                                <input type="number" id="distance_score" name="distance_score" min="1" max="10" required class="score-input">
                                <span class="score-help">Rate distance completion</span>
                            </div>
                            
                            <div class="score-group">
                                <label for="heart_rate_score">❤️ Heart Rate Score</label>
                                <input type="number" id="heart_rate_score" name="heart_rate_score" min="1" max="10" required class="score-input">
                                <span class="score-help">Rate heart rate management</span>
                            </div>
                            
                            <div class="score-group">
                                <label for="score_comments">💬 Comments</label>
                                <textarea id="score_comments" name="comments" rows="3" placeholder="Feedback and encouragement..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('scoreActivityModal')">Cancel</button>
                        <button type="submit" class="btn-strava">Save Score</button>
                    </div>
                </form>
            </div>
        </div>
    `;

    jQuery('body').append(modal);
    jQuery('#scoreActivityModal').show();

    // Handle form submission
    jQuery('#scoreActivityForm').on('submit', function (e) {
        e.preventDefault();
        saveActivityScore(activityId);
    });
}

function saveActivityScore(activityId) {
    const formData = {
        action: 'score_activity',
        activity_id: activityId,
        pace_score: jQuery('#pace_score').val(),
        distance_score: jQuery('#distance_score').val(),
        heart_rate_score: jQuery('#heart_rate_score').val(),
        comments: jQuery('#score_comments').val(),
        nonce: stravaCoaching.scoreNonce
    };

    showLoading('Saving activity score...');

    jQuery.post(ajaxurl, formData, function (response) {
        hideLoading();
        if (response.success) {
            showNotice('✅ Activity scored successfully!', 'success');
            closeModal('scoreActivityModal');
            // Refresh progress modal if open
            if (jQuery('#progressModal').is(':visible')) {
                const menteeId = jQuery('#progressModal').data('mentee-id');
                if (menteeId) {
                    setTimeout(() => viewMenteeProgress(menteeId), 1000);
                }
            }
        } else {
            showNotice('❌ ' + response.data.message, 'error');
        }
    }).fail(function () {
        hideLoading();
        showNotice('❌ Network error occurred', 'error');
    });
}

/**
 * PROGRESS TRACKING FUNCTIONALITY
 */
function viewMenteeProgress(menteeId) {
    showLoading('Loading mentee progress...');

    jQuery.post(ajaxurl, {
        action: 'get_mentee_progress',
        mentee_id: menteeId,
        nonce: stravaCoaching.progressNonce
    }, function (response) {
        hideLoading();
        if (response.success) {
            showProgressModal(response.data);
        } else {
            showNotice('❌ Failed to load progress data', 'error');
        }
    }).fail(function () {
        hideLoading();
        showNotice('❌ Network error occurred', 'error');
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

/**
 * EXISTING FUNCTIONALITY FROM PHASE 2
 */
function syncMyActivities() {
    showLoading('Syncing activities from Strava...');

    jQuery.post(ajaxurl, {
        action: 'sync_strava_activities'
    }, function (response) {
        hideLoading();
        if (response.success) {
            showNotice('✅ ' + response.data.message, 'success');
            setTimeout(() => location.reload(), 2000);
        } else {
            showNotice('❌ ' + (response.data.message || 'Failed to sync activities'), 'error');
        }
    }).fail(function () {
        hideLoading();
        showNotice('❌ Network error occurred', 'error');
    });
}

/**
 * PLACEHOLDER FUNCTIONS FOR FUTURE PHASES
 */
function viewFullPlan(planId) {
    showNotice('Full plan viewer coming in next update!', 'info');
}

function generateReports() {
    showNotice('Report generation feature coming next!', 'info');
}

function generateProgressReport(menteeId) {
    showNotice('Progress report generation coming next!', 'info');
}

function showPlanTemplates() {
    showNotice('Plan templates feature coming next!', 'info');
}

function editActivityScore(activityId) {
    showNotice('Edit score functionality coming next!', 'info');
}

function syncAllData() {
    if (confirm('This will sync data for all your mentees. This may take a few minutes. Continue?')) {
        showLoading('Syncing data for all mentees...');

        setTimeout(() => {
            hideLoading();
            showNotice('Bulk sync feature coming in next update!', 'info');
        }, 2000);
    }
}

function viewAllActivities() {
    showNotice('Activity management coming in next phase!', 'info');
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

function showNotice(message, type = 'info') {
    const icons = {
        'success': '✅',
        'error': '❌',
        'warning': '⚠️',
        'info': 'ℹ️'
    };

    const noticeClass = type === 'success' ? 'notice-success' :
        type === 'error' ? 'notice-error' :
            type === 'warning' ? 'notice-warning' : 'notice-info';

    const notice = jQuery(`
        <div class="notice ${noticeClass} is-dismissible strava-notice">
            <p>${icons[type]} ${message}</p>
        </div>
    `);

    jQuery('.wrap h1').after(notice);

    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        notice.fadeOut(() => notice.remove());
    }, 5000);
}