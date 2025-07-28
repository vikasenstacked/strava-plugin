// Dashboard JS for Activity Coach System
// Chart.js integration and interactivity will go here

console.log('ACS Dashboard JS loaded successfully');

// Ensure functions are available immediately
(function() {
  console.log('Initializing ACS Dashboard functions...');
  
  // Make functions globally accessible
  window.viewMenteeAnalytics = function(menteeId) {
    console.log('viewMenteeAnalytics called with:', menteeId);
    const analyticsContent = document.getElementById('mentee-analytics-content');
    if (!menteeId) {
      if (analyticsContent) analyticsContent.innerHTML = '<p style="margin:0;">Please select a mentee to display the data.</p>';
      return;
    }
    const formData = new FormData();
    formData.append('action', 'acs_get_mentee_analytics');
    formData.append('nonce', getAjaxNonce());
    formData.append('mentee_id', menteeId);
    if (analyticsContent) analyticsContent.innerHTML = '<p>Loading analytics...</p>';
    fetch(getAjaxUrl(), {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        let content = '<div style="margin-bottom: 1rem;">';
        content += `<h4 style='margin-top:0;'>Analytics Overview</h4>`;
        content += `<p><strong>Recent Activities:</strong> ${data.data.total_activities}</p>`;
        content += '<div style="margin: 2rem 0;">';
        content += '<h5 style="color: #3b4051; margin-bottom: 1rem;">Activity Charts</h5>';
        content += '<div style="display: flex; flex-wrap: wrap; gap: 1rem;">';
        content += '<div style="flex: 1; min-width: 250px; height: 200px;"><canvas id="menteeDistanceChart"></canvas></div>';
        content += '<div style="flex: 1; min-width: 250px; height: 200px;"><canvas id="menteeTypeChart"></canvas></div>';
        content += '<div style="flex: 1; min-width: 250px; height: 200px;"><canvas id="menteePaceChart"></canvas></div>';
        content += '</div>';
        content += '</div>';
        if (data.data.activities.length > 0) {
          content += '<h5 style="color: #3b4051; margin-bottom: 1rem;">Recent Activities</h5>';
          content += '<table style="width: 100%; border-collapse: collapse;">';
          content += '<thead><tr style="background: #fafbfc;"><th>Date</th><th>Type</th><th>Distance</th><th>Duration</th><th>Pace</th></tr></thead><tbody>';
          data.data.activities.forEach(activity => {
            content += `<tr style="border-bottom: 1px solid #eee;">
              <td>${activity.date}</td>
              <td>${activity.type}</td>
              <td>${activity.distance} km</td>
              <td>${activity.duration} min</td>
              <td>${activity.pace} min/km</td>
            </tr>`;
          });
          content += '</tbody></table>';
        } else {
          content += '<p>No recent activities found.</p>';
        }
        content += '</div>';
        if (analyticsContent) analyticsContent.innerHTML = content;
        // Render charts after content is injected
        setTimeout(function() {
          if (typeof Chart !== 'undefined' && data.data.chart_data) {
            var chartData = data.data.chart_data;
            var primary = '#ff6124';
            var text = '#3b4051';
            // Distance Chart
            var ctxDist = document.getElementById('menteeDistanceChart');
            if (ctxDist && chartData.dates && chartData.distances) {
              new Chart(ctxDist, {
                type: 'line',
                data: {
                  labels: chartData.dates,
                  datasets: [{
                    label: 'Distance (km)',
                    data: chartData.distances,
                    borderColor: primary,
                    backgroundColor: 'rgba(255,97,36,0.08)',
                    pointBackgroundColor: primary,
                    pointBorderColor: primary,
                    tension: 0.3,
                    fill: true
                  }]
                },
                options: {
                  plugins: { legend: { labels: { color: text } } },
                  scales: {
                    x: { ticks: { color: text }, grid: { color: '#eee' } },
                    y: { ticks: { color: text }, grid: { color: '#eee' } }
                  },
                  responsive: true,
                  maintainAspectRatio: false
                }
              });
            }
            // Type Chart
            var ctxType = document.getElementById('menteeTypeChart');
            if (ctxType && chartData.type_labels && chartData.type_data) {
              new Chart(ctxType, {
                type: 'bar',
                data: {
                  labels: chartData.type_labels,
                  datasets: [{
                    label: 'Activity Count',
                    data: chartData.type_data,
                    backgroundColor: primary,
                    borderRadius: 8
                  }]
                },
                options: {
                  plugins: { legend: { display: false } },
                  scales: {
                    x: { ticks: { color: text }, grid: { color: '#eee' } },
                    y: { ticks: { color: text }, grid: { color: '#eee' } }
                  },
                  responsive: true,
                  maintainAspectRatio: false
                }
              });
            }
            // Pace Chart
            var ctxPace = document.getElementById('menteePaceChart');
            if (ctxPace && chartData.dates && chartData.paces) {
              new Chart(ctxPace, {
                type: 'line',
                data: {
                  labels: chartData.dates,
                  datasets: [{
                    label: 'Pace (min/km)',
                    data: chartData.paces,
                    borderColor: primary,
                    backgroundColor: 'rgba(255,97,36,0.08)',
                    pointBackgroundColor: primary,
                    pointBorderColor: primary,
                    tension: 0.3,
                    fill: true
                  }]
                },
                options: {
                  plugins: { legend: { labels: { color: text } } },
                  scales: {
                    x: { ticks: { color: text }, grid: { color: '#eee' } },
                    y: { ticks: { color: text }, grid: { color: '#eee' } }
                  },
                  responsive: true,
                  maintainAspectRatio: false
                }
              });
            }
          }
        }, 100);
      } else {
        if (analyticsContent) analyticsContent.innerHTML = '<p style="color:red;">Failed to load analytics.</p>';
      }
    })
    .catch(error => {
      if (analyticsContent) analyticsContent.innerHTML = '<p style="color:red;">Error loading analytics.</p>';
    });
  };

  window.createPlanFor = function(menteeId) {
    console.log('createPlanFor called with:', menteeId);
    // Pre-select the mentee in the plan creation form
    const menteeSelect = document.querySelector('select[name="mentee_id"]');
    if (menteeSelect) {
      menteeSelect.value = menteeId;
      // Scroll to plan creation section
      const planSection = document.querySelector('h3');
      if (planSection && planSection.textContent.includes('Create Training Plan')) {
        planSection.scrollIntoView({ behavior: 'smooth' });
      }
    }
  };

  window.scorePlan = function(planId, prefill) {
    console.log('scorePlan called with:', planId, prefill);
    // Use labels from acsFeedbackLabels if available, else fallback
    const metricLabels = (typeof acsFeedbackLabels !== 'undefined') ? acsFeedbackLabels : [
      'Pace', 'Distance', 'Consistency', 'Elevation'
    ];
    prefill = prefill || {};
    const content = `
      <div>
        ${metricLabels.map((label, i) => `
          <div style='margin-bottom: 1rem;'>
            <label style='display: block; font-weight: 600;'>${label} (0-10):</label>
            <input type='number' id='metric${i+1}' min='0' max='10' style='width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 6px;' value='${prefill['metric'+(i+1)] !== undefined ? prefill['metric'+(i+1)] : ''}'>
          </div>
        `).join('')}
        <div style="margin-bottom: 1rem;">
          <label style="display: block; font-weight: 600;">Coach Feedback / Notes:</label>
          <textarea id="planFeedback" rows="3" style="width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 6px;">${prefill.feedback !== undefined ? prefill.feedback : ''}</textarea>
        </div>
      </div>
    `;
    const modal = showModal('Score Training Plan', content, [
      { text: 'Cancel', onclick: 'this.closest(".modal").remove()' },
      { text: 'Submit Score', primary: true, onclick: `submitPlanScore(${planId})` }
    ]);
  };

  window.trackProgress = function(planId) {
    console.log('trackProgress called with:', planId);
    const formData = new FormData();
    formData.append('action', 'acs_track_progress');
    formData.append('nonce', getAjaxNonce());
    formData.append('plan_id', planId);
    
    fetch(getAjaxUrl(), {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const progress = data.data;
        const content = `
          <div>
            <h4 style="margin-top: 0;">${progress.plan_title}</h4>
            <p><strong>Week:</strong> ${progress.week_period}</p>
            
            <div style="margin: 1rem 0;">
              <h5>Distance Progress</h5>
              <div style="background: #f8f9fa; border-radius: 8px; padding: 1rem; margin-bottom: 0.5rem;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                  <span>Target: ${progress.target_distance} km</span>
                  <span>Actual: ${progress.actual_distance} km</span>
                </div>
                <div style="background: #e9ecef; border-radius: 4px; height: 8px;">
                  <div style="background: #ff6124; height: 100%; border-radius: 4px; width: ${Math.min(progress.distance_progress, 100)}%;"></div>
                </div>
                <div style="text-align: center; margin-top: 0.5rem; font-weight: 600;">${progress.distance_progress}%</div>
              </div>
            </div>
            
            <div style="margin: 1rem 0;">
              <h5>Duration Progress</h5>
              <div style="background: #f8f9fa; border-radius: 8px; padding: 1rem; margin-bottom: 0.5rem;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                  <span>Target: ${progress.target_duration} min</span>
                  <span>Actual: ${progress.actual_duration} min</span>
                </div>
                <div style="background: #e9ecef; border-radius: 4px; height: 8px;">
                  <div style="background: #ff6124; height: 100%; border-radius: 4px; width: ${Math.min(progress.duration_progress, 100)}%;"></div>
                </div>
                <div style="text-align: center; margin-top: 0.5rem; font-weight: 600;">${progress.duration_progress}%</div>
              </div>
            </div>
            
            <p><strong>Activities Completed:</strong> ${progress.activities_completed}</p>
          </div>
        `;
        
        showModal('Progress Tracking', content, [
          { text: 'Close', onclick: 'this.closest(".modal").remove()' }
        ]);
      } else {
        showNotification(data.data || 'Failed to load progress', 'error');
      }
    })
    .catch(error => {
      console.error('Error:', error);
      showNotification('Error loading progress', 'error');
    });
  };

  window.submitPlanScore = function(planId) {
    console.log('submitPlanScore called with:', planId);
    const metrics = [];
    for (let i = 1; i <= 4; i++) {
      metrics.push(document.getElementById('metric'+i).value);
    }
    const feedback = document.getElementById('planFeedback').value;
    // Validate all scores are 0-10
    if (metrics.some(s => s === '' || isNaN(s) || s < 0 || s > 10)) {
      showNotification('Please enter valid scores (0-10) for all metrics', 'error');
      return;
    }
    const formData = new FormData();
    formData.append('action', 'acs_score_plan');
    formData.append('nonce', getAjaxNonce());
    formData.append('plan_id', planId);
    formData.append('pace_score', metrics[0]);
    formData.append('distance_score', metrics[1]);
    formData.append('consistency_score', metrics[2]);
    formData.append('elevation_score', metrics[3]);
    formData.append('feedback', feedback);
    // Calculate average for overall score
    const avg = metrics.reduce((a, b) => parseFloat(a) + parseFloat(b), 0) / 4;
    formData.append('score', avg.toFixed(2));
    fetch(getAjaxUrl(), {
      method: 'POST',
      body: formData
    })
    .then(response => {
      // Try to parse JSON, handle non-JSON (e.g., Unauthorized)
      return response.text().then(text => {
        try {
          return JSON.parse(text);
        } catch (e) {
          throw new Error(text);
        }
      });
    })
    .then(data => {
      if (data && data.success) {
        showNotification(data.data.message);
        // Close modal and refresh page to show updated score
        const modal = document.querySelector('.modal');
        if (modal) modal.remove();
        setTimeout(() => location.reload(), 1000);
      } else {
        showNotification((data && data.data) || 'Failed to submit score', 'error');
      }
    })
    .catch(error => {
      showNotification('Error submitting score: ' + error.message, 'error');
    });
  };

  // Add Remove Mentee to window
  window.removeMentee = function(menteeId) {
    if (!confirm('Are you sure you want to remove this mentee?')) return;
    const formData = new FormData();
    formData.append('action', 'acs_remove_mentee');
    formData.append('nonce', getAjaxNonce());
    formData.append('mentee_id', menteeId);
    fetch(getAjaxUrl(), {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showNotification('Mentee removed successfully');
        const row = document.getElementById('mentee-row-' + menteeId);
        if (row) row.remove();
      } else {
        showNotification(data.data || 'Failed to remove mentee', 'error');
      }
    })
    .catch(error => {
      showNotification('Error removing mentee', 'error');
    });
  };

  console.log('ACS Dashboard functions initialized successfully');
  console.log('Available functions:', {
    viewMenteeAnalytics: typeof window.viewMenteeAnalytics,
    createPlanFor: typeof window.createPlanFor,
    scorePlan: typeof window.scorePlan,
    trackProgress: typeof window.trackProgress,
    submitPlanScore: typeof window.submitPlanScore
  });
})();

