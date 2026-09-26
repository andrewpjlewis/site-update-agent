# WordPress Maintenance Agent — Simple cPanel Setup

This guide shows  how to install the WordPress maintenance agent on one cPanel account.

The agent can:

- Create a full backup of WordPress files and the database
- Keep only the newest backups
- Update plugins and themes
- Put WordPress into maintenance mode during updates
- Restore a previous backup
- Write logs showing what happened

---

## 1. Fill in these values first

Replace every value below with the correct information for the website.

```text
CPANEL_USER=REPLACE_WITH_CPANEL_USERNAME
HOME_PATH=/home12/REPLACE_WITH_CPANEL_USERNAME
WORDPRESS_ROOT=/home12/REPLACE_WITH_CPANEL_USERNAME/public_html
DOMAIN=example.com
PHP_PATH=/usr/local/bin/php
```

Example:

```text
CPANEL_USER=north26
HOME_PATH=/home12/north26
WORDPRESS_ROOT=/home12/north26/public_html
DOMAIN=northernironworks.com
PHP_PATH=/usr/local/bin/php
```

Important: the WordPress root is the folder containing:

```text
wp-admin
wp-content
wp-includes
wp-config.php
```

---

## 2. Create the agent folder

Inside the WordPress root, create:

```text
WORDPRESS_ROOT/wordpress-maintenance-agent
```

Example:

```text
/home12/northern26/public_html/wordpress-maintenance-agent
```

Upload these files into it:

```text
wp-agent.php
wp-agent.config.json
```

Recommended permissions:

```text
wordpress-maintenance-agent folder: 755
wp-agent.php: 644
wp-agent.config.json: 644
```

---

## 3. Create the private backup folder

Create this outside `public_html`:

```text
HOME_PATH/wordpress-backups/DOMAIN
```

Example:

```text
/home12/northern26/wordpress-backups/northernironworks.com
```

Recommended permission:

```text
755
```

Do not place backups inside `public_html`.

---

## 4. Create the WP-CLI folder

Create:

```text
HOME_PATH/wp-cli
```

Upload:

```text
wp-cli.phar
```

Example:

```text
/home12/northern26/wp-cli/wp-cli.phar
```

Recommended permission:

```text
644
```

---

## 5. Create the WP-CLI wrapper with a temporary cron job

Replace these placeholders before using the command:

```text
PHP_PATH
HOME_PATH
```

Template Command:

```bash
printf '#!/bin/sh\nexec /usr/local/bin/php home12/htp26/wp-cli/wp-cli.phar "$@"\n' > home12/htp26/wp-cli/wp && chmod 755 home12/htp26/wp-cli/wp
```

Example:

```bash
printf '#!/bin/sh\nexec /usr/local/bin/php /home12/northern26/wp-cli/wp-cli.phar "$@"\n' > /home12/northern26/wp-cli/wp && chmod 755 /home12/northern26/wp-cli/wp
```

Set the temporary cron schedule to every minute:

```text
Minute: *
Hour: *
Day: *
Month: *
Weekday: *
```

After it runs once, delete the temporary cron job.

The final files should be:

```text
HOME_PATH/wp-cli/wp
HOME_PATH/wp-cli/wp-cli.phar
```

Permissions:

```text
wp: 755
wp-cli.phar: 644
```

---

## 6. Edit `wp-agent.config.json`

Replace every `REPLACE_...` value:

```json
{
  "backupDir": "/home12/REPLACE_WITH_CPANEL_USERNAME/wordpress-backups/REPLACE_WITH_DOMAIN",
  "siteUrl": "https://REPLACE_WITH_DOMAIN",
  "wpCliBinary": "/home12/REPLACE_WITH_CPANEL_USERNAME/wp-cli/wp",
  "tarBinary": "/usr/bin/tar",
  "updatePlugins": true,
  "updateActiveTheme": true,
  "updateAllThemes": true,
  "clearCache": true,
  "verifyUrls": [],
  "autoRollbackOnFailure": false,
  "keepBackups": 2
}
```

Example:

```json
{
  "backupDir": "/home12/northern26/wordpress-backups/northernironworks.com",
  "siteUrl": "https://northernironworks.com",
  "wpCliBinary": "/home12/northern26/wp-cli/wp",
  "tarBinary": "/usr/bin/tar",
  "updatePlugins": true,
  "updateActiveTheme": true,
  "updateAllThemes": true,
  "clearCache": true,
  "verifyUrls": [],
  "autoRollbackOnFailure": false,
  "keepBackups": 2
}
```

`verifyUrls` is currently empty because HTTP verification may fail on some hosting accounts even when the website is working. Manually check the website after updates until verification is improved.

---

# Test cron jobs

Use each test temporarily. Set it to every minute, allow it to run once, then delete it.

## Test 1: Confirm cron works

Replace `HOME_PATH`:

```bash
date > HOME_PATH/cron-basic-test.log 2>&1
```

Example:

```bash
date > /home12/northern26/cron-basic-test.log 2>&1
```

Check:

```text
HOME_PATH/cron-basic-test.log
```

---

## Test 2: Test WP-CLI

Replace `HOME_PATH`:

```bash
HOME_PATH/wp-cli/wp --info > HOME_PATH/wp-wrapper-test.log 2>&1
```

Example:

```bash
/home12/northern26/wp-cli/wp --info > /home12/northern26/wp-wrapper-test.log 2>&1
```

Check:

```text
HOME_PATH/wp-wrapper-test.log
```

