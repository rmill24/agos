<?php

namespace App\Jobs;

use App\Services\LoyverseSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncLoyversePage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $teamId, public int $syncId, public int $attempt, public int $page) {}

    public function handle(LoyverseSyncService $service): void
    {
        $service->process($this->teamId, $this->syncId, $this->attempt, $this->page);
    }
}
