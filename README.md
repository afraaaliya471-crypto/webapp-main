# Tanseeq Investment — Web App

A Laravel 12 procurement and project-management portal for internal company use, with Microsoft SSO authentication and D365 Business Central integration.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2+, Laravel 12 |
| Frontend | Vite 7, Tailwind CSS 4, Blade |
| Database | PostgreSQL |
| Cache / Session / Queue | Redis (Upstash in dev, Azure Cache in prod) |
| Authentication | Microsoft Entra ID (OAuth 2.0 via Socialite) |
| External API | Dynamics 365 Business Central |

---

## Local Setup

### 1. Prerequisites

- PHP 8.2+ with extensions: `pdo_pgsql`, `redis`, `mbstring`, `xml`, `curl`
- Composer 2
- Node.js 20+ and npm
- PostgreSQL 15+
- A Redis instance (local, [Upstash](https://upstash.com), or [Memurai](https://www.memurai.com) on Windows)

### 2. Install dependencies

```bash
composer install
npm install
```

### 3. Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and fill in:

- **Database** — `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- **Redis** — `REDIS_HOST`, `REDIS_PASSWORD`, and set `REDIS_SCHEME=tls` for Upstash
- **Azure OAuth** — `AZURE_TENANT_ID`, `AZURE_CLIENT_ID`, `AZURE_CLIENT_SECRET`
- **OAuth callback** — `MICROSOFT_REDIRECT_URI="${APP_URL}/auth/microsoft/callback"`
- **Email domain restriction** — `MICROSOFT_ALLOWED_DOMAIN=yourcompany.com`

### 4. Database

```bash
php artisan migrate
php artisan db:seed
```

### 5. Build assets and serve

```bash
npm run dev          # dev server with hot reload
php artisan serve    # or use Laravel Herd / Valet
```

---

## Microsoft Entra App Registration

1. Go to [Azure Portal → App Registrations](https://portal.azure.com/#blade/Microsoft_AAD_RegisteredApps/ApplicationsListBlade) and create a new app.
2. Under **Authentication**, add a redirect URI:
   ```
   https://your-domain.com/auth/microsoft/callback
   ```
3. Under **Certificates & secrets**, create a client secret and copy it to `AZURE_CLIENT_SECRET`.
4. Copy the **Application (client) ID** to `AZURE_CLIENT_ID`.
5. Copy the **Directory (tenant) ID** to `AZURE_TENANT_ID`.

The app requests `openid`, `profile`, `email`, and `User.Read` scopes — no admin consent required.

---

## Authentication Flow

```
User clicks "Sign in with Microsoft"
  → GET /auth/microsoft          (throttled: 15 req/min per IP)
  → Redirect to Microsoft login
  → GET /auth/microsoft/callback (throttled: 15 req/min per IP)
  → Email domain validated against MICROSOFT_ALLOWED_DOMAIN
  → User created or matched in the local DB
  → Session started → redirect to /dashboard
```

---

## Key Configuration Files

| File | Purpose |
|---|---|
| `config/services.php` | Microsoft OAuth settings (reads `AZURE_*` and `MICROSOFT_*` env vars) |
| `config/database.php` | PostgreSQL and Redis connections |
| `bootstrap/app.php` | Global middleware (security headers, CSRF, aliases) |
| `app/Http/Middleware/SecurityHeadersMiddleware.php` | X-Frame-Options, CSP, HSTS, etc. |

---

## Role-Based Access Control

Permissions are mapped to menu keys via the Masters → Settings → Menu Access admin screen. Role assignments are per-company membership. Super admins and company admins bypass all permission checks.

---

## Production Checklist

- [ ] Set `APP_ENV=production` and `APP_DEBUG=false`
- [ ] Set `SESSION_ENCRYPT=true`
- [ ] Use a strong `APP_KEY` (run `php artisan key:generate`)
- [ ] Use Azure Cache for Redis (or a password-protected Upstash instance)
- [ ] Set `MICROSOFT_ALLOWED_DOMAIN` to your organisation's email domain
- [ ] Enable HTTPS and ensure HSTS is served (`Strict-Transport-Security`)
- [ ] Run `php artisan config:cache && php artisan route:cache && php artisan view:cache`
- [ ] Set up a queue worker: `php artisan queue:work --sleep=3 --tries=3`
