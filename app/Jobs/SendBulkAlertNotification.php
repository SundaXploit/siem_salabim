<?php

namespace App\Jobs;

use App\Services\BulkAlertNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendBulkAlertNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 300;

    public array $backoff = [30, 120];

    public function __construct(public readonly string $groupId, public readonly array $deliveryIds)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'bulk-alert-summary:'.$this->groupId;
    }

    public function handle(BulkAlertNotifier $notifier): void
    {
        $notifier->deliver($this->groupId, $this->deliveryIds);
    }
}
