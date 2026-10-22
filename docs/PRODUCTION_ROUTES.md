# Production routes

A task keeps its overall deadline. Each real workshop job can also have a deadline, operator, files with quantities, priority and completion evidence requirements. INFO, PDF and Engineering remain file categories or positions, never production stages. Jobs and their dependencies remain scoped to the selected company and task.

At creation, `production_route` contains `mode` (`sequential`, `parallel`, `custom`) and ordered `stages`. Each stage contains a `factory_id`, nullable `deadline` (`Y-m-d`) and `predecessor_factory_ids`. Sequential mode derives one predecessor from the stage order; parallel mode clears predecessors; custom mode supports multiple predecessors and joins. Every current workshop must appear exactly once. Self-dependencies, foreign stages and cycles are rejected transactionally.

Existing tasks without a route retain their current behavior. The migration adds nullable JSON `predecessor_ids`, an integer `route_position`, and nullable `ready_at`; it does not delete or rewrite existing jobs. The legacy `depends_on_id` remains readable and mirrors the first predecessor for older clients. An explicit empty JSON list means no dependencies.

`PUT /api/tasks/{order}/production-route` accepts `production_route` plus `previous_route`, an ordered snapshot containing `factory_id`, sorted `predecessor_factory_ids`, and nullable `deadline`. A changed snapshot returns 409. The creator engineer with `orders.update`, an admin or a manager can edit the route. Dependencies of started jobs and deadlines of completed, submitted or canceled jobs are preserved. Pending stages can be changed through mixed mode even after another stage starts. File-selection edits remap dependencies to recreated job IDs using workshop IDs.

`POST /api/tasks/{order}/workshops` accepts multiple existing job IDs in `predecessor_ids`, or the legacy `depends_on_id`. It can route an existing file to another workshop while preserving the original project file, its revision, quantities and explicit reference-file visibility.

A stage is ready only after **all** its predecessors complete. If evidence requires engineer approval, submitting the evidence does not release the next stage. Queued work cannot be accepted, started or completed through the API. A transition to ready stores an in-app `step.ready` notification for the assigned operator, or eligible active operators of that workshop if unassigned. `ready_at` deduplicates retries under the same task row lock used by status updates. Route edits notify assigned operators. Operator payloads contain unfinished predecessor workshop names, without predecessor file or operator relations.

## Deployment

Deploy the server before the new frontend ZIP. From the existing API directory:

```bash
git pull --ff-only origin master
composer install --no-dev --optimize-autoloader --no-scripts
php artisan package:discover --no-interaction
php artisan migrate --force --no-interaction
php artisan optimize:clear --no-interaction
php artisan up
```

The explicit Composer/package discovery commands support the existing host with `proc_open` disabled. There is no need to put the application into maintenance mode for these additive columns. Then extract the frontend ZIP contents into the existing `/work` directory, including its static server configuration. Preserve the production API `.env` and uploaded files.
