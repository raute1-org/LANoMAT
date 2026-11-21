<?php

namespace Database\Seeders;

use App\Modules\Lighting\Support\DefaultScenes;
use Illuminate\Database\Seeder;

class LightingSceneSeeder extends Seeder
{
    public function run(): void
    {
        DefaultScenes::seed();
    }
}
