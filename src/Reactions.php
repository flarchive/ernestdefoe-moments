<?php

namespace Ernestdefoe\Moments;

use Flarum\Extension\ExtensionManager;
use Illuminate\Database\ConnectionInterface;

/**
 * Which reactions a moment offers.
 *
 * fof/reactions stores its reactions against discussion posts only (its
 * table requires a post), so Moments keeps its own small table of who
 * reacted with what. With fof/reactions enabled, the reactions on offer are
 * the forum's own set from it (the enabled ones, as the admin configured
 * them); without it, a short built-in set of emoji.
 */
class Reactions
{
    public const BUILT_IN = ['thumbsup', 'heart', 'laughing', 'open_mouth', 'cry', 'tada'];

    /** @var list<string>|null */
    protected ?array $allowed = null;

    public function __construct(protected ExtensionManager $extensions, protected ConnectionInterface $db)
    {
    }

    public function usesFof(): bool
    {
        return $this->extensions->isEnabled('fof-reactions');
    }

    /** @return list<string> */
    public function allowed(): array
    {
        if ($this->allowed !== null) {
            return $this->allowed;
        }

        if ($this->usesFof()) {
            $ids = $this->db->table('reactions')->where('enabled', true)->pluck('identifier')->map(fn ($v) => (string) $v)->all();
            if ($ids) {
                return $this->allowed = array_values($ids);
            }
        }

        return $this->allowed = self::BUILT_IN;
    }

    public function isAllowed(string $reaction): bool
    {
        return in_array($reaction, $this->allowed(), true);
    }
}
