# EMPOWER — Supabase Configuration

This project uses **Supabase (PostgreSQL)** as its database and **Supabase Storage**
for applicant documents. There is no local MySQL/XAMPP dependency.

## What you need to collect

From your Supabase dashboard, gather these values:

| Value | Where to find it |
| --- | --- |
| Database password | Shown once when you created the project. If lost: *Project Settings → Database → Reset database password* |
| Session pooler host | *Project Settings → Database → Connection string → **Session pooler*** tab |
| Project reference ID | The `xxxxxxxxxxxx` part of your project URL |
| S3 access key ID | *Project Settings → Storage → S3 Access Keys → New access key* |
| S3 secret access key | Shown once when you create the S3 access key |
| S3 endpoint + region | *Project Settings → Storage → S3 Connection* |

### Why S3 credentials rather than the `service_role` key

Supabase Storage exposes an S3-compatible endpoint. Pointing Laravel's built-in
`s3` filesystem driver at it means document uploads, downloads, and expiring
signed URLs all use the standard `Storage::disk()` API with no custom HTTP client
to maintain. It also avoids putting the `service_role` key — which bypasses every
Row Level Security policy in the project — into application code paths that only
need file access.

### Why the Session pooler, specifically

Supabase exposes three connection modes. Only one is correct for Laravel:

- **Direct connection** (`db.<ref>.supabase.co:5432`) — IPv6-only on the free
  tier. Most Philippine residential ISPs are IPv4-only, so this will time out.
- **Session pooler** (`aws-0-<region>.pooler.supabase.com:5432`) — IPv4, holds a
  session per connection, and fully supports prepared statements. **Use this.**
- **Transaction pooler** (port `6543`) — does not support prepared statements,
  which PDO and Laravel migrations rely on. Using it causes intermittent
  "prepared statement already exists" failures.

## Filling in the environment file

Open `backend/laravel/.env` and replace the placeholder values below. Note that
the pooler username is **not** plain `postgres` — it is `postgres.<project-ref>`.

```dotenv
DB_CONNECTION=pgsql
DB_HOST=aws-0-ap-southeast-1.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.YOUR_PROJECT_REF
DB_PASSWORD=YOUR_DATABASE_PASSWORD
DB_SSLMODE=require

FILESYSTEM_DISK=supabase
SUPABASE_S3_ENDPOINT=https://YOUR_PROJECT_REF.storage.supabase.co/storage/v1/s3
SUPABASE_S3_REGION=ap-southeast-1
SUPABASE_S3_BUCKET=applicant-documents
SUPABASE_S3_ACCESS_KEY_ID=YOUR_S3_ACCESS_KEY_ID
SUPABASE_S3_SECRET_ACCESS_KEY=YOUR_S3_SECRET_ACCESS_KEY
```

`DB_SSLMODE=require` is not optional. Supabase refuses unencrypted connections,
and omitting it produces a misleading "connection refused" error.

## Storage bucket

Create a bucket named `applicant-documents` with **Public access disabled**.

Applicant requirements include birth certificates, police clearances, and medical
results. Under the Data Privacy Act of 2012 (RA 10173) these are personal and
sensitive personal information, so the bucket must not be publicly readable. The
application serves them through short-lived signed URLs generated server-side,
which means the `service_role` key never reaches the browser.

## TLS certificate authority bundle (Windows)

PHP on Windows ships without a certificate authority bundle, so cURL cannot
verify TLS certificates and every Supabase Storage call fails with:

```
cURL error 60: SSL certificate ... unable to get local issuer certificate
```

The database connection is unaffected, which makes this confusing to diagnose:
`pgsql` performs its own TLS handling, so the application appears healthy right
up until the first file upload.

Fix it by downloading a CA bundle and pointing PHP at it:

```powershell
Invoke-WebRequest -Uri 'https://curl.se/ca/cacert.pem' -OutFile 'C:\php83\extras\ssl\cacert.pem'
```

then in `php.ini`:

```ini
curl.cainfo = "C:\php83\extras\ssl\cacert.pem"
openssl.cafile = "C:\php83\extras\ssl\cacert.pem"
```

This is already configured on the development machine. It will need repeating on
any other Windows machine that runs the backend. Linux hosts, including Railway
and Render, use the system CA store and need no equivalent step.

## Security notes

1. `.env` is listed in `.gitignore` and must never be committed.
2. Keep the S3 secret access key on the Laravel server only. It is never sent to
   the React frontend; the browser only ever receives short-lived signed URLs
   generated server-side.
3. If a credential is exposed, revoke and reissue it from *Project Settings →
   Storage → S3 Access Keys*, then update `.env`.
4. The free tier pauses a project after roughly a week of inactivity. Open the
   dashboard before a demo or defense so the database is awake — a paused project
   refuses connections, which looks like a broken application.
