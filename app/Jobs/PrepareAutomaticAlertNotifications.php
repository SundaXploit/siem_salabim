<?php

namespace App\Jobs;

use App\Services\AutomaticAlertNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PrepareAutomaticAlertNotifications implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(AutomaticAlertNotifier $notifier): void
    {
        // Recover committed delivery intents only; never scan existing NEW alerts.
        $notifier->dispatchPending();
    }
}
