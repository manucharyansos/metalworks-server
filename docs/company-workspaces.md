# Company workspaces

An enterprise (`Company`) owns workshops (`Factory`) and every business record. A shared `User` identity has a `CompanyMembership` in each authorized enterprise, with a separate role, workshop, active flag and permission assignments. Deleting an employee or client revokes their membership in the selected company while preserving the account and historical records.

The initial migration assigns existing records and account permissions to MetalWorks. Existing installation administrators receive `is_platform_admin`; new company administrators do not. Public registration submits a company-specific request; an account and membership are created only after manager approval. The platform flag is not mass assignable or editable through employee/profile requests.

## Deployment on an existing installation

Back up the database and `storage/app` before applying the migration. Keep the workspace closed while deploying the API and privatizing old uploads. The company migration deliberately rejects automatic rollback: restore the pre-deployment backup to undo it.

Run from the Laravel API directory:

```sh
(
set -e
php artisan down
git pull origin master
composer install --no-dev --optimize-autoloader --no-scripts --no-interaction
php artisan optimize:clear --no-interaction
php artisan package:discover --no-interaction
php artisan migrate --force --no-interaction
php artisan files:privatize-workspaces --apply --no-interaction
php artisan optimize:clear --no-interaction
php artisan up
)
```

If any command fails, fix the reported issue before reopening the application. Do not rerun seeders on the existing installation: account seeders synchronize initial passwords from environment settings.

The subshell keeps an interactive hosting terminal open if a command fails. Copy only the commands, without terminal prompts or output. Composer's automatic `@php` script requires starting another process; hosts with `proc_open` disabled can reject that step after dependencies are already installed. `--no-scripts` avoids it, and the explicit cache clear and package discovery perform the required Laravel setup in the current PHP process. These direct Artisan commands were verified with `proc_open` disabled. This does not override the hosting provider's PHP restrictions.

If the company migration stopped with MySQL error 121 on a foreign key named `1`, update to the corrected code and rerun `php artisan migrate --force --no-interaction` while maintenance mode is enabled. The migration resumes existing company tables and ownership columns, adds separately named company indexes and foreign keys, and backfills the original account memberships and permissions. It preserves existing owners, password hashes, modified memberships and explicit permission changes. Do not delete the partially created tables or mark the migration completed manually.

`files:privatize-workspaces` without `--apply` is read-only. Apply mode copies DB-backed uploads and abandoned files from known business directories, compares SHA-256 hashes, and removes only matching public copies. A mismatched private copy is never overwritten; missing uploads and mismatches result in a nonzero exit code. Unrelated public website assets remain public. Secure downloads serve the private disk only, including legacy paths. Any independent web-server aliases to upload directories must also point outside the public document root.

Deploy the matching generated client archive to `metalworks.am/work/` after the API update. Then create enterprises in **Companies** and assign per-enterprise employee access in **Employees**. New companies get default workshop, upload-policy and workflow-status templates, without another company's orders, customers or staff data.

## Request isolation

Authenticated business requests select their enterprise with `X-Company-ID`. Embedded file links use `company_id` in the query; the header takes precedence. Selection is always checked against an active company and membership before route model binding. A platform administrator may select any active company. With multiple companies, a business request without an explicit selection returns 409; `/api/user` can bootstrap the first authorized company. Company administration and logo endpoints check access independently.

Models and business pivots receive a company scope and immutable ownership. The tenant-aware validation presence verifier checks foreign IDs and unique values within the selected company; identity emails stay globally unique. Parent references are additionally checked during model saves. Raw dashboard aggregates explicitly constrain the company. Shared role/permission-catalog mutation and public-site visitor statistics require platform administration.

The client stores selection in session storage for the signed-in user, so tabs may select different companies. Switching verifies the target identity and permissions, warns about drafts, waits for pending business writes and loads a fresh document. This removes previous company Vuex state, component state, file blobs and pending reads before rendering the next workspace.

Console business operations should explicitly run inside `CompanyContext::run($company, $callback)`. Installation seeders operate on MetalWorks. Do not use unscoped queries in request handlers except the access-checked company directory and platform staff options.

## Verification

```sh
php artisan test --filter=Company
```

Tests cover populated-data backfill, interrupted and repeated migrations, company directories and header tampering, route bindings, dashboards, private downloads, independent numbering/group codes, role/workshop/permission isolation, access checkboxes, revocation, public registration, shared client identities and hash-verified privatization. CI separately runs migration recovery and registration approval tests on MySQL 8/InnoDB: Laravel 10's SQLite grammar ignores foreign keys added to existing tables and cannot verify these production DDL statements. The client regression suite checks request headers, account-scoped selection, draft cancellation, pending writes, page reload, trailing-slash authentication routes and protected file URLs.

## Registration requests — 2026-10-08

Deploy `2026_10_08_160000_create_registration_requests_table.php` using the deployment commands above before uploading the matching client. This additive migration does not modify existing accounts or memberships. Do not run `migrate:fresh`, reseed production, or remove company tables.

Public `GET /api/registration/companies` returns only active company IDs and names, because both client and employee applicants must choose the destination when multiple enterprises accept requests. A sole active company is assigned automatically. Workshops, staff and all business records remain private. The separate public branding endpoint described below exposes only active names and brand logos. This public endpoint and `POST /api/register` ignore stale company-selection headers.

