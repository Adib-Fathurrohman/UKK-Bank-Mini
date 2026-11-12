# Deployment (versioned releases on the VPS)

The API runs at **https://api-bank.rumput.tech** (routes under `/api`, health check at
`/up`). The staff web app (`https://bank.rumput.tech`) is a separate repository and calls
this API from the browser; the customer app is a native Android/iOS app.

Releases are immutable, versioned build artifacts. A release is published on GitHub, and the
server downloads that exact file, checks its SHA-256 and switches to it. Source code is never
copied to the server by other means.

```
git tag vX.Y.Z ──► GitHub Release (tarball + .sha256 + manifest.json)
                        │
                        ▼
          VPS: bin/deploy.sh X.Y.Z ──► releases/vX.Y.Z ──► current → vX.Y.Z
```

## 1. Layout on the server

Host `rumput.tech` (Ubuntu, Nginx, PHP-FPM, MariaDB). The VPS is shared with other
students' applications, so everything of this project stays under one folder:

```
/var/www/rumput/bank-mini/
├── api/
│   ├── releases/vX.Y.Z/     one folder per release, never overwritten
│   ├── shared/
│   │   ├── .env             production settings and secrets (mode 600)
│   │   ├── storage/         logs, caches, sessions (outlive releases)
│   │   ├── firebase-credentials.json   service account key (mode 600)
│   │   └── initial-admin-password.txt  first administrator password (mode 600)
│   └── current -> releases/vX.Y.Z      what Nginx serves (public/ inside)
├── backups/                 database dumps taken before each deploy (mode 750)
├── bin/deploy.sh, rollback.sh   copies of deploy/server/*.sh
└── tmp/                     downloads, PHP uploads and temp files, ACME challenges
```

Every release has `.env` and `storage` as symlinks into `shared/`.

| Piece | Where |
|---|---|
| System user | `rumput` (no login). Owns all of the above |
| PHP-FPM pool | `/etc/php/8.5/fpm/pool.d/rumput-bank-mini.conf`, socket `/run/php/rumput-bank-mini.sock` |
| Nginx vhost | `/etc/nginx/sites-available/api-bank.rumput.tech` (linked in `sites-enabled`) |
| TLS certificate | Let's Encrypt via certbot, webroot `/var/www/rumput/bank-mini/tmp/acme` |
| Database | `rumput_bank_mini`, user `rumput_bank_mini` (rights on that database only) |
| Scheduler | `/etc/cron.d/rumput-bank-mini`, every minute as `rumput` |
| Nginx logs | `/var/log/nginx/api-bank.rumput.tech.{access,error}.log` |
| App logs | `shared/storage/logs/laravel-*.log` and `php-fpm.log` |

No extra TCP port is used: Nginx talks to PHP-FPM over the socket. SSH is not touched.

## 2. Releasing a new version

Versions follow semantic versioning. `0.x` marks pre-releases (the current ones are for the
exam, not public releases).

1. Merge to `main` and make sure the tests pass (`php artisan test`).
2. Tag the commit and push the tag:

   ```bash
   git tag -a v0.2.0 -m "Rumput Bank Mini API v0.2.0"
   git push origin v0.2.0
   ```

3. The workflow [.github/workflows/release.yml](../.github/workflows/release.yml) runs the
   tests, builds the artifact with [deploy/build-release.sh](../deploy/build-release.sh) and
   creates the GitHub Release with the three files below.

