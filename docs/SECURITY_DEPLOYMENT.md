# Security hardening deployment preflight

This checklist is intentionally conservative. It keeps the current public-file copies available until every active client flow uses authenticated file access.

## 1. Before deployment

Take a normal production database/files backup, then run the read-only checks from the current production release if/when the new commands are available in a release candidate:

```bash
php artisan order-numbers:audit
php artisan files:security-audit
```

Do not add a unique index to `order_numbers.number` until the order-number audit reports no duplicates.

## 2. Production configuration checks

Do not copy repository `.env` files over the production environment.

Confirm the production environment has the correct values for the real deployment, especially:

- `APP_ENV=production`
- `APP_URL`
- `FRONTEND_URL` / `FRONTEND_PASSWORD_RESET_URL`
- `CORS_ALLOWED_ORIGINS` when the frontend origin differs from the safe repository fallback
- `SESSION_DOMAIN`
- `SESSION_SECURE_COOKIE=true` (the application also defaults this to true in production when not explicitly configured)
- Sanctum stateful-domain configuration required by the deployed frontend/backend topology

Confirm the web server/proxy forwards HTTPS correctly. Do not enable trust-all proxies without verifying that the origin server cannot be reached in a way that lets clients spoof forwarding headers.

## 3. Backend first

Deploy the backend hardening before the client begins using `/api/secure-files/*`.

Run database migrations:

```bash
php artisan migrate --force
php artisan optimize:clear
```

The order-number sequence migration must be present before normal traffic relies on the new generator.

Verify:

```bash
php artisan order-numbers:audit
php artisan files:security-audit
```

Also confirm the Laravel scheduler is invoked by the server (normally every minute), because expired Sanctum-token pruning is scheduled by the application.

## 4. Smoke-test backend authentication and authorization

Verify at minimum:

- login / logout
- password reset
- current-user endpoint
- manager order CRUD according to permissions
- engineer can only see/update/delete orders they created
- factory worker sees only the assigned factory/operator work
- unauthorized file IDs return 403/404
- authorized `/api/secure-files/pmp/{id}` and `/api/secure-files/order/{id}` requests work

## 5. Deploy the client

Only after the secure-file backend routes are live, deploy client code that uses those routes for previews/downloads.

Verify login, manager, engineer and factory views before continuing.

## 6. Stage files privately — non-destructive

First run the dry-run (default):

```bash
php artisan files:stage-private
```

If the report is expected and there are no missing/unsafe records, copy files into private storage:

```bash
php artisan files:stage-private --apply
```

This command only copies files to private storage and verifies the private copy. It does **not** delete public files, so legacy client flows remain available during the transition.

Re-run:

```bash
php artisan files:security-audit
```

## 7. Public-file removal is a separate later phase

Do not remove `storage/app/public` copies or the public storage symlink for DB-backed protected files until code search and production smoke tests confirm there are no active direct `/storage/...` links.

Public-copy cleanup should be implemented as a separate, explicit, auditable command after the client migration is complete. Keeping this as a separate phase preserves rollback safety.
