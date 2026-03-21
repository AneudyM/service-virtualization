# Deployment Guide — TigerTech Shared Hosting

## Prerequisites
- SSH access to your TigerTech hosting
- PHP 8.2+ (confirmed)
- MySQL database (create one via your hosting control panel)
- Composer installed globally or locally

## Step 1: Upload the project

```bash
# Option A: SCP from your machine
scp -r service-virtualization/ user@intelycs.com:~/

# Option B: Git clone on the server
ssh user@intelycs.com
cd ~
git clone <repo-url> service-virtualization
```

## Step 2: Install dependencies

```bash
ssh user@intelycs.com
cd ~/service-virtualization

# If composer is available globally:
composer install --no-dev --optimize-autoloader

# If not, install composer first:
curl -sS https://getcomposer.org/installer | php
php composer.phar install --no-dev --optimize-autoloader
```

## Step 3: Configure environment

```bash
cp .env.example .env
nano .env
```

Fill in your MySQL credentials. The database name must match one you've created
in your hosting control panel.

Set `APP_BASE_URL` to your subdomain:
```
APP_BASE_URL=https://virtual-services.intelycs.com
```

## Step 4: Install database schema

```bash
php bin/install-schema.php
```

Expected output:
```
Installing schema into 'your_db_name'...
Schema installed successfully. Tables created:
  - callback_history
  - entities
  - pending_callbacks
  - request_log
  - scenarios
  - state_history
```

## Step 5: Create the subdomain

TigerTech creates subdomains automatically from folders in your web root.
Just symlink the `public/` directory as a new folder named `virtual-services`.

```bash
cd /var/www/html/in/intelycs.com    # or wherever your web root is
ln -s ~/service-virtualization/public virtual-services
```

That's it — `https://virtual-services.intelycs.com/` is now live.

**Important:** Because TigerTech handles subdomain rewrite internally, add
`RewriteBase /` to the `.htaccess` inside the subdomain directory (already
included in the project, but verify if routing breaks).

### SSL note
Your existing SSL certificate should already cover `*.intelycs.com` at the
same level (e.g., `virtual-services.intelycs.com`). If not, you may need to
request that hostname be added to your certificate. Check by visiting
`https://virtual-services.intelycs.com/` — if you get an SSL warning, contact
TigerTech support to add the hostname.

## Step 6: Test it

```bash
curl https://virtual-services.intelycs.com/
```

Should return:
```json
{
    "error": false,
    "message": "ok",
    "data": {
        "service": "AlfredPay Service Virtualization Platform",
        "version": "0.1.0-poc",
        "status": "healthy"
    }
}
```

Test the database connection:
```bash
curl https://virtual-services.intelycs.com/health
```

## Step 7: Set up cron job (optional, for async callbacks)

```bash
ssh user@intelycs.com
crontab -e
```

Add this line (fires pending callbacks every 5 minutes):
```
*/5 * * * * cd ~/service-virtualization; /usr/bin/php bin/fire-callbacks.php
```

Note: For most testing, you'll use the instant `POST /control/fire-callbacks`
endpoint instead of waiting for cron.

## Step 8: Set up expired scenario cleanup (optional)

Add another cron entry to clean up expired test scenarios hourly:
```
17 * * * * /usr/bin/wget --quiet -O - 'https://virtual-services.intelycs.com/control/cleanup-expired'
```

## Quick Reference

| What | URL |
|------|-----|
| Health check | `GET https://virtual-services.intelycs.com/health` |
| Seed scenario | `POST https://virtual-services.intelycs.com/control/scenarios` |
| Fire callbacks | `POST https://virtual-services.intelycs.com/control/fire-callbacks` |
| Create KYC session | `POST https://virtual-services.intelycs.com/api/compliance/sessions` |
| Full demo | `bash demo.sh https://virtual-services.intelycs.com` |
