<?php

namespace romanavr\contentops\mcp;

use craft\elements\User;

/**
 * Who an MCP session acts as and what it may do.
 *
 * Modes: `readonly` (read tools only), `propose` (AI creates previewed changesets; a person applies them
 * in the CP), `full` (AI may also apply and undo changesets).
 *
 * @author Romanavr
 * @since 1.0.0
 */
class McpContext
{
    // Const Properties
    // =========================================================================

    public const MODE_READONLY = 'readonly';
    public const MODE_PROPOSE = 'propose';
    public const MODE_FULL = 'full';

    // Public Methods
    // =========================================================================

    /**
     * @param string $mode
     * @param User|null $user The Craft user the session acts as (`null` = console, no permission checks)
     * @param string $client Label recorded on changesets (e.g. “stdio” or a token name)
     */
    public function __construct(
        public readonly string $mode = self::MODE_PROPOSE,
        public readonly ?User $user = null,
        public readonly string $client = 'stdio',
    ) {
    }

    /**
     * @return bool
     */
    public function canPropose(): bool
    {
        return in_array($this->mode, [self::MODE_PROPOSE, self::MODE_FULL], true);
    }

    /**
     * @return bool
     */
    public function canApply(): bool
    {
        return $this->mode === self::MODE_FULL;
    }
}
