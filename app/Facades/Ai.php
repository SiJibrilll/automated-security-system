<?php

namespace App\Facades;

use App\Services\Ai\AiManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \App\Services\Ai\Contracts\AiProviderContract driver(?string $driver = null)
 * @method static \App\Services\Ai\DTOs\AiResponse analyze(\App\Services\Ai\DTOs\AiPrompt $prompt)
 * @method static string getDefaultDriver()
 * @method static bool isConfigured()
 * @method static \App\Services\Ai\AiManager extend(string $driver, \Closure $callback)
 *
 * @see AiManager
 */
class Ai extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return AiManager::class;
    }
}
