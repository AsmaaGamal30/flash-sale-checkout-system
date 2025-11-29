<?php

namespace App\Console\Commands;

use App\Models\Hold;
use App\Models\MetricsLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ReleaseExpiredHolds extends Command
{
    protected $signature = 'holds:release-expired';
    protected $description = 'Release expired holds and restore stock availability';

    public function handle()
    {
        $lockKey = 'holds:release-expired:lock';

        $lock = Cache::lock($lockKey, 60);

        if (!$lock->get()) {
            $this->warn('Another process is already releasing holds');
            return 0;
        }

        try {
            $expiredHolds = Hold::where('expires_at', '<=', now())
                ->whereNull('used_at')
                ->whereNull('released_at')
                ->get();

            $count = 0;
            foreach ($expiredHolds as $hold) {
                $hold->release();
                $count++;
            }

            if ($count > 0) {
                $this->info("Released {$count} expired holds");

                MetricsLog::create([
                    'type' => 'holds_batch_released',
                    'payload' => ['count' => $count],
                ]);
            }

            return 0;
        } finally {
            $lock->release();
        }
    }
}