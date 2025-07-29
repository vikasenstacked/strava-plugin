# Activity Coach System

A comprehensive WordPress plugin for fitness coaching with Coach and Mentee dashboards, Strava integration, activity tracking, and analytics.

## ✅ Completed Features

### Core System
- ✅ Custom user roles (Coach & Mentee)
- ✅ Database tables for relationships, activities, plans, and scores
- ✅ WordPress Settings API integration
- ✅ Frontend shortcodes for dashboards

### Strava Integration
- ✅ OAuth 2.0 authentication flow
- ✅ Token refresh handling
- ✅ Activity synchronization (last 30 days)
- ✅ Secure token storage

### Mentee Dashboard
- ✅ Strava connection status
- ✅ Manual activity sync
- ✅ Recent activities table
- ✅ **Analytics with Chart.js:**
  - Distance over time trends
  - Activity type breakdown
  - Pace performance tracking
- ✅ Training plan viewing
- ✅ Progress tracking interface

### Coach Dashboard
- ✅ **Mentee Management:**
  - Assign/unassign mentees
  - View mentee list with contact info
  - Quick access to mentee analytics
- ✅ **Training Plan Creation:**
  - Create weekly plans for mentees
  - Set activity targets (distance, duration, pace)
  - Plan notes and customization
- ✅ **Performance Tracking:**
  - View all created plans
  - Score mentee performance (1-10 scale)
  - Provide feedback on progress

## Requirements
- WordPress 5.0+
- PHP 7.4+
- Chart.js (loaded via CDN)
- Strava API credentials

## Structure
```
activity-coach-system/
├── activity-coach-system.php          # Main plugin file
├── includes/
│   ├── class-database.php             # Database tables & activation
│   ├── class-user-roles.php           # Coach/Mentee roles
│   ├── class-api.php                  # API integration (placeholder)
│   ├── class-ajax.php                 # AJAX handlers (placeholder)
│   ├── class-dashboards.php           # Dashboard logic & OAuth
│   ├── class-settings.php             # Admin settings page
│   └── functions.php                  # Shared functions
├── assets/
│   ├── css/
│   │   ├── dashboard.css              # Dashboard styling
│   │   └── admin.css                  # Admin page styling
│   └── js/
│       └── dashboard.js               # Chart.js & interactivity
└── README.md
```

## Setup Instructions

### 1. Plugin Installation
1. Upload to `wp-content/plugins/` and activate
2. Database tables are created automatically

### 2. Strava API Configuration
1. Go to **Settings > Activity Coach**
2. Enter your Strava Client ID and Client Secret
3. Copy the OAuth Redirect URI to your Strava app settings
4. Set "Authorization Callback Domain" to your domain (not full URL)

### 3. User Role Assignment
1. Go to **Users** in WordPress admin
2. Assign "Coach" role to coaches
3. Assign "Mentee" role to mentees

### 4. Frontend Usage
Use these shortcodes on any page:
- `[acs_mentee_dashboard]` - For mentees to view their dashboard
- `[acs_coach_dashboard]` - For coaches to manage mentees and plans

## Database Tables

- `acs_coach_mentees` - Coach-mentee relationships
- `acs_api_users` - OAuth tokens and API connections
- `acs_activities_cache` - Synced Strava activities
- `acs_weekly_plans` - Training plans created by coaches
- `acs_plan_activities` - Individual activities within plans
- `acs_weekly_scores` - Performance scores and feedback
- `acs_settings` - Plugin configuration

## Security Features

- ✅ WordPress nonces for all forms
- ✅ Input sanitization and validation
- ✅ OAuth state verification
- ✅ User capability checks
- ✅ SQL prepared statements

## UI/UX Features

- ✅ Responsive design with CSS Grid/Flexbox
- ✅ Brand color scheme (#ff6124 primary)
- ✅ Modern card-based layout
- ✅ Interactive charts with Chart.js
- ✅ Clean, professional styling

## Troubleshooting

### JavaScript Functions Not Working

If you're getting "function is not defined" errors in the console:

1. **Check Asset Loading**: Ensure the plugin's JavaScript files are being loaded. Look for "ACS Dashboard JS loaded successfully" in the browser console.

2. **Clear Cache**: Clear any caching plugins or CDN cache that might be serving old versions of the files.

3. **Theme Conflicts**: Some themes may interfere with script loading. Try switching to a default theme temporarily.

4. **Plugin Conflicts**: Disable other plugins one by one to identify conflicts.

5. **Test Functions**: Use the included `test-dashboard.html` file to verify JavaScript functions are working.

### Common Issues

- **Functions not defined**: Usually indicates the dashboard.js file isn't loading properly
- **AJAX errors**: Check that the nonce is being generated correctly
- **Charts not displaying**: Ensure Chart.js is loading from the CDN

## Next Steps (Future Enhancements)

- AJAX-powered interactions for better UX
- Email notifications for new plans/scores
- Advanced analytics and reporting
- Mobile app integration
- Team/group coaching features
- Payment integration for premium features

---

**Version:** 1.0.0  
**Tested:** WordPress 6.5  
**PHP:** 7.4+
