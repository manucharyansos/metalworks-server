<?php

namespace App\Support;

use App\Models\{FactoryOrder, Order, Pmp, SelectedFile};
use Illuminate\Validation\ValidationException;

final class TaskWorkflow
{
    public static function rules(): array
    {
        return [
            'confirmation_required' => 'sometimes|boolean',
            'confirmation_method' => 'nullable|required_if:confirmation_required,true|in:photo,text,photo_text',
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
        ];
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

    /** Workshops that will really get a job: reference (INFO/PDF) files never create one. */
    public static function workshopIds(Pmp $pmp, array $selected): array
    {
        return $pmp->files->whereIn('id', array_column($selected, 'id'))
            ->filter(fn ($file) => !$file->factory->is_reference)->pluck('factory_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public static function validateFiles(array $selected, Pmp $pmp, array $visibility, array $workshopSettings = []): void
    {
        $files = $pmp->files->whereIn('id', array_column($selected, 'id'));
        $workshopIds = self::workshopIds($pmp, $selected);
        if (!$workshopIds) throw ValidationException::withMessages(['selected_files' => ['Ընտրեք առնվազն մեկ արտադրամասի աշխատանքային ֆայլ։ INFO/PDF-ը տեղեկատու բաժիններ են։']]);
        if (array_diff(array_column($workshopSettings, 'factory_id'), $workshopIds)) {
            throw ValidationException::withMessages(['workshop_settings' => ['Կարգավորումները պետք է պատկանեն ընտրված արտադրամասերի աշխատանքներին։']]);
        }
        foreach ($visibility as $row) {
            $file = $files->firstWhere('id', $row['file_id']);
            if (!$file || !$file->factory->is_reference || count(array_unique($row['factory_ids'])) !== count($row['factory_ids']) || array_diff($row['factory_ids'], $workshopIds)) {
                throw ValidationException::withMessages(['reference_file_visibility' => ['Տեղեկատու ֆայլը և ընտրված արտադրամասերը պետք է պատկանեն այս առաջադրանքին և ընկերությանը։']]);
            }
        }
    }

    public static function attachFiles(Order $order, Pmp $pmp, array $selected, array $visibility, $operators, array $workshopSettings = []): void
    {
        $settings = self::settings($order->toArray());
        $shares = collect($visibility)->keyBy('file_id');
        $overrides = collect($workshopSettings)->keyBy('factory_id');
        foreach ($selected as $item) {
            $file = $pmp->files->firstWhere('id', (int) $item['id']);
            SelectedFile::create(['order_id' => $order->id, 'pmp_file_id' => $file->id, 'quantity' => $item['quantity']]);
            $destinations = $file->factory->is_reference ? ($shares->get($file->id)['factory_ids'] ?? []) : [$file->factory_id];
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
