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
