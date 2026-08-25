<?php

namespace App\Console\Commands;

use App\Jobs\PruneImageBlob;
use App\Models\ImageBlob;
use Illuminate\Console\Command;

/**
 * Safety net for the delete path: if a prune job was lost (worker killed, queue
 * flushed) the blob would sit on disk forever. This sweeps anything that has
 * been unreferenced for a while and re-queues it.
 */
class PruneOrphanBlobs extends Command
{
    protected $signature = 'images:prune
                            {--minutes=60 : Only prune blobs unreferenced for at least this long}
                            {--chunk=500 : Rows to scan per batch}';

    protected $description = 'Delete stored image blobs that no user references any more';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));
        $queued = 0;

        ImageBlob::query()
            ->where('reference_count', '<=', 0)
            ->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('images')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($blobs) use (&$queued) {
                foreach ($blobs as $blob) {
                    PruneImageBlob::dispatch($blob->getKey());
                    $queued++;
                }
            });

        $this->info("Queued {$queued} orphaned blob(s) for deletion.");

        return self::SUCCESS;
    }
}