`POST /api/register` accepts `name`, `email`, `password`, `password_confirmation`, `company_id` and boolean `is_employee` (defaults to false). Employees also need `last_name` and informational `job_title`; `patronymic` is optional. Clients need neither surname, patronymic nor job title. Job title and any caller-provided role/platform-admin fields never grant access. The response is HTTP 202 with `{ "status": "pending", "message": "Ձեր հարցումն ընդունված է։" }`. It creates no user, membership, session or token. Passwords are hashed in the private request table and omitted from all API responses.

Managers and company administrators review requests from every active company where they hold an active manager/admin position through `/api/registration-requests`, `/options`, `/{id}/approve` and `/{id}/reject`. Platform administrators can review all active companies. The list includes company names and supports an optional authorized `company_id` filter. Approval and rejection use the application's company context, even when another workspace is selected. Employees require explicit permitted positions; laser, bend and powder operators also require workshops from that company. Clients always receive `authenticatedUser`. Non-platform managers cannot assign administrators. Approval creates a user or adds an already verified shared account to the company, then creates the company-specific worker/client profile. Individual employee permissions are assigned separately through the existing employee-permissions page. Reactivating a membership clears its old job grants without affecting another company's access.

Existing email owners must prove their current password when applying to another enterprise. Approval preserves their name, password and other memberships. If a new account for the email appeared after an application, the manager cannot approve that application until its owner resubmits with the account's current password. Repeated review returns 409. Rejection creates no account and clears the stored password hash; an applicant can resubmit. Pending retries for an unknown email require the same password, preventing another visitor from replacing the applicant's credentials.

Run `php artisan test --filter=RegistrationRequestsTest` for the approval, validation, password, rate-limit and company-isolation regression suite. Approval-email delivery was added in the follow-up described below.


## Branding, separate request types, email and manager company access — 2026-10-09

Apply `2026_10_09_020000_preserve_registration_types_and_mail_status.php` before deploying the matching client. It preserves all existing applications and accounts, replaces the application key with `(company_id, email, type)` and records mail delivery status. It is resumable after interrupted MySQL DDL. Previously overwritten applications cannot be reconstructed from the surviving row; do not fabricate them or recreate accounts.

The login and registration panels load active companies from public `GET /api/workspace/brands`. The response contains only ID, name, slug and a public brand-logo URL. `GET /api/workspace/brands/{company}/logo` serves only the active company's uploaded branding image. A closed company's logo returns 404. Private company directories, workshops, employees, uploads and orders keep their existing authorization. The default MetalWorks logo is bundled as a hashed frontend asset, and companies without a usable logo get their own name initials.

Client and employee applications with the same company/email are separate rows. Retry updates only the same application type after credential validation. A shared email still identifies one account: approving one application does not create a second account from the other application, overwrite an existing password or silently grant a different role. An account that appeared after an unverified application still requires its owner's current password before it can be reused.

Approval sends a HY/RU/EN email using the applicant's submission language, after committing the account and membership transaction. Configure the server's existing `MAIL_*` settings for real SMTP delivery, including a valid sender. `WORKSPACE_URL` defaults to `https://metalworks.am/work`; it controls the localized sign-in link. SMTP operations have a bounded timeout, default 8 seconds. `log`/`array` mailers and failover chains containing them are reported as unconfigured, never as sent. `sent` means the mail transport accepted the message; inbox delivery still depends on the mail provider.

A mail failure keeps approval successful and visible. Approved cards display the notification status. The matching client has no manual send-email button: new approvals send automatically after the transaction commits. The compatibility `POST /api/registration-requests/{id}/notify` endpoint remains access-checked for older clients; repeating a successfully sent notification does not send another email. Pending or rejected applications cannot be notified, and revoked company access cannot be notified. The migration sends no historical messages automatically. Email headers, footers and body use the destination company name; the welcome text contains no manager wording or password.

After registration, the **Companies** action is available from employee lists, client lists and approved applications. `GET/PUT /api/company-access/{user}` operates on an active account in the selected company, or in an optional authorized `source_company_id` supplied by a request card. This source does not change the actor's selected workspace. The platform administrator can manage all active companies. A company manager/admin can manage only destinations where they already hold an active manager/admin membership. An employee-only membership does not grant destination management. Only those companies and their workshops appear in the editor.

Companies, employee positions and operator workshops use expandable checkbox lists. Each employee destination has independent positions and workshops, with one primary assignment. Workshops belong to a production position; selecting another company's workshop is rejected. The source company cannot be unchecked, but its employee assignments can be edited in this dialog. Protected administrator access remains read-only for non-platform managers.

An account that is a client in the source company gets a company-only editor. Its response contains no selectable roles or workshops, and each access row contains only `company_id` and `enabled`. Saving a new destination grants only client access. Attempts to submit employee roles or workshops through this client flow are rejected. If this shared identity already has employee access in another company, that membership is shown read-only and preserved. Employee editors retain support for existing mixed client/employee memberships without silently changing them.

The endpoint checks every destination again on save and applies all changes in one transaction. It preserves the shared name/password, unselected memberships, existing company contacts, historical records and other companies' individual permission grants. Reactivation or an assignment change clears only the destination's old grants. The checkbox/all-managed-requests follow-up adds no migrations or Composer dependencies; deployments that already applied the preceding migrations need only the code update and cache clear before the matching frontend archive.
