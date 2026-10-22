<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:generate-api-key')]
#[Description('Command description')]
class GenerateAPIKey extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $user = User::first();

        $token = $user->createToken('APIToken');

        $this->info($token->plainTextToken);
    }
}
