<?php

namespace Database\Seeders;

use App\Models\Material;
use App\Models\MaterialGroup;
use App\Models\MaterialCategory;
use Illuminate\Database\Seeder;

class MaterialsSeeder extends Seeder
{
    public function run()
    {
        // Create Material Groups
        $steelPipesGroup = MaterialGroup::create([
            'name' => 'Պողպատյա խողովակներ',
            'image' => null
        ]);

        $steelSheetsGroup = MaterialGroup::create([
            'name' => 'Թիթեղներ',
            'image' => null
        ]);

        // Create Categories for Steel Pipes
        $squareStraightPipes = MaterialCategory::create([
            'name' => 'քառանկյուն հատումով, ուղղակար',
            'material_group_id' => $steelPipesGroup->id
        ]);

        $roundStraightPipes = MaterialCategory::create([
            'name' => 'կլոր հատումով, ուղղակար',
            'material_group_id' => $steelPipesGroup->id
        ]);

        $roundSeamlessPipes = MaterialCategory::create([
            'name' => 'կլոր հատումով, անկար',
            'material_group_id' => $steelPipesGroup->id
        ]);

        $ovalPipes = MaterialCategory::create([
            'name' => 'օվալաձև և այլ հատումներով',
            'material_group_id' => $steelPipesGroup->id
        ]);

        // Create Categories for Steel Sheets
        $coiledSheets = MaterialCategory::create([
            'name' => 'պողպատյա գլանաթիթեղ',
            'material_group_id' => $steelSheetsGroup->id
        ]);

        $coldRolledSheets = MaterialCategory::create([
            'name' => 'պողպատյա սառը գլանումով',
            'material_group_id' => $steelSheetsGroup->id
        ]);

        $hotRolledSheets = MaterialCategory::create([
            'name' => 'պողպատյա տաք գլանումով',
            'material_group_id' => $steelSheetsGroup->id
        ]);

        $embossedSheets = MaterialCategory::create([
            'name' => 'պողպատյա ռելիեֆային նախշերով',
            'material_group_id' => $steelSheetsGroup->id
        ]);

        $patternedSheets = MaterialCategory::create([
            'name' => 'պողպատյա ջերմավորված նախշերով',
            'material_group_id' => $steelSheetsGroup->id
        ]);

        $perforatedSheets = MaterialCategory::create([
            'name' => 'պողպատյա, անցքահատված',
            'material_group_id' => $steelSheetsGroup->id
        ]);

        $stainlessSheets = MaterialCategory::create([
            'name' => 'չժանգոտվող պողպատից',
            'material_group_id' => $steelSheetsGroup->id
        ]);

        $galvanizedSheets = MaterialCategory::create([
            'name' => 'ցինկապատ պողպատից',
            'material_group_id' => $steelSheetsGroup->id
        ]);

        $corrugatedSheets = MaterialCategory::create([
            'name' => 'ցինկապատ ծալքաթիթեղ',
            'material_group_id' => $steelSheetsGroup->id
        ]);

        // Create Materials for Steel Pipes Categories
        // 1. Քառանկյուն հատումով, ուղղակար (id: 1)
        Material::create([
            'material_category_id' => $squareStraightPipes->id,
            'description' => 'Քառանկյուն պողպատյա խողովակ 40x40 մմ, հաստություն՝ 2 մմ',
            'thickness' => 2.0,
            'width' => 40,
            'length' => 6000
        ]);

        Material::create([
            'material_category_id' => $squareStraightPipes->id,
            'description' => 'Քառանկյուն պողպատյա խողովակ 60x60 մմ, հաստություն՝ 3 մմ',
            'thickness' => 3.0,
            'width' => 60,
            'length' => 6000
        ]);

        // 2. Կլոր հատումով, ուղղակար (id: 2)
        Material::create([
            'material_category_id' => $roundStraightPipes->id,
            'description' => 'Կլոր պողպատյա խողովակ Ø50 մմ, հաստություն՝ 2.5 մմ',
            'thickness' => 2.5,
            'width' => 50,
            'length' => 6000
        ]);

        Material::create([
            'material_category_id' => $roundStraightPipes->id,
            'description' => 'Կլոր պողպատյա խողովակ Ø76 մմ, հաստություն՝ 3 մմ',
            'thickness' => 3.0,
            'width' => 76,
            'length' => 6000
        ]);

        // 3. Կլոր հատումով, անկար (id: 3)
        Material::create([
            'material_category_id' => $roundSeamlessPipes->id,
            'description' => 'Անկար կլոր խողովակ Ø32 մմ, հաստություն՝ 3.2 մմ',
            'thickness' => 3.2,
            'width' => 32,
            'length' => 6000
        ]);

        Material::create([
            'material_category_id' => $roundSeamlessPipes->id,
            'description' => 'Անկար կլոր խողովակ Ø60 մմ, հաստություն՝ 4 մմ',
            'thickness' => 4.0,
            'width' => 60,
            'length' => 6000
        ]);

        // 4. Օվալաձև և այլ հատումներով (id: 4)
        Material::create([
            'material_category_id' => $ovalPipes->id,
            'description' => 'Օվալաձև պողպատյա խողովակ 50x30 մմ, հաստություն՝ 2 մմ',
            'thickness' => 2.0,
            'width' => 50,
            'length' => 6000
        ]);

        Material::create([
            'material_category_id' => $ovalPipes->id,
            'description' => 'Օվալաձև պողպատյա խողովակ 60x40 մմ, հաստություն՝ 2.5 մմ',
            'thickness' => 2.5,
            'width' => 60,
            'length' => 6000
        ]);

        // Create Materials for Steel Sheets Categories
        // 5. Պողպատյա գլանաթիթեղ (id: 5)
        Material::create([
            'material_category_id' => $coiledSheets->id,
            'description' => 'Պողպատյա գլանաթիթեղ 0.8 մմ, լայնություն՝ 1250 մմ',
            'thickness' => 0.8,
            'width' => 1250,
            'length' => null
        ]);

        Material::create([
            'material_category_id' => $coiledSheets->id,
            'description' => 'Պողպատյա գլանաթիթեղ 1.0 մմ, լայնություն՝ 1500 մմ',
            'thickness' => 1.0,
            'width' => 1500,
            'length' => null
        ]);

        // 6. Պողպատյա սառը գլանումով (id: 6)
        Material::create([
            'material_category_id' => $coldRolledSheets->id,
            'description' => 'Սառը գլանման թիթեղ 0.5 մմ, լայնություն՝ 1000 մմ',
            'thickness' => 0.5,
            'width' => 1000,
            'length' => 2000
        ]);

        Material::create([
            'material_category_id' => $coldRolledSheets->id,
            'description' => 'Սառը գլանման թիթեղ 1.2 մմ, լայնություն՝ 1250 մմ',
            'thickness' => 1.2,
            'width' => 1250,
            'length' => 2500
        ]);

        // 7. Պողպատյա տաք գլանումով (id: 7)
        Material::create([
            'material_category_id' => $hotRolledSheets->id,
            'description' => 'Տաք գլանման թիթեղ 3.0 մմ, լայնություն՝ 1500 մմ',
            'thickness' => 3.0,
            'width' => 1500,
            'length' => 6000
        ]);

        Material::create([
            'material_category_id' => $hotRolledSheets->id,
            'description' => 'Տաք գլանման թիթեղ 4.0 մմ, լայնություն՝ 1500 մմ',
            'thickness' => 4.0,
            'width' => 1500,
            'length' => 6000
        ]);

        // 8. Պողպատյա ռելիեֆային նախշերով (id: 8)
        Material::create([
            'material_category_id' => $embossedSheets->id,
            'description' => 'Ռելիեֆային թիթեղ 1.5 մմ, լայնություն՝ 1250 մմ, ադամանդե նախշ',
            'thickness' => 1.5,
            'width' => 1250,
            'length' => 2500
        ]);

        Material::create([
            'material_category_id' => $embossedSheets->id,
            'description' => 'Ռելիեֆային թիթեղ 2.0 մմ, լայնություն՝ 1250 մմ, գծային նախշ',
            'thickness' => 2.0,
            'width' => 1250,
            'length' => 2500
        ]);

        // 9. Պողպատյա ջերմավորված նախշերով (id: 9)
        Material::create([
            'material_category_id' => $patternedSheets->id,
            'description' => 'Ջերմավորված նախշերով թիթեղ 1.0 մմ, լայնություն՝ 1000 մմ',
            'thickness' => 1.0,
            'width' => 1000,
            'length' => 2000
        ]);

        Material::create([
            'material_category_id' => $patternedSheets->id,
            'description' => 'Ջերմավորված նախշերով թիթեղ 1.2 մմ, լայնություն՝ 1250 մմ',
            'thickness' => 1.2,
            'width' => 1250,
            'length' => 2500
        ]);

        // 10. Պողպատյա, անցքահատված (id: 10)
        Material::create([
            'material_category_id' => $perforatedSheets->id,
            'description' => 'Անցքահատված թիթեղ 1.0 մմ, անցքեր Ø5 մմ, լայնություն՝ 1000 մմ',
            'thickness' => 1.0,
            'width' => 1000,
            'length' => 2000
        ]);

        Material::create([
            'material_category_id' => $perforatedSheets->id,
            'description' => 'Անցքահատված թիթեղ 1.5 մմ, անցքեր Ø8 մմ, լայնություն՝ 1250 մմ',
            'thickness' => 1.5,
            'width' => 1250,
            'length' => 2500
        ]);

        // 11. Չժանգոտվող պողպատից (id: 11)
        Material::create([
            'material_category_id' => $stainlessSheets->id,
            'description' => 'Չժանգոտվող պողպատյա թիթեղ 0.8 մմ, լայնություն՝ 1250 մմ',
            'thickness' => 0.8,
            'width' => 1250,
            'length' => 2500
        ]);

        Material::create([
            'material_category_id' => $stainlessSheets->id,
            'description' => 'Չժանգոտվող պողպատյա թիթեղ 1.0 մմ, լայնություն՝ 1500 մմ',
            'thickness' => 1.0,
            'width' => 1500,
            'length' => 3000
        ]);

        // 12. Ցինկապատ պողպատից (id: 12)
        Material::create([
            'material_category_id' => $galvanizedSheets->id,
            'description' => 'Ցինկապատ պողպատյա թիթեղ 0.5 մմ, լայնություն՝ 1000 մմ',
            'thickness' => 0.5,
            'width' => 1000,
            'length' => 2000
        ]);

        Material::create([
            'material_category_id' => $galvanizedSheets->id,
            'description' => 'Ցինկապատ պողպատյա թիթեղ 0.7 մմ, լայնություն՝ 1250 մմ',
            'thickness' => 0.7,
            'width' => 1250,
            'length' => 2500
        ]);

        // 13. Ցինկապատ ծալքաթիթեղ (id: 13)
        Material::create([
            'material_category_id' => $corrugatedSheets->id,
            'description' => '« ԿՊ-25 » 0,35x900 մմ, օգտակար լայնությունը՝ 820 մմ',
            'thickness' => 0.35,
            'width' => 900,
            'length' => null
        ]);

        Material::create([
            'material_category_id' => $corrugatedSheets->id,
            'description' => '« ԿՊ-25 » 0,35x1130 մմ, օգտակար լայնությունը՝ 1025 մմ',
            'thickness' => 0.35,
            'width' => 1130,
            'length' => null
        ]);

        Material::create([
            'material_category_id' => $corrugatedSheets->id,
            'description' => '« ԿՊ-21 » 0,40x850 մմ, օգտակար լայնությունը՝ 800 մմ',
            'thickness' => 0.40,
            'width' => 850,
            'length' => null
        ]);

        Material::create([
            'material_category_id' => $corrugatedSheets->id,
            'description' => '« ԿՊ-25 » 0,40x900 մմ, օգտակար լայնությունը՝ 820 մմ',
            'thickness' => 0.40,
            'width' => 900,
            'length' => null
        ]);

        Material::create([
            'material_category_id' => $corrugatedSheets->id,
            'description' => '« ԿՊ-25 » 0,40x1050 մմ, օգտակար լայնությունը՝ 1000 մմ',
            'thickness' => 0.40,
            'width' => 1050,
            'length' => null
        ]);

        Material::create([
            'material_category_id' => $corrugatedSheets->id,
            'description' => '« ԿՊ-25 » 0,40x1130 մմ, օգտակար լայնությունը՝ 1025 մմ',
            'thickness' => 0.40,
            'width' => 1130,
            'length' => null
        ]);

        Material::create([
            'material_category_id' => $corrugatedSheets->id,
            'description' => '« ԿՊ-21 » 0,50x850 մմ, օգտակար լայնությունը՝ 800 մմ',
            'thickness' => 0.50,
            'width' => 850,
            'length' => null
        ]);

        Material::create([
            'material_category_id' => $corrugatedSheets->id,
            'description' => '« ԿՊ-21 » 0,50x900 մմ, օգտակար լայնությունը՝ 820 մմ',
            'thickness' => 0.50,
            'width' => 900,
            'length' => null
        ]);

        Material::create([
            'material_category_id' => $corrugatedSheets->id,
            'description' => '« ԿՊ-25 » 0,50x1050 մմ, օգտակար լայնությունը՝ 1000 մմ',
            'thickness' => 0.50,
            'width' => 1050,
            'length' => null
        ]);

        Material::create([
            'material_category_id' => $corrugatedSheets->id,
            'description' => '« ԿՊ-25 » 0,50x1130 մմ, օգտակար լայնությունը՝ 1025 մմ',
            'thickness' => 0.50,
            'width' => 1130,
            'length' => null
        ]);
    }
}
