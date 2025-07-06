<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Products;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Products>
 */
class ProductsFactory extends Factory
{
    protected $model = Products::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $categories = [
            'Աթոռ' => [
                'prefixes' => ['Էրգոնոմիկ', 'Գործադիր', 'Ճաշարանային', 'Գրասենյակային', 'Հաշմանդամի'],
                'price_range' => [5000, 25000],
                'description' => 'Հարմարավետ %s աթոռ %s ծածկույթով, կատարյալ %s օգտագործման համար:'
            ],
            'Սեղան' => [
                'prefixes' => ['Ճաշարանային', 'Հաշվիչ', 'Գրասենյակային', 'Ծալովի', 'Ուսումնական'],
                'price_range' => [10000, 40000],
                'description' => 'Ամուր %s սեղան՝ պատրաստված %s-ից, իդեալական %s միջավայրերի համար:'
            ],
            'Պահարան' => [
                'prefixes' => ['Հաշվապահական', 'Թվային', 'Պատի', 'Շարժական', 'Կենսաչափական'],
                'price_range' => [15000, 50000],
                'description' => 'Անվտանգ %s պահարան՝ %s կողպման համակարգով, հարմար %s պահպանման համար:'
            ],
            'Դարակ' => [
                'prefixes' => ['Հաշվապահական', 'Պահեստային', 'Ցուցադրական', 'Խոհանոցային', 'Հագուստի'],
                'price_range' => [8000, 30000],
                'description' => 'Ընդարձակ %s դարակ՝ %s մակերեսով, նախատեսված %s կազմակերպման համար:'
            ],
            'Դարակաշար' => [
                'prefixes' => ['Պատի', 'Գրապահարան', 'Պահեստային', 'Ցուցադրական', 'Անկյունային'],
                'price_range' => [4000, 20000],
                'description' => 'Ժամանակակից %s դարակաշար՝ %s կառուցվածքով, կատարյալ %s ցուցադրության համար:'
            ]
        ];

        $category = $this->faker->randomElement(array_keys($categories));
        $prefix = $this->faker->randomElement($categories[$category]['prefixes']);
        $material = $this->faker->randomElement(['փայտ', 'մետաղ', 'ապակի', 'կաշի', 'գործվածք']);
        $use = $this->faker->randomElement(['տնային', 'գրասենյակային', 'առևտրային', 'բացօթյա', 'անձնական']);

        return [
            'name' => "$prefix $category",
            'description' => sprintf(
                $categories[$category]['description'],
                $prefix,
                $material,
                $use
            ),
            'image' => $this->storeFakeImage(),
            'price' => $this->faker->numberBetween(
                $categories[$category]['price_range'][0],
                $categories[$category]['price_range'][1]
            ),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function storeFakeImage()
    {
        $fakeImagePath = $this->faker->image(storage_path('app/public/Products'), 640, 480, null, false);

        return '/storage/Products/' . basename($fakeImagePath);
    }
}