If GitHub Actions is not available (for example the account has a billing lock), build and
publish from your own computer instead. This needs the [GitHub CLI](https://cli.github.com)
(`gh auth login`) and a clean checkout of the tagged commit:

```bash
deploy/build-release.sh 0.2.0     # writes dist/ (git-ignored)
gh release create v0.2.0 dist/rumput-bank-mini-api-v0.2.0.tar.gz \
  dist/rumput-bank-mini-api-v0.2.0.tar.gz.sha256 dist/rumput-bank-mini-api-v0.2.0.manifest.json \
  --verify-tag --prerelease --title "Rumput Bank Mini API v0.2.0" --notes "Commit <sha>"
```

Use one route or the other for a tag, not both: the workflow's `gh release create` fails if
the release already exists.

**What is in the artifact** (`rumput-bank-mini-api-vX.Y.Z.tar.gz`): the tracked source of the
tagged commit, Composer production dependencies (`vendor/`, no dev packages), `public/`, and a
`manifest.json` with the version, commit SHA, build time, PHP requirement (currently
`>=8.4.1`), required PHP extensions and Laravel version. **Not** in it: `.env`, database
dumps, keys, Firebase service account files, tests, docs. `build-release.sh` stops if such a
file turns up.

## 3. Deploying a release

On the VPS, as root:

```bash
/var/www/rumput/bank-mini/bin/deploy.sh 0.2.0
```

[deploy/server/deploy.sh](../deploy/server/deploy.sh) does, in this order, and stops at the first failure (an incomplete release
folder is removed and `current` stays on the working release):

1. Downloads the tarball and `.sha256` from the GitHub Release and verifies the checksum.
2. Extracts into `api/releases/vX.Y.Z/` (an existing release is never overwritten) and checks
   the PHP version against the manifest.
3. Links `.env` and `storage` from `shared/`.
4. Dumps the database to `backups/` (mode 600).
5. `php artisan migrate --force` as `rumput`.
6. `php artisan optimize` with `TMPDIR` set to the project's `tmp/`. This matters: the config
   cache stores `sys_get_temp_dir()` (used by the PDF library), and the PHP-FPM pool may only
   use paths inside `/var/www/rumput/bank-mini/`.
7. Health check of the new release (`/up` must answer 200).
8. Switches `current` atomically (`ln` + `mv -T`). Old releases stay for rollback; remove
   old ones by hand when disk space matters.

No service restart is needed: every release has its own real path (`$realpath_root` in the
Nginx config), so PHP's OPcache never serves old files. A PHP-FPM reload is only needed after
changing the pool file.

Never run `migrate:fresh`, `db:wipe`, or the demo seeder in production.

### Rollback

```bash
/var/www/rumput/bank-mini/bin/rollback.sh 0.1.0
```

This points `current` at an existing release and nothing else. Migrations are not reverted
(they only add). If a release must be undone at the database level, restore the matching dump
from `backups/` (stop writes first).

## 4. Settings (`shared/.env`)

Created once on the server; never in git. Start from `.env.example`. Values that differ from
local development:

| Key | Production value | Why |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | |
| `APP_URL` | `https://api-bank.rumput.tech` | |
| `APP_KEY` | random, `base64:` + 32 bytes | Do not change it after go-live |
| `DB_CONNECTION` | `mariadb` | The driver the project is built and tested with |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `rumput_bank_mini`, `rumput_bank_mini`, random | Dedicated database and user |
| `LOG_STACK` / `LOG_LEVEL` | `daily` / `info` | |
| `SESSION_SECURE_COOKIE` etc. | `true`, `SESSION_ENCRYPT=true` | The API is stateless; sessions are not used for login |
| `CORS_ALLOWED_ORIGINS` | `https://bank.rumput.tech` | Browser origins that may call the API (comma-separated) |
| `STAFF_WEB_URL` | `https://bank.rumput.tech/api` | Staff web app with its base path; links in staff emails (`/reset-password`, `/login`) open it |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT` | `smtp`, `smtp-relay.brevo.com`, `587` | Staff verification and password-reset emails (Brevo SMTP) |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | Brevo SMTP login and SMTP key | Secret: only in `shared/.env` |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | a sender verified in Brevo, `"Bank Mini"` | Must be a verified sender, or Brevo refuses the mail |
| `TRUSTED_PROXIES` | empty | Nothing sits in front of Nginx. Set to the proxy address (or `*`) if a CDN/tunnel is added, so the login rate limits see real client IPs |
| `SANCTUM_STATEFUL_DOMAINS` | empty | Cookie-based SPA login is not used |
| `FIREBASE_PROJECT_ID` | `bank-mini-rumput` | |
| `FIREBASE_CREDENTIALS` | `/var/www/rumput/bank-mini/api/shared/firebase-credentials.json` | Absolute path; the key is copied by hand |
| `FIREBASE_ALLOWED_PROVIDERS` | `password,google.com` | |
| `CUSTOMER_SESSION_MINUTES` | `43200` | |

No mail settings are needed: the backend sends no email.

### Authentication and CORS

Staff (`POST /api/staff/login`) and customers (Firebase login) both receive **Sanctum
personal access tokens** and send them as `Authorization: Bearer ...`. There are no cookies
and no CSRF tokens, so CORS runs **without credentials**:

- [config/cors.php](../config/cors.php) allows only the origins in `CORS_ALLOWED_ORIGINS`,
  the methods `GET POST PUT PATCH DELETE OPTIONS` and the headers `Accept`, `Authorization`,
  `Content-Type`, `Idempotency-Key`, `X-Requested-With`. It exposes `Content-Disposition`
  (PDF file names) and `Retry-After`.
- The native Android/iOS app is not subject to CORS.
- The staff web app must be **built with the API base URL `https://api-bank.rumput.tech/api`**.
  The default build uses a relative `/api`, which only works when the web app and the API
  share a host.

## 5. First-time setup (already done on rumput.tech)

Kept as a record and for rebuilding the server. Run as root. Check first that nothing
exists under the names below, and stop on any conflict.

```bash
# user and folders
useradd --system --user-group --home-dir /var/www/rumput --no-create-home --shell /usr/sbin/nologin rumput
install -d -o rumput -g rumput -m 755 /var/www/rumput /var/www/rumput/bank-mini /var/www/rumput/bank-mini/api /var/www/rumput/bank-mini/api/releases
install -d -o rumput -g rumput -m 750 /var/www/rumput/bank-mini/api/shared /var/www/rumput/bank-mini/backups
install -d -o rumput -g rumput -m 711 /var/www/rumput/bank-mini/tmp
install -d -o rumput -g rumput -m 755 /var/www/rumput/bank-mini/tmp/acme
# shared/storage: app/{private,public}, framework/{cache/data,sessions,views,testing}, logs (owner rumput, mode 750)
```

Database (the password goes straight into `shared/.env`, never on a command line you keep):

```sql
CREATE DATABASE rumput_bank_mini CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rumput_bank_mini'@'localhost' IDENTIFIED BY '<random>';
GRANT ALL PRIVILEGES ON rumput_bank_mini.* TO 'rumput_bank_mini'@'localhost';
```

Firebase service account key (from your computer; it is never in git or in the artifact):

```bash
scp storage/app/private/firebase-credentials.json \
    root@rumput.tech:/var/www/rumput/bank-mini/api/shared/firebase-credentials.json
ssh root@rumput.tech 'chown rumput:rumput /var/www/rumput/bank-mini/api/shared/firebase-credentials.json; chmod 600 /var/www/rumput/bank-mini/api/shared/firebase-credentials.json'
```

**PHP-FPM pool** (own user and socket, so the other students' pools, which run as `www-data`,
cannot read this project's files). `/etc/php/8.5/fpm/pool.d/rumput-bank-mini.conf`:

```ini
[rumput-bank-mini]
user = rumput
group = rumput
listen = /run/php/rumput-bank-mini.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 30s
pm.max_requests = 500
request_terminate_timeout = 60s
catch_workers_output = yes

php_admin_value[error_log] = /var/www/rumput/bank-mini/api/shared/storage/logs/php-fpm.log
php_admin_flag[log_errors] = on
php_admin_flag[display_errors] = off
php_admin_value[open_basedir] = /var/www/rumput/bank-mini/:/usr/share/php/:/etc/ssl/certs/:/usr/share/ca-certificates/:/dev/urandom:/dev/null
php_admin_value[sys_temp_dir] = /var/www/rumput/bank-mini/tmp
php_admin_value[upload_tmp_dir] = /var/www/rumput/bank-mini/tmp
; The customer import accepts files up to 5 MB.
php_admin_value[upload_max_filesize] = 6M
php_admin_value[post_max_size] = 8M
php_admin_value[memory_limit] = 256M
```

Check with `php-fpm8.5 -t`, then `systemctl reload php8.5-fpm` (graceful; it also serves the
other students, so only reload after a successful test). OPcache is enabled in the global
`php.ini`; it cannot be set per pool.

**Nginx** (`/etc/nginx/sites-available/api-bank.rumput.tech`, then
`ln -s` into `sites-enabled`; test with `nginx -t` before `systemctl reload nginx`). This
is a new file; no existing vhost or global setting is edited:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name api-bank.rumput.tech;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/rumput/bank-mini/tmp/acme;
        default_type text/plain;
    }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name api-bank.rumput.tech;

    ssl_certificate     /etc/letsencrypt/live/api-bank.rumput.tech/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api-bank.rumput.tech/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    root /var/www/rumput/bank-mini/api/current/public;
    index index.php;
    charset utf-8;
    client_max_body_size 6m;     # the customer import accepts files up to 5 MB

    access_log /var/log/nginx/api-bank.rumput.tech.access.log;
    error_log  /var/log/nginx/api-bank.rumput.tech.error.log;

    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header Strict-Transport-Security "max-age=31536000" always;

    location / { try_files $uri /index.php?$query_string; }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/rumput-bank-mini.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        include fastcgi_params;
        fastcgi_read_timeout 60s;
    }

    location ~ \.php$ { return 404; }
    location ~ /\.(?!well-known) { deny all; }
}
```

**HTTPS** needs a DNS record first: an `A` record `api-bank` → the VPS address, DNS only (no
proxy). Until the certificate exists, install only the port-80 block (without the redirect,
and with `allow 127.0.0.1; deny all;` if the app should not be reachable yet), then:

```bash
certbot certonly --webroot -w /var/www/rumput/bank-mini/tmp/acme -d api-bank.rumput.tech
```

and install the full file above. Renewal uses the saved webroot (`certbot.timer` is active).

**Scheduler** (`/etc/cron.d/rumput-bank-mini`; expires withdrawal requests every minute and
prunes old tokens daily):

```
* * * * * rumput cd /var/www/rumput/bank-mini/api/current && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

