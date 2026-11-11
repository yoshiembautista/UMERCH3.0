# Deploying UMerch to Cloudflare Containers

This guide deploys the Laravel + Inertia app as a **Cloudflare Container** wrapped
by a Worker. Cloudflare does not run PHP on Workers or Pages directly, so the
container is the application and the Worker is the public entry point.

```
Browser → Worker → Durable Object → Container :8080 (FrankenPHP)
                                      ├── Neon Postgres
                                      ├── Cloudflare R2
                                      └── SMTP
```

## 1. Prerequisites

- A Cloudflare account on the **Workers Paid** plan ($5/month minimum).
- **Docker Desktop** installed and running (Linux containers). Wrangler uses it
  to build and push the image.
- Node.js 20+ and the dependencies from `npm install`.
- A **Neon Postgres** project.
- A **Cloudflare R2 bucket** plus an API token with Object Read & Write.
- A **reCAPTCHA v2** site/secret key pair. Login is blocked without a real site
  key, because `resources/js/components/ui/Knowledge.jsx` requires a token.

## 2. Create the database (Neon)

1. Create a project at <https://neon.tech>.
2. Copy the connection string, for example:

   ```
   postgresql://user:password@ep-xxx-pooler.region.aws.neon.tech/umerch?sslmode=require
   ```

3. Keep it for the `DB_URL` secret. Migrations run automatically when the
   container starts, so you do not need to run them locally against Neon.

## 3. Create the R2 bucket

1. Cloudflare dashboard → **R2** → **Create bucket** (for example `umerch`).
2. **Manage R2 API Tokens** → create a token with **Object Read & Write** for
   that bucket. Save the access key ID and secret.
3. Give the bucket a public URL: either an **R2 custom domain** (recommended) or
   the `r2.dev` development URL.
4. The public URL is `R2_PUBLIC_URL` (no trailing slash). The S3 endpoint is:

   ```
   https://<ACCOUNT_ID>.r2.cloudflarestorage.com
   ```

Uploads are written through Laravel's `public` disk, which switches to the S3
driver automatically when `R2_BUCKET` is set (see `config/filesystems.php`).

## 4. Configure secrets and build variables

Replace the reCAPTCHA **site key** in `wrangler.jsonc`:

```jsonc
"build_vars": {
  "VITE_RECAPTCHA_SITE_KEY": "your-public-site-key"
}
```

Generate an application key locally:

```powershell
php artisan key:generate --show
```

Set the Worker secrets:

```powershell
npx wrangler login

npx wrangler secret put APP_KEY              # output of key:generate --show
npx wrangler secret put APP_URL              # https://umerch.<subdomain>.workers.dev
npx wrangler secret put DB_URL               # Neon connection string
npx wrangler secret put R2_ACCESS_KEY_ID
npx wrangler secret put R2_SECRET_ACCESS_KEY
npx wrangler secret put R2_BUCKET
npx wrangler secret put R2_ENDPOINT
npx wrangler secret put R2_PUBLIC_URL
npx wrangler secret put RECAPTCHA_SECRET_KEY
```

Optional but required for OTP email:

```powershell
npx wrangler secret put MAIL_HOST
npx wrangler secret put MAIL_PORT
npx wrangler secret put MAIL_USERNAME
npx wrangler secret put MAIL_PASSWORD
npx wrangler secret put MAIL_ENCRYPTION
npx wrangler secret put MAIL_FROM_ADDRESS
```

The non-secret production values (`APP_ENV`, `DB_CONNECTION`, session/cache
drivers, `LOG_CHANNEL`) are already in the `vars` block of `wrangler.jsonc`.

> A container reads its environment **once at startup**. After changing a
> secret, redeploy or restart the container so the new value is picked up.

## 5. Deploy

Make sure Docker Desktop is running, then:

```powershell
npx wrangler deploy
```

Wrangler uploads the Worker, builds the image from `Dockerfile`, pushes it to
Cloudflare's registry, and rolls out the container. The first deploy takes
several minutes; the Worker URL can respond before the container is ready.

Watch logs:

```powershell
npx wrangler tail
npx wrangler containers list
```

Open the `workers.dev` URL printed by Wrangler. The first request triggers a
cold start: migrations, config/view caches, then FrankenPHP.

## 6. First admin user

**Do not run `db:seed` in production.** `DatabaseSeeder` hardcodes the
`54232 / yeisha123` account with a known password.

Register a user through the app, then promote it in the Neon SQL editor:

```sql
UPDATE users SET role = 'Admin' WHERE um_id = '<your UM ID>';
```

## 7. Custom domain

1. Cloudflare dashboard → **Workers & Pages** → `umerch` → **Settings** →
   **Domains & Routes** → **Add custom domain**.
2. Update the `APP_URL` secret to the custom domain and redeploy.

## 8. Existing uploads

Container disks are ephemeral. Files already in `storage/app/public` are not
part of the image. Re-upload products through the admin UI, or copy them once:

```powershell
rclone sync storage/app/public r2:umerch
```

## 9. Local container smoke test

```powershell
docker build -t umerch `
  --build-arg VITE_RECAPTCHA_SITE_KEY=your-site-key .

docker run --rm -p 8080:8080 --env-file .env.production umerch
```

Start from `.env.production.example` and fill in real test credentials. Do not
commit that file.

## 10. Operational notes

- **Scaling:** one singleton Durable Object keeps a single container, so
  sessions and writes stay consistent. `max_instances` is intentionally left
  unset.
- **Sleep:** the container stops after 5 minutes of inactivity
  (`INACTIVITY_TIMEOUT_MS` in `cloudflare/worker.ts`). The next request pays a
  cold start.
- **Disk:** ephemeral. All persistent uploads must go to R2.
- **Migrations:** `docker/entrypoint.sh` retries `php artisan migrate --force`
  up to 20 times to tolerate a sleeping Neon database.
- **Route caching:** `route:cache` is deliberately **not** run because
  `routes/admin-routes.php` contains closure routes. Config and view caches are
  safe and are enabled.
- **Cost:** containers bill per vCPU/memory/disk-second on top of Workers Paid.
  The `basic` (1 GiB / 4 GB disk) instance is used; scale-to-zero keeps idle
  usage near the plan floor.
- **Logs:** Laravel writes to stderr (`LOG_CHANNEL=stderr`); use
  `npx wrangler tail` or the Cloudflare dashboard.
- **Rollback:** `npx wrangler rollback` reverts the Worker version; container
  rollouts follow the Worker configuration.
