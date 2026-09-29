<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Factory;
use App\Models\FactoryFileExtension;
use App\Models\FactoryOrder;
use App\Models\Material;
use App\Models\Order;
use App\Models\Pmp;
use App\Models\PmpFiles;
use App\Models\RemoteNumber;
use App\Models\Role;
use App\Models\User;
use App\Models\Worker;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class ActivityObserver
{
    private const HIDDEN_FIELDS = [
        'password',
        'remember_token',
        'token',
        'email_verification_code',
        'email_verification_expires_at',
        'password_reset_token',
    ];

    private const PRIVATE_VALUE_FIELDS = [
        'email',
        'phone',
        'second_phone',
        'address',
    ];

    public function created(Model $model): void
    {
        $this->record($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->record($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted');
    }

    private function record(Model $model, string $event): void
    {
        if (app()->runningInConsole() || !auth()->check() || !Schema::hasTable('activity_logs')) {
            return;
        }

        $actor = auth()->user();
        $actorRole = $actor?->role?->name;
        if (!$actorRole || in_array($actorRole, ['authenticatedUser', 'guestUser'], true)) {
            return;
        }

        $descriptor = $this->descriptor($model);
        if ($descriptor === null) {
            return;
        }

        $action = $this->actionFor($model, $event, $descriptor['prefix']);
        $meta = array_merge(
            ['source' => 'model_observer'],
            $descriptor['meta'] ?? []
        );

        if ($event === 'updated') {
            $changes = $this->safeChanges($model);
            if ($changes === []) {
                return;
            }

            $meta['changed_fields'] = array_keys($changes);
            $meta['changes'] = $changes;
        }

        try {
            ActivityLog::create([
                'user_id' => $actor->id,
                'category' => $descriptor['category'],
                'action' => $action,
                'method' => request()?->method(),
                'route' => request()?->path(),
                'subject_type' => $descriptor['type'],
                'subject_id' => $model->getKey() !== null ? (string) $model->getKey() : null,
                'subject_label' => $descriptor['label'],
                'description' => null,
                'meta' => $meta,
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the business action that already succeeded.
            report($e);
        }
    }

    private function descriptor(Model $model): ?array
    {
        return match (true) {
            $model instanceof Order => [
                'category' => 'orders',
                'prefix' => 'order',
                'type' => 'order',
                'label' => $model->name ?: 'Order #' . $model->getKey(),
                'meta' => ['status' => $model->status],
            ],
            $model instanceof PmpFiles => [
                'category' => 'files',
                'prefix' => 'file',
                'type' => 'pmp_file',
                'label' => $model->original_name ?: 'File #' . $model->getKey(),
                'meta' => [
                    'pmp_id' => $model->pmp_id,
                    'remote_number_id' => $model->remote_number_id,
                    'factory_id' => $model->factory_id,
                    'file_type' => $model->file_type,
                ],
            ],
            $model instanceof Pmp => [
                'category' => 'projects',
                'prefix' => 'project',
                'type' => 'pmp',
                'label' => trim(implode(' · ', array_filter([$model->group, $model->group_name]))) ?: 'PMP #' . $model->getKey(),
                'meta' => ['group' => $model->group],
            ],
            $model instanceof RemoteNumber => [
                'category' => 'projects',
                'prefix' => 'project.subgroup',
                'type' => 'pmp_subgroup',
                'label' => trim(implode(' · ', array_filter([$model->remote_number, $model->remote_number_name]))) ?: 'Subgroup #' . $model->getKey(),
                'meta' => ['pmp_id' => $model->pmp_id, 'remote_number' => $model->remote_number],
            ],
            $model instanceof FactoryOrder => [
                'category' => 'production',
                'prefix' => 'production',
                'type' => 'factory_order',
                'label' => 'Order #' . $model->order_id . ' · Factory #' . $model->factory_id,
                'meta' => [
                    'order_id' => $model->order_id,
                    'factory_id' => $model->factory_id,
                    'operator_id' => $model->operator_id,
                    'status' => $model->status,
                ],
            ],
            $model instanceof Worker => [
                'category' => 'staff',
                'prefix' => 'worker',
                'type' => 'worker',
                'label' => $model->user?->name ?: 'Worker #' . $model->getKey(),
                'meta' => ['user_id' => $model->user_id],
            ],
            $model instanceof User => [
                'category' => 'staff',
                'prefix' => 'user',
                'type' => 'user',
                'label' => $model->name ?: 'User #' . $model->getKey(),
                'meta' => ['role_id' => $model->role_id, 'factory_id' => $model->factory_id],
            ],
            $model instanceof Client => [
                'category' => 'clients',
                'prefix' => 'client',
                'type' => 'client',
                'label' => trim(implode(' ', array_filter([$model->name, $model->last_name]))) ?: ($model->company_name ?: 'Client #' . $model->getKey()),
                'meta' => ['user_id' => $model->user_id, 'type' => $model->type],
            ],
            $model instanceof Material => [
                'category' => 'materials',
                'prefix' => 'material',
                'type' => 'material',
                'label' => $model->name ?: 'Material #' . $model->getKey(),
                'meta' => [],
            ],
            $model instanceof Factory => [
                'category' => 'settings',
                'prefix' => 'factory',
                'type' => 'factory',
                'label' => $model->name ?: 'Factory #' . $model->getKey(),
                'meta' => [],
            ],
            $model instanceof FactoryFileExtension => [
                'category' => 'settings',
                'prefix' => 'file_type',
                'type' => 'factory_file_extension',
                'label' => $model->extension ?: 'File type #' . $model->getKey(),
                'meta' => ['factory_id' => $model->factory_id],
            ],
            $model instanceof Role => [
                'category' => 'access',
                'prefix' => 'role',
                'type' => 'role',
                'label' => $model->value ?: ($model->name ?: 'Role #' . $model->getKey()),
                'meta' => [],
            ],
            default => null,
        };
    }

    private function actionFor(Model $model, string $event, string $prefix): string
    {
        if ($model instanceof PmpFiles) {
            return match ($event) {
                'created' => 'file.uploaded',
                'deleted' => 'file.deleted',
                default => 'file.updated',
            };
        }

        if ($model instanceof FactoryOrder && $event === 'updated') {
            if ($model->wasChanged('status')) {
                return 'production.status_changed';
            }
            if ($model->wasChanged('operator_id')) {
                return 'production.operator_changed';
            }
        }

        return $prefix . '.' . $event;
    }

    private function safeChanges(Model $model): array
    {
        $result = [];
        $changes = $model->getChanges();

        foreach ($changes as $field => $newValue) {
            if (in_array($field, ['created_at', 'updated_at'], true) || in_array($field, self::HIDDEN_FIELDS, true)) {
                continue;
            }

            if (in_array($field, self::PRIVATE_VALUE_FIELDS, true)) {
                $result[$field] = ['changed' => true];
                continue;
            }

            $result[$field] = [
                'from' => $this->normalizeValue($model->getOriginal($field)),
                'to' => $this->normalizeValue($newValue),
            ];
        }

        return $result;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value) || is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            return mb_substr($value, 0, 500);
        }

        return mb_substr((string) $value, 0, 500);
    }
}