**First data.** The database starts empty. Do not run the demo seeder (it creates accounts
with the password "password"). Create the majors and one administrator instead (see
`database/seeders/DatabaseSeeder.php` for the major list); the administrator's random password
is written to `shared/initial-admin-password.txt`, never printed. There is no API to change an
administrator's password; do it with `php artisan tinker` as `rumput`. Customers can then be
created one by one or imported (`POST /api/admin/customers/import`).

Finally copy [deploy/server/deploy.sh](../deploy/server/deploy.sh) and
[rollback.sh](../deploy/server/rollback.sh) to `/var/www/rumput/bank-mini/bin/` (owner root,
mode 750) and run `bin/deploy.sh 0.1.0`. After editing the scripts in git, copy them again.

## 6. Verifying a deployment

From any computer, after DNS and HTTPS are in place:

```bash
curl -s https://api-bank.rumput.tech/up -o /dev/null -w '%{http_code}\n'            # 200
curl -s https://api-bank.rumput.tech/api/staff/me -H 'Accept: application/json'      # 401 without a token
curl -si -X OPTIONS https://api-bank.rumput.tech/api/staff/login \
  -H 'Origin: https://bank.rumput.tech' -H 'Access-Control-Request-Method: POST' \
  -H 'Access-Control-Request-Headers: authorization,content-type'                   # 204 + allow-origin
```