// AJAX Helper Functions
function getAjaxNonce() {
  return typeof acsAjaxData !== 'undefined' ? acsAjaxData.nonce : '';
}

function getAjaxUrl() {
  return typeof acsAjaxData !== 'undefined' ? acsAjaxData.ajaxurl : '';
}

function showNotification(message, type = 'success') {
  const notification = document.createElement('div');
  notification.style.cssText = `
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    color: white;
    font-weight: 600;
    z-index: 10000;
    background: ${type === 'success' ? '#ff6124' : '#dc3545'};
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transform: translateX(100%);
    transition: transform 0.3s ease;
  `;
  notification.textContent = message;
  document.body.appendChild(notification);
  
  setTimeout(() => notification.style.transform = 'translateX(0)', 100);
  setTimeout(() => {
    notification.style.transform = 'translateX(100%)';
    setTimeout(() => document.body.removeChild(notification), 300);
  }, 3000);
}

function showModal(title, content, buttons = []) {
  const modal = document.createElement('div');
  modal.className = 'modal'; // Ensure modal can be closed by Cancel button
  modal.style.cssText = `
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
  `;
  
  modal.innerHTML = `
    <div style="
      background: white;
      border-radius: 12px;
      padding: 2rem;
      max-width: 500px;
      width: 90%;
      max-height: 80vh;
      overflow-y: auto;
      box-shadow: 0 8px 32px rgba(0,0,0,0.1);
    ">
      <h3 style="margin-top: 0; color: #3b4051;">${title}</h3>
      <div style="margin-bottom: 1.5rem;">${content}</div>
      <div style="display: flex; gap: 1rem; justify-content: flex-end;">
        ${buttons.map(btn => `
          <button onclick="${btn.onclick}" style="
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            ${btn.primary ? 'background: #ff6124; color: white;' : 'background: #f8f9fa; color: #3b4051;'}
          ">${btn.text}</button>
        `).join('')}
      </div>
    </div>
  `;
  
  document.body.appendChild(modal);
  
  // Close on background click
  modal.addEventListener('click', (e) => {
    if (e.target === modal) {
      document.body.removeChild(modal);
    }
  });
  
  return modal;
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function () {
  if (typeof acsChartData === 'undefined' || typeof Chart === 'undefined') return;

  // Brand colors
  const primary = '#ff6124';
  const text = '#3b4051';
  const bg = '#fff';

  // Distance over time (Line)
  const ctxDist = document.getElementById('acsDistanceChart');
  if (ctxDist) {
    new Chart(ctxDist, {
      type: 'line',
      data: {
        labels: acsChartData.dates,
        datasets: [{
          label: 'Distance (km)',
          data: acsChartData.distances,
          borderColor: primary,
          backgroundColor: 'rgba(255,97,36,0.08)',
          pointBackgroundColor: primary,
          pointBorderColor: primary,
          tension: 0.3,
          fill: true,
        }]
      },
      options: {
        plugins: {
          legend: { labels: { color: text } }
        },
        scales: {
          x: { ticks: { color: text }, grid: { color: '#eee' } },
          y: { ticks: { color: text }, grid: { color: '#eee' } }
        },
        responsive: true,
        maintainAspectRatio: false,
      }
    });
  }

  // Activity type breakdown (Bar)
  const ctxType = document.getElementById('acsTypeChart');
  if (ctxType) {
    new Chart(ctxType, {
      type: 'bar',
      data: {
        labels: acsChartData.type_labels,
        datasets: [{
          label: 'Activity Count',
          data: acsChartData.type_data,
          backgroundColor: primary,
          borderRadius: 8,
        }]
      },
      options: {
        plugins: {
          legend: { display: false },
        },
        scales: {
          x: { ticks: { color: text }, grid: { color: '#eee' } },
          y: { ticks: { color: text }, grid: { color: '#eee' } }
        },
        responsive: true,
        maintainAspectRatio: false,
      }
    });
  }

  // Pace trend (Line)
  const ctxPace = document.getElementById('acsPaceChart');
  if (ctxPace) {
    new Chart(ctxPace, {
      type: 'line',
      data: {
        labels: acsChartData.dates,
        datasets: [{
          label: 'Pace (min/km)',
          data: acsChartData.paces,
          borderColor: primary,
          backgroundColor: 'rgba(255,97,36,0.08)',
          pointBackgroundColor: primary,
          pointBorderColor: primary,
          tension: 0.3,
          fill: true,
        }]
      },
      options: {
        plugins: {
          legend: { labels: { color: text } }
        },
        scales: {
          x: { ticks: { color: text }, grid: { color: '#eee' } },
          y: { ticks: { color: text }, grid: { color: '#eee' } }
        },
        responsive: true,
        maintainAspectRatio: false,
      }
    });
  }

  // Initialize AJAX functionality
  initAjaxHandlers();
  
  // Debug: Verify functions are globally accessible
  console.log('Global functions defined:', {
    viewMenteeAnalytics: typeof window.viewMenteeAnalytics,
    createPlanFor: typeof window.createPlanFor,
    scorePlan: typeof window.scorePlan,
    trackProgress: typeof window.trackProgress,
    submitPlanScore: typeof window.submitPlanScore
  });
});