A successful result shows the WP-CLI version.

---

## Test 3: Test WordPress access

Replace `WORDPRESS_ROOT` and `HOME_PATH`:

```bash
cd WORDPRESS_ROOT && { HOME_PATH/wp-cli/wp core version; HOME_PATH/wp-cli/wp plugin list; HOME_PATH/wp-cli/wp theme list; } > HOME_PATH/wp-wordpress-test.log 2>&1
```

Example:

```bash
cd /home12/northern26/public_html && { /home12/northern26/wp-cli/wp core version; /home12/northern26/wp-cli/wp plugin list; /home12/northern26/wp-cli/wp theme list; } > /home12/northern26/wp-wordpress-test.log 2>&1
```

Check:

```text
HOME_PATH/wp-wordpress-test.log
```

A successful result shows the WordPress version, plugin list, and theme list.

---

## Test 4: Create a backup

Replace `WORDPRESS_ROOT`, `PHP_PATH`, and `HOME_PATH`:

```bash
cd WORDPRESS_ROOT && PHP_PATH wordpress-maintenance-agent/wp-agent.php backup > HOME_PATH/wp-maintenance-backup-test.log 2>&1
```

Example:

```bash
cd /home12/northern26/public_html && /usr/local/bin/php wordpress-maintenance-agent/wp-agent.php backup > /home12/northern26/wp-maintenance-backup-test.log 2>&1
```

Check:

```text
HOME_PATH/wp-maintenance-backup-test.log
```

A successful result includes:

```text
Backup completed
"success": true
```

The backup should appear inside:

```text
HOME_PATH/wordpress-backups/DOMAIN
```

---

## Test 5: Test an update

Replace `WORDPRESS_ROOT`, `PHP_PATH`, and `HOME_PATH`:

```bash
cd WORDPRESS_ROOT && PHP_PATH wordpress-maintenance-agent/wp-agent.php update > HOME_PATH/wp-maintenance-update-test.log 2>&1
```

Example:

```bash
cd /home12/northern26/public_html && /usr/local/bin/php wordpress-maintenance-agent/wp-agent.php update > /home12/northern26/wp-maintenance-update-test.log 2>&1
```

Check:

```text
HOME_PATH/wp-maintenance-update-test.log
```

A successful result includes:

```text
Update completed successfully
"success": true
```

---

## Test 6: Restore a backup

Replace:

```text
WORDPRESS_ROOT
PHP_PATH
HOME_PATH
EXACT_BACKUP_FOLDER
```

Template:

```bash
cd WORDPRESS_ROOT && printf 'YES\n' | PHP_PATH wordpress-maintenance-agent/wp-agent.php rollback --backup HOME_PATH/wordpress-backups/DOMAIN/EXACT_BACKUP_FOLDER > HOME_PATH/wp-maintenance-restore-test.log 2>&1
```

Example:

```bash
cd /home12/northern26/public_html && printf 'YES\n' | /usr/local/bin/php wordpress-maintenance-agent/wp-agent.php rollback --backup /home12/northern26/wordpress-backups/northernironworks.com/site-backup-2026-06-29-22-09-06 > /home12/northern26/wp-maintenance-restore-test.log 2>&1
```

Check:

```text
HOME_PATH/wp-maintenance-restore-test.log
```

A successful result includes:

```text
Rollback completed
"success": true
```

Delete the rollback cron immediately after it runs.

---

# Production weekly update cron

Replace:

```text
WORDPRESS_ROOT
PHP_PATH
HOME_PATH
```

Template:

```bash
cd WORDPRESS_ROOT && PHP_PATH wordpress-maintenance-agent/wp-agent.php update >> HOME_PATH/wp-maintenance-update.log 2>&1
```

Example:

```bash
cd /home12/northern26/public_html && /usr/local/bin/php wordpress-maintenance-agent/wp-agent.php update >> /home12/northern26/wp-maintenance-update.log 2>&1
```

Suggested weekly schedule:

```text
Minute: 30
Hour: 10
Day: *
Month: *
Weekday: 1
```

Cron expression:

```text
30 10 * * 1
```

This runs every Monday at 10:30 server time.

Stagger different websites by 15–30 minutes so they do not all update at once.

---

# What happens during the production update

The agent:

1. Runs preflight checks
2. Creates a full backup
3. Keeps the four newest backups
4. Deletes older backups
5. Enables WordPress maintenance mode
6. Updates plugins
7. Updates configured themes
8. Disables maintenance mode
9. Writes a log

The production log is:

```text
HOME_PATH/wp-maintenance-update.log
```

---

# Quick checklist

```text
[ ] Confirm cPanel username
[ ] Confirm home path
[ ] Confirm WordPress root
[ ] Confirm working PHP CLI path
[ ] Upload wp-agent.php
[ ] Upload wp-agent.config.json
[ ] Create private backup directory
[ ] Upload wp-cli.phar
[ ] Create WP-CLI wrapper
[ ] Test WP-CLI
[ ] Test WordPress access
[ ] Test backup
[ ] Test update
[ ] Test rollback
[ ] Remove all temporary every-minute cron jobs
[ ] Add weekly production cron
[ ] Manually check the website after updates
```

## Important reminders

- Never leave a test or rollback cron running every minute.
- Always keep backups outside `public_html`.
- Use the PHP path that actually works for WP-CLI on that account.
- Different cPanel accounts may use different PHP paths.
- The agent currently keeps the four newest backups.
- Manually check the website after scheduled updates while `verifyUrls` is empty.
