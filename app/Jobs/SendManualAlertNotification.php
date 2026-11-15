<?php

namespace App\Jobs;

use App\Services\ManualAlertNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendManualAlertNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 300;

    public array $backoff = [30, 120];

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'manual-alert-delivery:'.$this->deliveryId;
    }

    public function handle(ManualAlertNotifier $notifier): void
    {
        $notifier->deliverQueued($this->deliveryId);
    }
}
