<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the local AGOS Coffee House demonstration database.
     */
    public function run(): void
    {
        $this->call(AgosCoffeeHouseSeeder::class);
    }
}
