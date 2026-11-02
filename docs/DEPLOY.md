# Deployment (VPS, GitHub Actions)

Production: staff web app and API on **https://bank.rumput.tech** (API under `/api`),
customer app on **https://app.rumput.tech** (activation page `/aktivasi`).

The workflow [.github/workflows/ci-cd.yml](../.github/workflows/ci-cd.yml) runs the tests on
every push and pull request to `main` and `production`. A push to **`production`** that
passes the tests is deployed:

1. The runner installs Composer dependencies without dev packages and builds the Vite
   assets.
2. `php artisan down` on the server.
3. `rsync --delete` of the release into `DEPLOY_PATH`. Never uploaded and never deleted:
   `.env`, `storage/` (logs, caches, the Firebase key), `bootstrap/cache/*.php`
   ([deploy/rsync-exclude.txt](../deploy/rsync-exclude.txt)).
4. [deploy/post-deploy.sh](../deploy/post-deploy.sh) on the server: creates the `storage/`
   folders, warns if the Firebase key is missing, `migrate --force`, `optimize`,
   `queue:restart`, gives `storage/` and `bootstrap/cache` to the web user (when deploying
   as root), optionally reloads PHP-FPM, `php artisan up`.

If a step fails, the site is taken out of maintenance mode again. Migrations only add;
the workflow never seeds or wipes the database.

## 1. GitHub settings

Repository → Settings → Environments → **New environment** `production` (optionally
restrict it to the `production` branch). In that environment:

**Secrets**

| Name | Value |
|---|---|
| `DEPLOY_HOST` | `rumput.tech` (or the VPS IP) |
| `DEPLOY_USER` | SSH user, e.g. `root` |
| `DEPLOY_SSH_KEY` | Private key of a key pair made for deploys (see below) |
| `DEPLOY_KNOWN_HOSTS` | Output of `ssh-keyscan rumput.tech` checked against the server. Optional, but without it the first connection trusts whatever key answers |

**Variables** (all optional)

| Name | Default | Purpose |
|---|---|---|
| `DEPLOY_PATH` | `/var/www/bank-mini-laravel` | App folder on the server |
| `DEPLOY_PORT` | `22` | SSH port |
| `WEB_USER` | `www-data` | Owner of `storage/` and `bootstrap/cache` |
| `PHP_FPM_SERVICE` | (none) | e.g. `php8.4-fpm`; reloaded after each deploy (clears OPcache) |

Deploy key, made on your own computer (not on the server):

```bash
ssh-keygen -t ed25519 -C "github-deploy bank-mini" -f ~/.ssh/bank_mini_deploy -N ""
ssh-copy-id -i ~/.ssh/bank_mini_deploy.pub root@rumput.tech   # authorizes it on the VPS
ssh-keyscan rumput.tech                                         # → DEPLOY_KNOWN_HOSTS
```

Paste the contents of `~/.ssh/bank_mini_deploy` (the private file) into `DEPLOY_SSH_KEY`.

## 2. First-time server setup

Packages (Ubuntu/Debian; PHP **8.4 or newer**, the locked dependencies need it):

```bash
apt install nginx mariadb-server rsync \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-intl php8.4-bcmath \
  php8.4-gd php8.4-zip php8.4-curl php8.4-xml
```

Database:

```sql
CREATE DATABASE bank_mini CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bank_mini'@'localhost' IDENTIFIED BY '<strong password>';
GRANT ALL ON bank_mini.* TO 'bank_mini'@'localhost';
```

App folder, `.env` and the Firebase key (before the first deploy):

```bash
mkdir -p /var/www/bank-mini-laravel/storage/app/private
nano /var/www/bank-mini-laravel/.env
```

Start from `.env.example` and set at least:

```dotenv
APP_NAME="Bank Mini"
APP_ENV=production
APP_KEY=            # generate: php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
APP_DEBUG=false
APP_URL=https://bank.rumput.tech

DB_CONNECTION=mariadb
DB_DATABASE=bank_mini
DB_USERNAME=bank_mini
DB_PASSWORD=<strong password>

FIREBASE_PROJECT_ID=bank-mini-rumput
FIREBASE_CREDENTIALS=storage/app/private/firebase-credentials.json
FIREBASE_ALLOWED_PROVIDERS=password,google.com

CUSTOMER_ACTIVATION_URL=https://app.rumput.tech/aktivasi
CUSTOMER_ACTIVATION_TOKEN_HOURS=24
CUSTOMER_SESSION_MINUTES=43200

MAIL_MAILER=smtp
MAIL_HOST=<smtp host>
MAIL_PORT=587
MAIL_USERNAME=<smtp user>
MAIL_PASSWORD=<smtp password>
MAIL_FROM_ADDRESS="noreply@rumput.tech"
MAIL_FROM_NAME="Bank Mini"
```

Copy the Firebase service account key from your computer (it is never in git):

```bash
scp storage/app/private/firebase-credentials.json \
    root@rumput.tech:/var/www/bank-mini-laravel/storage/app/private/firebase-credentials.json
ssh root@rumput.tech 'chmod 600 /var/www/bank-mini-laravel/.env /var/www/bank-mini-laravel/storage/app/private/firebase-credentials.json'
```

(`post-deploy.sh` hands `storage/` to `www-data` on every deploy, so the key becomes readable
by PHP-FPM.)

Scheduler (prunes expired tokens and abandoned withdrawal requests):

```bash
crontab -u www-data -e
* * * * * cd /var/www/bank-mini-laravel && php artisan schedule:run >> /dev/null 2>&1
```

## 3. nginx

The staff web app (separate repository) is served from its own build folder; only `/api`
and `/up` go to Laravel. Example `/etc/nginx/sites-available/bank.rumput.tech`:

```nginx
server {
    listen 80;
    server_name bank.rumput.tech;

    # Staff web app build (front-end repository)
    root /var/www/bank-mini-frontend;
    index index.html;
    client_max_body_size 10m;

    location / {
        try_files $uri $uri/ /index.html;
    }

    # Laravel API and health check
    location ~ ^/(api|up)(/|$) {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/bank-mini-laravel/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
}
```

Then `ln -s /etc/nginx/sites-available/bank.rumput.tech /etc/nginx/sites-enabled/`,
`nginx -t && systemctl reload nginx`, and HTTPS with `certbot --nginx -d bank.rumput.tech`
once DNS points at the VPS. `app.rumput.tech` gets its own server block when the customer
app's web build exists.

## 4. First deploy

```bash
git switch -c production main
git push -u origin production
```

Watch it under the repository's **Actions** tab. Afterwards, once only:

```bash
ssh root@rumput.tech
cd /var/www/bank-mini-laravel
php artisan db:seed --force   # optional: demo accounts admin/supervisor/teller with password "password"; change them
curl -s https://bank.rumput.tech/up
```

Later deploys: merge or push to `production`.

## Checks

| Symptom | Cause |
|---|---|
| Deploy fails at "Missing .env" | Create `/var/www/bank-mini-laravel/.env` (section 2) |
| Log line "Firebase key ... not found" | Copy the key (section 2); customer login answers `503 auth_unavailable` until then |
| `Permission denied (publickey)` | Public deploy key not in the server's `~/.ssh/authorized_keys`, or wrong `DEPLOY_USER` |
| `Host key verification failed` | `DEPLOY_KNOWN_HOSTS` does not match the server |
| 500 with "Permission denied" in `storage/` | `WEB_USER` differs from the PHP-FPM user |
