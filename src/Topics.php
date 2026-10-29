<?php

namespace Ernestdefoe\Moments;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;

/**
 * The topic index: which moments use which #words. Every moment is indexed
 * (so a follower's topic feed finds a followers-only moment, through the
 * privacy rule like every other feed), but a topic's count and its place in
 * the popular-topics widget come from public, visible moments only.
 */
class Topics
{
    public function __construct(protected ConnectionInterface $db, protected Content $content)
    {
    }

    public function sync(Entry $entry): void
    {
        $names = $entry->isRoot() ? $this->content->topics($entry->content) : [];

        $previous = $this->db->table('moments_entry_topic')->where('entry_id', $entry->id)->pluck('topic_id')->map(fn ($id) => (int) $id)->all();
        $ids = $this->resolve($names);

        $this->db->table('moments_entry_topic')->where('entry_id', $entry->id)->delete();
        if ($ids) {
            $this->db->table('moments_entry_topic')->insert(array_map(fn (int $id) => ['entry_id' => $entry->id, 'topic_id' => $id], $ids));
        }

        $this->recount(array_values(array_unique(array_merge($previous, $ids))));
    }

    /** Before a moment is deleted: the topics whose counts will change. */
    public function topicIdsOf(int $entryId): array
    {
        return $this->db->table('moments_entry_topic')->where('entry_id', $entryId)->pluck('topic_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param array<string, string> $names key => name
     * @return list<int>
     */
    protected function resolve(array $names): array
    {
        if (! $names) {
            return [];
        }

        $keys = array_map('strval', array_keys($names));
        $existing = Topic::query()->whereIn('name_key', $keys)->pluck('id', 'name_key')->all();
        $missing = array_diff($keys, array_map('strval', array_keys($existing)));

        if ($missing) {
            // insertOrIgnore: two members can write a brand-new topic at once.
            $this->db->table('moments_topics')->insertOrIgnore(array_map(fn (string $key) => [
                'name' => $names[$key],
                'name_key' => $key,
                'moment_count' => 0,
            ], array_values($missing)));
            $existing = Topic::query()->whereIn('name_key', $keys)->pluck('id', 'name_key')->all();
        }

        return array_values(array_map('intval', $existing));
    }

    /**
     * Recomputed rather than incremented, so the counts cannot drift; topics
     * nothing uses any more are removed.
     *
     * @param list<int> $topicIds
     */
    public function recount(array $topicIds): void
    {
        if (! $topicIds) {
            return;
        }

        $rows = $this->db->table('moments_entry_topic')
            ->join('moments_entries', 'moments_entries.id', '=', 'moments_entry_topic.entry_id')
            ->whereIn('moments_entry_topic.topic_id', $topicIds)
            ->where('moments_entries.privacy', Entry::PUBLIC)
            ->whereNull('moments_entries.hidden_at')
            ->groupBy('moments_entry_topic.topic_id')
            ->select('moments_entry_topic.topic_id')
            ->selectRaw('COUNT(*) as aggregate')
            ->get()
            ->keyBy('topic_id');

        $used = $this->db->table('moments_entry_topic')->whereIn('topic_id', $topicIds)->distinct()->pluck('topic_id')->map(fn ($id) => (int) $id)->all();

        foreach ($topicIds as $id) {
            if (! in_array($id, $used, true)) {
                Topic::query()->whereKey($id)->delete();
                continue;
            }

            $count = (int) ($rows->get($id)->aggregate ?? 0);
            Topic::query()->whereKey($id)->update(array_filter([
                'moment_count' => $count,
                'last_used_at' => $count > 0 ? Carbon::now() : null,
            ], fn ($v) => $v !== null));
        }
    }
}
