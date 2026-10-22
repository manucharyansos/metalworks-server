<?php

namespace App\Support;

use App\Models\{Factory, FactoryOrder, Order, Pmp, SelectedFile};
use Illuminate\Validation\ValidationException;

final class TaskWorkflow
{
    public static function rules(): array
    {
        return [
            'confirmation_required' => 'sometimes|boolean',
            'confirmation_method' => 'nullable|required_if:confirmation_required,true|in:photo,text,photo_text',
            'file_routing' => 'sometimes|array|max:1000',
            'file_routing.*.file_id' => 'required|integer|distinct',
            'file_routing.*.factory_ids' => 'present|array',
            'file_routing.*.factory_ids.*' => 'required|integer',
            'reference_file_visibility' => 'sometimes|array',
            'reference_file_visibility.*.file_id' => 'required|integer|distinct',
            'reference_file_visibility.*.factory_ids' => 'present|array',
            'reference_file_visibility.*.factory_ids.*' => 'required|integer',
            'workshop_settings' => 'sometimes|array|max:200',
            'workshop_settings.*.factory_id' => 'required|integer|distinct|exists:factories,id',
            'workshop_settings.*.confirmation_required' => 'required|boolean',
            'workshop_settings.*.confirmation_method' => 'nullable|required_if:workshop_settings.*.confirmation_required,true|in:photo,text,photo_text',
            'workshop_settings.*.deadline' => 'nullable|date_format:Y-m-d',
            'workshop_settings.*.priority' => 'required|in:normal,high,urgent',
        ] + ProductionRoute::rules();
    }

    public static function evidenceMethods(?string $method): array
    {
        return match ($method) {
            'photo' => ['photo'],
            'text' => ['text'],
            'photo_text' => ['photo', 'text'],
            default => [],
        };
    }

    public static function settings(array $data): array
    {
        $required = (bool) ($data['confirmation_required'] ?? false);
        return ['confirmation_required' => $required, 'confirmation_method' => $required ? $data['confirmation_method'] : null];
    }

    public static function destinations($file, array $routing = []): array
    {
        $override = collect($routing)->firstWhere('file_id', $file->id);
        if ($override !== null) return array_values(array_unique(array_map('intval', $override['factory_ids'])));
        return $file->routing_factory_id ? [(int) $file->routing_factory_id] : [];
    }

    public static function workshopIds(Pmp $pmp, array $selected, array $routing = []): array
    {
        return $pmp->files->whereIn('id', array_column($selected, 'id'))->reject(fn ($file) => $file->is_reference_file)
            ->flatMap(fn ($file) => self::destinations($file, $routing))->unique()->values()->all();
    }

    public static function validateFiles(array $selected, Pmp $pmp, array $visibility, array $workshopSettings = [], array $routing = []): void
    {
        $files = $pmp->files->whereIn('id', array_column($selected, 'id'));
        $workshopIds = self::workshopIds($pmp, $selected, $routing);
        if (!$workshopIds || Factory::activeWorkshops()->whereIn('id', $workshopIds)->count() !== count($workshopIds)) {
            throw ValidationException::withMessages(['selected_files' => ['Աշխատանքային ֆայլի համար ընտրեք այս ընկերության գործող արտադրամասը։']]);
        }
        foreach ($routing as $row) {
            $file = $files->firstWhere('id', $row['file_id']);
            if (!$file || $file->is_reference_file || count(array_unique($row['factory_ids'])) !== count($row['factory_ids']) || array_diff($row['factory_ids'], $workshopIds)) {
                throw ValidationException::withMessages(['file_routing' => ['Ընտրեք այս առաջադրանքի աշխատանքային ֆայլերն ու գործող արտադրամասերը։']]);
            }
        }
        if (array_diff(array_column($workshopSettings, 'factory_id'), $workshopIds)) {
            throw ValidationException::withMessages(['workshop_settings' => ['Կարգավորումները պետք է պատկանեն ընտրված արտադրամասերի աշխատանքներին։']]);
        }
        foreach ($visibility as $row) {
            $file = $files->firstWhere('id', $row['file_id']);
            if (!$file || !$file->is_reference_file || count(array_unique($row['factory_ids'])) !== count($row['factory_ids']) || array_diff($row['factory_ids'], $workshopIds)) {
                throw ValidationException::withMessages(['reference_file_visibility' => ['Տեղեկատու ֆայլը և ընտրված արտադրամասերը պետք է պատկանեն այս առաջադրանքին և ընկերությանը։']]);
            }
        }
    }

    public static function attachFiles(Order $order, Pmp $pmp, array $selected, array $visibility, $operators, array $workshopSettings = [], array $routing = []): void
    {
        $ids = self::workshopIds($pmp, $selected, $routing);
        if (Factory::activeWorkshops()->whereIn('id', $ids)->sharedLock()->get(['id'])->count() !== count($ids)) throw ValidationException::withMessages(['file_routing' => ['Արտադրամասերի հասանելիությունը փոխվել է։ Թարմացրեք ընտրությունը։']]);
        $settings = self::settings($order->toArray());
        $shares = collect($visibility)->keyBy('file_id');
        $overrides = collect($workshopSettings)->keyBy('factory_id');
        foreach ($selected as $item) {
            $file = $pmp->files->firstWhere('id', (int) $item['id']);
            SelectedFile::create(['order_id' => $order->id, 'pmp_file_id' => $file->id, 'quantity' => $item['quantity']]);
            $destinations = $file->is_reference_file ? ($shares->get($file->id)['factory_ids'] ?? []) : self::destinations($file, $routing);
            foreach ($destinations as $factoryId) {
                $override = $overrides->get($factoryId);
                $stepSettings = $override ? self::settings($override) + ['deadline' => $override['deadline'] ?? null, 'priority' => $override['priority']] : $settings;
                $step = FactoryOrder::firstOrCreate(['order_id' => $order->id, 'factory_id' => $factoryId],
                    $stepSettings + ['status' => 'pending', 'operator_id' => $operators->get($factoryId)['user_id'] ?? null]);
                $step->files()->syncWithoutDetaching([$file->id => [
                    'quantity' => $item['quantity'], 'material_type' => $file->material_type, 'thickness' => $file->thickness,
                ]]);
            }
        }
    }
}