// Event delegation for Score, Remove Mentee, and Apply Analytics buttons
// This must be outside the IIFE to ensure it works even if DOM is loaded after script

document.addEventListener('click', function(e) {
  // Score/Edit Score button
  if (e.target && e.target.classList.contains('acs-score-btn')) {
    e.preventDefault();
    const planId = e.target.getAttribute('data-plan-id');
    // Pre-fill if editing
    const prefill = {};
    if (e.target.hasAttribute('data-score')) prefill.metric1 = e.target.getAttribute('data-pace');
    if (e.target.hasAttribute('data-distance')) prefill.metric2 = e.target.getAttribute('data-distance');
    if (e.target.hasAttribute('data-consistency')) prefill.metric3 = e.target.getAttribute('data-consistency');
    if (e.target.hasAttribute('data-elevation')) prefill.metric4 = e.target.getAttribute('data-elevation');
    if (e.target.hasAttribute('data-feedback')) prefill.feedback = e.target.getAttribute('data-feedback');
    window.scorePlan(planId, prefill);
  }
  // Remove mentee button
  if (e.target && e.target.classList.contains('acs-remove-mentee-btn')) {
    e.preventDefault();
    const menteeId = e.target.getAttribute('data-mentee-id');
    if (menteeId && typeof window.removeMentee === 'function') {
      window.removeMentee(menteeId);
    }
  }
  // Apply analytics button
  if (e.target && e.target.classList.contains('acs-apply-analytics-btn')) {
    e.preventDefault();
    const select = document.getElementById('mentee-analytics-select');
    if (select && typeof window.viewMenteeAnalytics === 'function') {
      window.viewMenteeAnalytics(select.value);
    }
  }
});

function initAjaxHandlers() {
  // Handle mentee assignment form
  const assignForm = document.querySelector('form[data-action="assign_mentee"]');
  if (assignForm) {
    assignForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const formData = new FormData(this);
      formData.append('action', 'acs_assign_mentee');
      formData.append('nonce', getAjaxNonce());
      
      fetch(getAjaxUrl(), {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showNotification(data.data.message);
          setTimeout(() => location.reload(), 1000);
        } else {
          showNotification(data.data || 'Failed to assign mentee', 'error');
        }
      })
      .catch(error => {
        console.error('Error:', error);
        showNotification('Error assigning mentee', 'error');
      });
    });
  }
  
  // Handle plan creation form
  const planForm = document.querySelector('form[data-action="create_plan"]');
  if (planForm) {
    planForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const formData = new FormData(this);
      formData.append('action', 'acs_create_plan');
      formData.append('nonce', getAjaxNonce());
      
      fetch(getAjaxUrl(), {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showNotification(data.data.message);
          setTimeout(() => location.reload(), 1000);
        } else {
          showNotification(data.data || 'Failed to create plan', 'error');
        }
      })
      .catch(error => {
        console.error('Error:', error);
        showNotification('Error creating plan', 'error');
      });
    });
  }
}
