<?php

use App\Log;
use App\Player;
use Illuminate\Database\Seeder;

class LogsTableSeeder extends Seeder
{

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        Log::factory()->count(1000)->create([
            'identifier' => Player::query()->inRandomOrder()->first()->steam_identifier,
        ]);
    }

}
