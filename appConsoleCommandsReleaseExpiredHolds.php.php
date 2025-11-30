<?php

namespace App\Console\Commands;

use App\Services\HoldService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReleaseExpiredHolds extends Command
{
    protected $signature = 'holds:release-expired';
    protected $description = 'Release expired holds and return stock to availability';

    public function handle(HoldService $holdService): int
    {
        $this->info('Starting to release expired holds...');
        
        $released = $holdService->releaseExpiredHolds();
        
        $this->info("Released {$released} expired holds.");
        Log::info("Released {$released} expired holds via scheduler");

        return Command::SUCCESS;
    }
}