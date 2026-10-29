<?php

namespace Ernestdefoe\Moments\Console;

use Carbon\Carbon;
use Ernestdefoe\Moments\Media;
use Ernestdefoe\Moments\Media\Storage;
use Flarum\Console\AbstractCommand;

/**
 * Removes uploads that were never posted (a composer closed with a photo
 * attached), with their files, once they are a day old. Scheduled daily.
 */
class PruneCommand extends AbstractCommand
{
    public function __construct(protected Storage $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('moments:prune')->setDescription('Remove unposted Moments uploads older than a day.');
    }

    protected function fire(): int
    {
        $count = 0;

        Media::query()->whereNull('entry_id')->where('created_at', '<', Carbon::now()->subDay())
            ->chunkById(200, function ($rows) use (&$count) {
                $this->storage->deletePaths($rows->where('source', 'local')->pluck('path')->filter()->values()->all());
                Media::query()->whereIn('id', $rows->pluck('id')->all())->delete();
                $count += $rows->count();
            });

        $this->info("Removed $count unposted uploads.");

        return 0;
    }
}