Then log in as staff from the web app and check one PDF export (it exercises the PHP
file-access restrictions) and a customer login from the mobile app.

## 7. Troubleshooting

| Symptom | Cause |
|---|---|
| `deploy.sh`: checksum mismatch | The download is incomplete or the release was replaced. Do not deploy; re-publish the release |
| `deploy.sh`: "PHP x.y or newer is required" | The server's PHP is older than `php_requirement` in the manifest |
| 500 on a PDF export, log says "Path must not be empty" | The config cache was built without `TMPDIR=/var/www/rumput/bank-mini/tmp` (use `deploy.sh`, or `TMPDIR=... php artisan optimize`) |
| Browser: "blocked by CORS policy" | The web app's origin is not in `CORS_ALLOWED_ORIGINS`, or it calls the wrong API URL. Run `php artisan optimize` after changing `.env` |
| Customer login answers `503 auth_unavailable` | The Firebase key is missing or `FIREBASE_CREDENTIALS` is not an absolute path to it |
| Everybody gets `429` quickly | Behind a proxy without `TRUSTED_PROXIES`, all clients share one IP in the rate limiter |
| 502 from Nginx | The PHP-FPM pool is not running or the socket permissions changed (`listen.owner` must be Nginx's user) |
| 413 on the customer import | `client_max_body_size` or the pool's `post_max_size` is below the 5 MB file limit |
| Certbot cannot issue | The DNS record is missing, or port 80 does not reach this vhost's `/.well-known/acme-challenge/` |

## Legacy: push-to-`production` pipeline (no longer used)

The first version of this project deployed by pushing to a `production` branch, which
`.github/workflows/ci-cd.yml` rsynced to `/var/www/bank-mini-laravel` using
`deploy/post-deploy.sh` and `deploy/rsync-exclude.txt`. That does not match the layout above
(different folder, no releases, no dedicated user) and is not used for production. Do not
push to `production` or configure its deploy secrets. The `test` job of that workflow still
runs on every push and pull request.
