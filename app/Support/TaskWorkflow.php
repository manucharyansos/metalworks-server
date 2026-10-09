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
            'confirmation_method' => 'nullable|required_if:confirmation_required,true|in:photo,text',
            'reference_file_visibility' => 'sometimes|array',
            'reference_file_visibility.*.file_id' => 'required|integer|distinct',
            'reference_file_visibility.*.factory_ids' => 'present|array',
            'reference_file_visibility.*.factory_ids.*' => 'required|integer',
        ];
    }

    public static function settings(array $data): array
    {
        $required = (bool) ($data['confirmation_required'] ?? false);
        return ['confirmation_required' => $required, 'confirmation_method' => $required ? $data['confirmation_method'] : null];
    }

    public static function validateFiles(array $selected, Pmp $pmp, array $visibility): void
    {
        $files = $pmp->files->whereIn('id', array_column($selected, 'id'));
        $workshopIds = $files->filter(fn ($file) => !$file->factory->is_reference)->pluck('factory_id')->unique()->all();
        if (!$workshopIds) throw ValidationException::withMessages(['selected_files' => ['Ընտրեք առնվազն մեկ արտադրամասի աշխատանքային ֆայլ։ INFO/PDF-ը տեղեկատու բաժիններ են։']]);
        foreach ($visibility as $row) {
            $file = $files->firstWhere('id', $row['file_id']);
            if (!$file || !$file->factory->is_reference || count(array_unique($row['factory_ids'])) !== count($row['factory_ids']) || array_diff($row['factory_ids'], $workshopIds)) {
                throw ValidationException::withMessages(['reference_file_visibility' => ['Տեղեկատու ֆայլը և ընտրված արտադրամասերը պետք է պատկանեն այս առաջադրանքին և ընկերությանը։']]);
            }
        }
    }

    public static function attachFiles(Order $order, Pmp $pmp, array $selected, array $visibility, $operators): void
    {
        $settings = self::settings($order->toArray());
        $shares = collect($visibility)->keyBy('file_id');
        foreach ($selected as $item) {
            $file = $pmp->files->firstWhere('id', (int) $item['id']);
            SelectedFile::create(['order_id' => $order->id, 'pmp_file_id' => $file->id, 'quantity' => $item['quantity']]);
            $destinations = $file->factory->is_reference ? ($shares->get($file->id)['factory_ids'] ?? []) : [$file->factory_id];
            foreach ($destinations as $factoryId) {
                $step = FactoryOrder::firstOrCreate(['order_id' => $order->id, 'factory_id' => $factoryId],
                    $settings + ['status' => 'pending', 'operator_id' => $operators->get($factoryId)['user_id'] ?? null]);
                $step->files()->syncWithoutDetaching([$file->id => [
                    'quantity' => $item['quantity'], 'material_type' => $file->material_type, 'thickness' => $file->thickness,
                ]]);
            }
        }
    }
}
