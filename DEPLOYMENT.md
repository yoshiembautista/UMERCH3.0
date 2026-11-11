# Deploying UM Merch (free tier)

Production runs the existing Laravel + Inertia app as a **Docker web service on
Render Free**, backed by **Neon Postgres**, **Cloudflare R2** for uploads, and
**Brevo** for OTP email. Total cost: $0.

```
Browser → Render (FrankenPHP container, free)
             ├── Neon Postgres        (free, no expiry)
             ├── Cloudflare R2        (free tier)  → uploads
             └── Brevo HTTPS API      (free tier)  → OTP email
```

Render's free plan cannot send SMTP (ports 25/465/587 are blocked), which is why
mail uses Brevo's HTTP API instead of SMTP. Nothing else about the app changes.

## 1. Prerequisites

- A GitHub account (Render deploys from a repository).
- A [Render](https://render.com) account. No credit card is required for the
  free plan.
- A [Neon](https://neon.tech) account.
- A Cloudflare account with R2 enabled. R2's free tier needs a payment method on
  file, but stays $0 within the free allowance (10 GB storage, no egress fees).
- A [Brevo](https://brevo.com) account (free: 300 emails/day).
- A **reCAPTCHA v2** site/secret pair. New-device login requires a real site key.

## 2. Push the repository

```powershell
git add -A
git commit -m "Add Render deployment"
git push origin main
```

`render.yaml` is a Blueprint: Render reads it and creates the service for you.

## 3. Create the database (Neon)

1. Create a project at <https://console.neon.tech>.
2. Copy the **direct** connection string (turn off "Pooled connection", or
   remove `-pooler` from the host), for example:

   ```
   postgresql://user:password@ep-xxx.region.aws.neon.tech/neondb?sslmode=require
   ```

   The container is a single long-lived process, so pooling is unnecessary and
   PgBouncer's transaction mode can interfere with the advisory lock Laravel
   uses while migrating.

3. Save it for `DB_URL`. Migrations run automatically on every container start,
   so there is nothing to run locally.

> Neon's free tier suspends idle computes. The container retries migrations for
> about a minute on cold start to tolerate this.

## 4. Create the R2 bucket

1. Cloudflare dashboard → **R2** → **Create bucket** (for example `umerch`).
2. **Manage R2 API Tokens** → create a token with **Object Read & Write** for
   that bucket. Save the access key ID and secret.
3. Enable a public URL: an **R2 custom domain** (recommended) or the `r2.dev`
   development URL.
4. Collect:

   | Variable | Value |
   | --- | --- |
   | `R2_ACCESS_KEY_ID` | token access key |
   | `R2_SECRET_ACCESS_KEY` | token secret |
   | `R2_BUCKET` | bucket name |
   | `R2_ENDPOINT` | `https://<ACCOUNT_ID>.r2.cloudflarestorage.com` |
   | `R2_PUBLIC_URL` | public bucket URL, no trailing slash |

Uploads go through Laravel's `public` disk, which switches to the S3 driver
automatically when `R2_BUCKET` is set (see `config/filesystems.php`).

## 5. Brevo (OTP email)

1. In Brevo: **Senders & IPs → Senders** → add and verify the address you will
   send from. Verifying a whole domain is better for deliverability.
2. **SMTP & API → API keys** → create a v3 API key.
3. Keep the key for `BREVO_API_KEY` and the verified address for
   `MAIL_FROM_ADDRESS`.

The transport is registered in `app/Providers/AppServiceProvider.php`
(`Mail::extend('brevo', …)`) and configured as the `brevo` mailer in
`config/mail.php`. It posts to `https://api.brevo.com/v3/smtp/email` over HTTPS,
so no SMTP ports are involved.

## 6. Create the Render service

1. Render dashboard → **New → Blueprint** → select this repository.
2. Render detects `render.yaml` and shows the `umerch` service.
3. Fill in every value marked `sync: false`:

   - `APP_KEY` — generate locally with `php artisan key:generate --show`
     (a `base64:…` value; it must stay stable or sessions and encrypted data
     break).
   - `DB_URL` — the Neon pooled connection string.
   - `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT`,
     `R2_PUBLIC_URL`.
   - `BREVO_API_KEY`, `MAIL_FROM_ADDRESS`.
   - `RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET_KEY`.

4. Click **Apply**. Render builds the `Dockerfile` remotely; no local Docker is
   needed. The first build takes several minutes.

`APP_URL` is filled automatically from `RENDER_EXTERNAL_URL`, and the health
check uses Laravel's `/up` route. Every push to the connected branch redeploys.

## 7. Verify

Open the service URL. Then check:

- The login page renders and the reCAPTCHA checkbox appears (the site key comes
  from Inertia shared props, so changing it does not require a rebuild).
- A login attempt on a new device delivers the OTP email (check Render logs if
  not).
- Uploading an image through the admin UI yields an R2 URL.
- `https://<service>.onrender.com/up` returns HTTP 200.

Render logs stream in the dashboard, or via the CLI:

```powershell
render logs --service umerch --tail
```

## 8. First admin user

**Do not run `db:seed` in production.** `DatabaseSeeder` hardcodes the
`54232 / yeisha123` account with a known password.

Register a user through the app, then promote it in the Neon SQL editor:

```sql
UPDATE users SET role = 'Admin' WHERE um_id = '<your UM ID>';
```

## 9. Custom domain

1. Render dashboard → the service → **Settings → Custom Domains** → add the
   domain and follow the DNS instructions.
2. `APP_URL` is wired to `RENDER_EXTERNAL_URL`, which keeps the `onrender.com`
   URL. If cookies or links must use the custom domain, set `APP_URL` manually
   in the service's environment and redeploy.

## 10. Existing uploads

Render's disk is ephemeral and files in `storage/app/public` are not part of the
image. Re-upload products through the admin UI, or copy them once to R2:

```powershell
rclone sync storage/app/public r2:umerch
```

## 11. Operational notes

- **Sleep:** free services sleep after 15 minutes without traffic and wake in
  about a minute. The 750 free instance-hours/month cover one always-on month.
- **Resources:** 0.1 CPU / 512 MB. `docker/Caddyfile` caps FrankenPHP at two
  threads and `docker/php.ini` lowers PHP's memory limit so the container does
  not get OOM-killed.
- **Disk:** ephemeral. All persistent uploads must go to R2.
- **Migrations:** `docker/entrypoint.sh` retries `php artisan migrate --force`
  up to 20 times to tolerate a sleeping Neon database.
- **Caching:** `php artisan optimize` is deliberately **not** run, because
  `routes/admin-routes.php` contains closure routes and `route:cache` fails.
  Config and view caches are safe and are enabled.
- **No queue worker:** `OtpMail` does not implement `ShouldQueue`, so mail is
  sent synchronously during the request. `QUEUE_CONNECTION=database` is set only
  so queued jobs would not be lost if added later.
- **Logs:** Laravel writes to stderr (`LOG_CHANNEL=stderr`); view them in the
  Render dashboard.
- **Rollback:** Render keeps previous deploys; use **Deploys → Rollback**.
- **Cost:** free as long as the app stays within the Render free instance hours,
  Neon free storage/compute, R2 free tier, and Brevo's 300 emails/day.

---

## Appendix: Cloudflare Containers (paid alternative)

The repository also contains a Cloudflare Containers setup
(`wrangler.jsonc`, `cloudflare/worker.ts`). It requires the **Workers Paid** plan
($5/month minimum) and **Docker Desktop** locally, so it is not part of the free
path.

```
Browser → Worker → Durable Object → Container :8080 (FrankenPHP)
```

Steps if you choose it:

```powershell
npx wrangler login

npx wrangler secret put APP_KEY
npx wrangler secret put APP_URL
npx wrangler secret put DB_URL
npx wrangler secret put R2_ACCESS_KEY_ID
npx wrangler secret put R2_SECRET_ACCESS_KEY
npx wrangler secret put R2_BUCKET
npx wrangler secret put R2_ENDPOINT
npx wrangler secret put R2_PUBLIC_URL
npx wrangler secret put BREVO_API_KEY
npx wrangler secret put MAIL_FROM_ADDRESS
npx wrangler secret put RECAPTCHA_SITE_KEY
npx wrangler secret put RECAPTCHA_SECRET_KEY

npm run deploy:cloudflare
```

Add `VITE_RECAPTCHA_SITE_KEY` to `containers[].images.app.build_vars` in
`wrangler.jsonc`, or leave it empty and rely on the runtime site key.

Differences from Render:

- One singleton Durable Object keeps a single container, so sessions and writes
  stay consistent; `max_instances` is intentionally unset.
- The container sleeps after 5 minutes (`INACTIVITY_TIMEOUT_MS`).
- The container listens on `:8080`; the Caddyfile falls back to that port when
  `PORT` is unset.
- Billing is per vCPU/memory/disk-second on top of Workers Paid. The `basic`
  (1 GiB / 4 GB disk) instance is used; scale-to-zero keeps idle usage near the
  plan floor.
- Rollback: `npx wrangler rollback`.
- You still need Neon, R2, Brevo, and reCAPTCHA exactly as above.
