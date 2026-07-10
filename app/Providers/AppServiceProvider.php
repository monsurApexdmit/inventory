<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (!$this->shouldBootBroadcastChannels()) {
            return;
        }

        require_once base_path('routes/channels.php');
    }

    private function shouldBootBroadcastChannels(): bool
    {
        $driver = config('broadcasting.default');

        if (!$driver || $driver === 'log' || $driver === 'null') {
            return false;
        }

        if ($driver === 'reverb' && !class_exists(\Pusher\Pusher::class)) {
            Log::warning('Broadcast channels not loaded because Pusher PHP SDK is missing while BROADCAST_CONNECTION=reverb.');
            return false;
        }

        return true;
    }
}
