# Predictions Platform - Deployment Guide

## Quick Start (InfinityFree)

### 1. Configure Database
1. Log into your InfinityFree control panel
2. Go to **MySQL Databases** and create a new database
3. Note your database name, username, password, and host (usually `sql.freedb.tech`)

### 2. Update Config
Open `config.php` and update these values:
```php
define('DB_HOST', 'sql.freedb.tech');     // Your MySQL host
define('DB_NAME', 'your_database_name');   // Your database name
define('DB_USER', 'your_username');         // Your database username
define('DB_PASS', 'your_password');         // Your database password
define('SITE_URL', 'https://yourdomain.com'); // Your site URL (no trailing slash)
define('CSRF_SECRET', 'change-to-random-64-char-string');
define('ENCRYPTION_KEY', 'change-this-32-char-key!!!!!!!!'); // Exactly 32 characters
```

### 3. Upload Files
Upload ALL files and folders to your `htdocs` directory on InfinityFree using the File Manager or FTP.

### 4. Run Installer
Visit `https://yourdomain.com/install.php` in your browser and click **"Run Setup"**.

This will:
- Create all 8 database tables
- Create the admin account (Dev1 / johnmccena)
- Set up indexes for performance

### 5. Delete Installer
**IMPORTANT:** Delete `install.php` after setup for security!

### 6. Login
- Regular users: Register at `/register.php`
- Admin: Login with username `Dev1` and password `johnmccena`

---

## File Structure
```
├── config.php              # Database and site configuration
├── install.php             # One-time database setup (DELETE AFTER USE)
├── .htaccess               # Security headers and access rules
├── index.php               # Home feed with active predictions
├── login.php               # User login
├── register.php            # User registration
├── logout.php              # Logout handler
├── prediction.php          # Prediction detail + betting
├── create.php              # Create new prediction
├── search.php              # Search predictions and users
├── profile.php             # User profile page
├── edit_profile.php        # Edit profile settings
├── leaderboard.php         # Top predictors rankings
├── notifications.php       # User notifications
├── buy_credits.php         # Credit purchase (manual review)
├── includes/               # Core PHP libraries
│   ├── db.php              # Database connection
│   ├── auth.php            # Authentication system
│   ├── csrf.php            # CSRF protection
│   ├── rate_limit.php      # Rate limiting
│   ├── functions.php       # Utility functions
│   ├── header.php          # HTML header template
│   └── footer.php          # HTML footer template
├── admin/                  # Admin dashboard (Dev1 only)
│   ├── index.php           # Admin overview
│   ├── users.php           # User management
│   ├── edit_user.php       # Edit individual user
│   ├── predictions.php     # Prediction management
│   ├── edit_prediction.php # Edit/resolve predictions
│   ├── resolve.php         # Resolve prediction handler
│   ├── payments.php        # Payment request review
│   └── audit.php           # Audit log viewer
├── api/                    # API endpoints
│   ├── bet.php             # Place bet endpoint
│   └── mark_notification.php # Mark notifications read
└── assets/
    ├── css/style.css       # Custom dark theme styles
    └── js/main.js          # Client-side JavaScript
```

## Features
- **Virtual Credits**: Every user starts with 50 credits
- **Predictions**: Create predictions with stakes, others can bet for/against
- **Auto Resolution**: When admin resolves a prediction, payouts are calculated automatically
- **Leaderboard**: Rankings by accuracy, credits, and prediction count
- **Admin Dashboard**: Full control over users, predictions, payments, and audit logs
- **Credit Purchase**: Users can request credits via card (manually reviewed by admin)
- **Security**: CSRF protection, rate limiting, password hashing, IP tracking, anti-spam honeypots
- **Anti-Abuse**: Multi-account IP detection, shadow bans, prediction locking

## Admin Account
- **Username**: Dev1
- **Password**: johnmccena
- **Access**: Full admin dashboard at `/admin/`
- **Capabilities**: Edit users, resolve predictions, approve payments, view IPs/metadata, ban users

## Security Notes
- All passwords are hashed with bcrypt
- Card numbers are AES-256-CBC encrypted
- CSRF tokens protect all forms
- Rate limiting on login, registration, betting, and payments
- IP-based multi-account prevention (max 2 accounts per IP)
- Shadow ban system (user doesn't know they're banned)
- Audit logging for all admin actions
