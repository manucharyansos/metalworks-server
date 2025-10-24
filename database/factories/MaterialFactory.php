<?php

namespace Database\Factories;

use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MaterialFactory extends Factory
{
    protected $model = Material::class;
    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        static $materialNumber = 1;

        $name = 'Material ' . $materialNumber;
        $title = 'This is the description for material ' . $materialNumber;
        $size = $materialNumber;
        $image = 'materials-images/material-' . $materialNumber . '.jpg';

        $materialNumber++;

        return [
            'name' => $name,
            'title' => $title,
            'size' => $size,
            'image' => $image,
        ];
    }
}
