# TiDB Cloud + Vercel Deployment Guide

## Overview
Deploy the GYF Welfare Management System to Vercel (serverless PHP via `vercel-php`) with TiDB Cloud as the MySQL-compatible database and Vercel Blob for photo storage.

> **Environment variables are managed in the Vercel dashboard**, NOT in `vercel.json` (the `env` block was removed so values can be changed without editing code or redeploying from a stale config). `vercel-php` reads them via `getenv()` at runtime.

---

## 1. TiDB Cloud Setup

1. Create a cluster at [tidbcloud.com](https://tidbcloud.com) (Developer tier is fine).
2. Note the connection details — **TiDB Cloud uses port `4000`**, not 3306:
   ```
   Host: <your-cluster>.prod.aws.tidbcloud.com
   Port: 4000
   User: <db-user>
   Password: <db-pass>
   Database: gyf_welfare
   ```
3. Create the database and a dedicated user:
   ```sql
   CREATE DATABASE gyf_welfare;
   CREATE USER 'welfare_user'@'%' IDENTIFIED BY '<secure_password>';
   GRANT ALL PRIVILEGES ON gyf_welfare.* TO 'welfare_user'@'%';
   ```
4. **Allow Vercel connections:** TiDB Cloud → Security/Network → add `0.0.0.0/0` (or Vercel's egress IPs) or the DB connection is refused.
5. **Import data:** run your exported `gyf_welfare.sql` against the new cluster so tables exist before first deploy.

---

## 2. Vercel Environment Variables (Dashboard)

Go to Vercel project → **Settings → Environment Variables** and add:

```
DB_HOST=<tidb host>
DB_PORT=4000
DB_NAME=gyf_welfare
DB_USER=welfare_user
DB_PASS=<secure_password>
DB_CHARSET=utf8mb4

APP_NAME=GYF Welfare Management System
APP_URL=https://<your-vercel-app>.vercel.app   # update to your real domain
UPLOAD_DIR=/tmp/uploads/

RESEND_API_KEY=re_xxxxxxxxxxxx
RESEND_FROM_EMAIL=noreply@<your-verified-domain>  # domain must be verified in Resend

BLOB_READ_WRITE_TOKEN=vercel_blob_rw_xxxx         # from Vercel Blob store
TREASURER_MEMBER_ID=GYF-ADMIN                      # optional, defaults to GYF-ADMIN
```

Notes:
- `DB_PORT` must be **4000** for TiDB Cloud.
- `APP_URL` drives absolute links, email/receipt image URLs, and the Blob photo fallback. Update it to the real domain.
- `RESEND_FROM_EMAIL` must use a **domain verified in Resend**, or emails fail (the app logs but does not crash).
- `BLOB_READ_WRITE_TOKEN` is optional: without it, photos fall back to local `/tmp` (ephemeral). With it, member photos persist via Vercel Blob.
- `config/database.php` fails closed (shows "Application configuration error") if `DB_HOST/DB_NAME/DB_USER` are missing.

---

## 3. Deploy

### Option A: Git (recommended)
1. Push to GitHub.
2. Vercel → New Project → Import repo.
3. Framework: **Other**; Build Command: empty; Output Directory: empty.
4. Add the env vars from Step 2.
5. Deploy.

### Option B: CLI
```bash
npm install -g vercel
vercel login
vercel --prod
```

The `vercel.json` already configures `vercel-php@0.6.0` for all `*.php` files and filesystem routing, so no extra build step is needed. The root `index.html` is the static landing page; PHP pages (e.g. `member/login.php`) are served by vercel-php.

---

## 4. Photo Storage (Vercel Blob)

`includes/blob_storage.php` wraps the Vercel Blob REST API (cURL, no npm needed). When `BLOB_READ_WRITE_TOKEN` is set:
- `uploadPhoto()` pushes the image to Blob and stores the **public Blob URL** in `members.passport_photo`.
- `displayPhotoUrl()` returns the Blob URL directly (or the local path in dev).
- All display spots (header, profile, dashboard, browse-members, receipts) use `displayPhotoUrl()`.

Local dev (no token) keeps using `uploads/photos/` as before — unchanged.

---

## 5. Sessions

Sessions work on Vercel's default `/tmp` session path. For multi-instance persistence you may later add Redis/Vercel KV, but it is not required for launch.

---

## 6. Android (Capacitor) — separate from Vercel

`www/` is the Capacitor `webDir` snapshot (already synced). Build the native app with:
```bash
npx cap sync android
npx cap open android
```
Update `capacitor.config.json` → `server.url` to your real Vercel domain so the WebView uses the live backend. `www/` is gitignored (build artifact), so it is not pushed to GitHub.

---

## 7. Pre-deploy checklist

- [ ] TiDB cluster created; `gyf_welfare.sql` imported
- [ ] TiDB allows Vercel IPs (`0.0.0.0/0` or Vercel egress)
- [ ] All Vercel env vars set (esp. `DB_PORT=4000`, `APP_URL`)
- [ ] Resend sender domain verified
- [ ] `BLOB_READ_WRITE_TOKEN` set (recommended)
- [ ] `config/database.php` is committed (it is — contains no secrets)
- [ ] `.env` is NOT committed (gitignored)
- [ ] `capacitor.config.json` `server.url` updated for Android

---

## 8. Troubleshooting

- **"Application configuration error"** → a required DB env var (`DB_HOST/DB_NAME/DB_USER`) is missing in Vercel.
- **DB connection failed** → wrong host/port (use 4000), or TiDB IP allowlist blocks Vercel.
- **Photos missing after upload on Vercel** → `BLOB_READ_WRITE_TOKEN` not set; falls back to ephemeral `/tmp`.
- **Emails not arriving** → Resend domain not verified, or `RESEND_API_KEY` invalid (check Vercel function logs).
- **CSP / asset 404s** → `APP_URL` mismatch; update it to the real domain.
