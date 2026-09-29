<?php

namespace romanavr\contentops\mcp;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use Mcp\Exception\ToolCallException;
use romanavr\contentops\ContentOps;
use romanavr\contentops\enums\ChangeStatus;
use romanavr\contentops\helpers\Values;
use romanavr\contentops\models\Changeset;
use romanavr\contentops\models\FindReplaceScope;
use romanavr\contentops\models\MatchSpec;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Selection;
use romanavr\contentops\services\Targets;
use Throwable;
use yii\base\InvalidArgumentException;

/**
 * MCP tools that work in changesets: an AI proposes a change across many entries, a person reviews the
 * preview in the CP and applies it (or, in `full` mode, the AI applies it), and anyone can undo it.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class ChangesetTools
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Sample changes returned with a proposal.
     */
    public const SAMPLE_SIZE = 20;

    /**
     * @var int Changesets up to this many element/site pairs are applied immediately; bigger ones are queued.
     */
    public const SYNC_APPLY_LIMIT = 200;

    // Public Methods
    // =========================================================================

    /**
     * @param McpContext $context
     */
    public function __construct(
        private readonly McpContext $context,
    ) {
    }

    /**
     * Lists what can be edited on entries: every field and attribute with its operations and the options each operation takes.
     * Call this before proposing a bulk edit.
     *
     * @param string|null $section Section handle.
     * @param string|null $type Entry type handle.
     * @param int[]|null $entryIds Specific entry IDs instead of a section.
     * @return array<string, mixed>
     * @throws ToolCallException
     */
    public function getEditOptions(?string $section = null, ?string $type = null, ?array $entryIds = null): array
    {
        $ids = $this->_entryIds($section, $type, $entryIds, null, null);
        $targets = ContentOps::getInstance()->getTargets()->describeTargets(Entry::class, $ids);

        return [
            'entries' => count($ids),
            'targets' => array_map(fn(array $target) => [
                'target' => $target['handle'],
                'label' => $target['label'],
                'appliesTo' => $target['count'],
                'operations' => array_map(fn(array $operation) => [
                    'operation' => $operation['handle'],
                    'label' => $operation['label'],
                    'options' => array_map(fn(array $input) => array_filter([
                        'name' => $input['name'],
                        'type' => $input['type'],
                        'label' => $input['label'],
                        'choices' => isset($input['options']) ? array_column($input['options'], 'value') : null,
                    ]), $operation['inputs']),
                ], $target['operations']),
            ], $targets),
        ];
    }

    /**
     * Proposes a bulk edit. Nothing is saved: this creates a previewed changeset and returns its counts,
     * sample before/after values and a link where a person can review and apply it.
     *
     * Each change is {"target": "…", "operation": "…", "options": {…}}; get the targets, operations and option
     * names from get_edit_options. Nested option names like "values.heading" become {"values": {"heading": …}}.
     *
     * @param array<int, array<string, mixed>> $changes The changes to make.
     * @param string|null $section Section handle.
     * @param string|null $type Entry type handle.
     * @param int[]|null $entryIds Specific entry IDs instead of (or within) a section.
     * @param string|null $search Only entries matching this Craft search query.
     * @param string[]|null $sites Site handles to change. Default: the primary site.
     * @return array<string, mixed>
     * @throws ToolCallException
     */
    public function proposeBulkEdit(
        array $changes,
        ?string $section = null,
        ?string $type = null,
        ?array $entryIds = null,
        ?string $search = null,
        ?array $sites = null,
    ): array {
        $this->_requireMode(McpContext::MODE_PROPOSE);
        $plugin = ContentOps::getInstance();

        try {
            $plugin->requirePro('Proposing changes through MCP');
            $ids = $this->_entryIds($section, $type, $entryIds, $search, $sites);
            $this->_checkLimit(count($ids));
            $operations = $this->_operations($changes, $ids);

            $changeset = $plugin->getPreviewer()->preview(
                new Selection([
                    'elementType' => Entry::class,
                    'criteria' => ['id' => $ids ?: [0]],
                    'siteIds' => $this->_siteIds($sites),
                ]),
                $operations,
                $this->context->user,
            );
        } catch (InvalidArgumentException $e) {
            throw new ToolCallException($e->getMessage());
        }

        return $this->_proposal($changeset);
    }

    /**
     * Proposes a find & replace across entries. Nothing is saved: returns a previewed changeset with the number
     * of matches, sample changes and a link where a person can review (and exclude individual matches) and apply it.
     *
     * @param string $find Text to find (or a regular expression without delimiters, with regex=true).
     * @param string $replace Replacement; with regex, $1… insert capture groups.
     * @param bool $caseSensitive
     * @param bool $wholeWord
     * @param bool $regex
     * @param bool $links Also replace inside href/src attributes of HTML fields.
     * @param string[]|null $sections Section handles. Default: all.
     * @param string[]|null $sites Site handles. Default: all.
     * @param bool $includeNested Also search inside Matrix nested entries.
     * @return array<string, mixed>
     * @throws ToolCallException
     */
    public function proposeFindReplace(
        string $find,
        string $replace,
        bool $caseSensitive = true,
        bool $wholeWord = false,
        bool $regex = false,
        bool $links = false,
        ?array $sections = null,
        ?array $sites = null,
        bool $includeNested = false,
    ): array {
        $this->_requireMode(McpContext::MODE_PROPOSE);
        $plugin = ContentOps::getInstance();

        try {
            $plugin->requirePro('Proposing changes through MCP');
            $changeset = $plugin->getFindReplace()->preview(
                new MatchSpec([
                    'find' => $find,
                    'replace' => $replace,
                    'caseSensitive' => $caseSensitive,
                    'wholeWord' => $wholeWord,
                    'regex' => $regex,
                    'html' => $links ? MatchSpec::HTML_TEXT_AND_LINKS : MatchSpec::HTML_TEXT,
                ]),
                new FindReplaceScope([
                    'sections' => $sections ?? [],
                    'siteIds' => $sites ? $this->_siteIds($sites) : [],
                    'includeNested' => $includeNested,
                ]),
                $this->context->user,
            );
            $this->_checkLimit($changeset->getCount('pending'), $changeset);
        } catch (InvalidArgumentException $e) {
            throw new ToolCallException($e->getMessage());
        }

        return $this->_proposal($changeset);
    }

    /**
     * Shows a changeset: status, counts and its changes with before/after values.
     *
     * @param int $id Changeset ID.
     * @param string|null $status Only changes with this status (pending, applied, skipped, conflict, failed, undone, undoConflict).
     * @param int $limit Max changes to return (max 100).
     * @param int $offset
     * @return array<string, mixed>
     * @throws ToolCallException
     */
    public function getChangeset(int $id, ?string $status = null, int $limit = 20, int $offset = 0): array
    {
        $changeset = $this->_changeset($id);
        $changeStatus = $status !== null ? (ChangeStatus::tryFrom($status) ?? throw new ToolCallException("Unknown status “{$status}”.")) : null;

        return $this->_summary($changeset) + [
            'changes' => $this->_changes($changeset, $changeStatus, max(1, min($limit, 100)), max(0, $offset)),
        ];
    }

    /**
     * Lists recent changesets (newest first).
     *
     * @param int $limit Max changesets (max 50).
     * @return array<string, mixed>
     */
    public function listChangesets(int $limit = 20): array
    {
        $service = ContentOps::getInstance()->getChangesets();
        [$changesets] = $service->getChangesetsPage(null, max(1, min($limit, 50)), 0);

        if ($this->context->user !== null) {
            $changesets = array_filter($changesets, fn(Changeset $changeset) => $service->canView($changeset, $this->context->user));
        }

        return ['changesets' => array_values(array_map(fn(Changeset $changeset) => $this->_summary($changeset), $changesets))];
    }

    /**
     * Applies a previewed changeset (only allowed in full mode; otherwise a person applies it in the CP).
     *
     * @param int $id Changeset ID.
     * @return array<string, mixed>
     * @throws ToolCallException
     */
    public function applyChangeset(int $id): array
    {
        $this->_requireMode(McpContext::MODE_FULL);
        $changeset = $this->_changeset($id);
        $service = ContentOps::getInstance()->getChangesets();

        if ($this->context->user !== null && !$service->canApply($changeset, $this->context->user)) {
            throw new ToolCallException('This changeset can’t be applied by this user.');
        }

        try {
            $this->_run($changeset, fn() => $service->applyNow($id), fn() => $service->queueApply($id));
        } catch (InvalidArgumentException $e) {
            throw new ToolCallException($e->getMessage());
        }

        return $this->_summary($service->getChangesetById($id));
    }

    /**
     * Undoes a changeset (only allowed in full mode). Values edited since it ran are left alone unless force is true.
     *
     * @param int $id Changeset ID.
     * @param bool $force Also overwrite values that were edited after the changeset ran.
     * @return array<string, mixed>
     * @throws ToolCallException
     */
    public function undoChangeset(int $id, bool $force = false): array
    {
        $this->_requireMode(McpContext::MODE_FULL);
        $changeset = $this->_changeset($id);
        $service = ContentOps::getInstance()->getChangesets();

        if ($this->context->user !== null && !$service->canUndo($changeset, $this->context->user)) {
            throw new ToolCallException('This changeset can’t be undone by this user.');
        }

        try {
            $this->_run($changeset, fn() => $service->undoNow($id, $force), fn() => $service->queueUndo($id, $force));
        } catch (InvalidArgumentException $e) {
            throw new ToolCallException($e->getMessage());
        }

        return $this->_summary($service->getChangesetById($id));
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string $mode
     * @throws ToolCallException
     */
    private function _requireMode(string $mode): void
    {
        $allowed = $mode === McpContext::MODE_FULL ? $this->context->canApply() : $this->context->canPropose();

        if (!$allowed) {
            throw new ToolCallException(match ($mode) {
                McpContext::MODE_FULL => 'This MCP session can only propose changes. A person has to review and apply them in the Craft control panel.',
                default => 'This MCP session is read-only.',
            });
        }
    }

    /**
     * @param int $count Element/site pairs (or changes) in the proposal
     * @param Changeset|null $changeset Deleted again if over the limit
     * @throws ToolCallException
     */
    private function _checkLimit(int $count, ?Changeset $changeset = null): void
    {
        $limit = ContentOps::getInstance()->getSettings()->mcpMaxElements;

        if ($limit && $count > $limit) {
            if ($changeset !== null) {
                Craft::$app->getDb()->createCommand()->delete(\romanavr\contentops\db\Table::CHANGESETS, ['id' => $changeset->id])->execute();
            }

            throw new ToolCallException("This would change $count items; AI changesets are limited to $limit (Content Ops settings). Narrow the selection.");
        }
    }

    /**
     * @param string|null $section
     * @param string|null $type
     * @param int[]|null $entryIds
     * @param string|null $search
     * @param string[]|null $sites
     * @return int[]
     * @throws ToolCallException
     */
    private function _entryIds(?string $section, ?string $type, ?array $entryIds, ?string $search, ?array $sites): array
    {
        if ($section === null && $type === null && empty($entryIds) && $search === null) {
            throw new ToolCallException('Give a section, an entry type, entry IDs or a search query.');
        }

        $query = Entry::find()->section($section ?? '*')->siteId($this->_siteIds($sites))->status(null)->unique();

        if ($type !== null) {
            $query->type($type);
        }

        if (!empty($entryIds)) {
            $query->id(array_map('intval', $entryIds));
        }

        if ($search !== null && $search !== '') {
            $query->search($search);
        }

        return array_map('intval', $query->ids());
    }

    /**
     * @param string[]|null $sites
     * @return int[]
     * @throws ToolCallException
     */
    private function _siteIds(?array $sites): array
    {
        $service = Craft::$app->getSites();

        if (empty($sites)) {
            return [$service->getPrimarySite()->id];
        }

        return array_map(function(string $handle) use ($service) {
            return $service->getSiteByHandle($handle)->id ?? throw new ToolCallException("Unknown site “{$handle}”.");
        }, $sites);
    }

    /**
     * Turns tool input into operations, picking each target's operator.
     *
     * @param array<int, array<string, mixed>> $changes
     * @param int[] $ids
     * @return Operation[]
     * @throws ToolCallException
     */
    private function _operations(array $changes, array $ids): array
    {
        if (empty($changes)) {
            throw new ToolCallException('Give at least one change.');
        }

        $operators = [];

        foreach (ContentOps::getInstance()->getTargets()->describeTargets(Entry::class, $ids) as $target) {
            $operators[$target['handle']] = $target['operator'];
        }

        return array_map(function(array $change) use ($operators) {
            $target = (string)($change['target'] ?? '');

            if (!isset($operators[$target])) {
                throw new ToolCallException("“{$target}” can’t be edited on these entries. Use get_edit_options to see what can.");
            }

            $options = [];

            foreach ((array)($change['options'] ?? []) as $name => $value) {
                // "values.heading" → ['values' => ['heading' => …]]
                $ref = &$options;
                foreach (explode(Targets::PATH_SEPARATOR, (string)$name) as $key) {
                    $ref = &$ref[$key];
                }
                $ref = $value;
                unset($ref);
            }

            return new Operation([
                'target' => $target,
                'operator' => $operators[$target],
                'operation' => (string)($change['operation'] ?? ''),
                'options' => $options,
            ]);
        }, $changes);
    }

    /**
     * Marks a new changeset as proposed by AI and returns what the AI needs to report back.
     *
     * @param Changeset $changeset
     * @return array<string, mixed>
     */
    private function _proposal(Changeset $changeset): array
    {
        $service = ContentOps::getInstance()->getChangesets();
        $service->setOptions($changeset->id, ['source' => 'ai', 'client' => $this->context->client]);
        $changeset = $service->getChangesetById($changeset->id);

        return $this->_summary($changeset) + [
            'sampleChanges' => $this->_changes($changeset, ChangeStatus::Pending, self::SAMPLE_SIZE, 0),
            'next' => $this->context->canApply()
                ? 'Show the user the sample changes. Call apply_changeset only after they confirm.'
                : 'Nothing has been saved. Ask the user to review and apply it in the Craft control panel: ' . $this->_reviewUrl($changeset),
        ];
    }

    /**
     * @param Changeset $changeset
     * @return array<string, mixed>
     */
    private function _summary(Changeset $changeset): array
    {
        return [
            'id' => $changeset->id,
            'type' => $changeset->type->value,
            'status' => $changeset->status->value,
            'counts' => array_filter($changeset->counts),
            'source' => ($changeset->options['source'] ?? null) === 'ai' ? 'ai:' . ($changeset->options['client'] ?? '?') : 'person',
            'reviewUrl' => $this->_reviewUrl($changeset),
            'created' => $changeset->dateCreated?->format(DATE_ATOM),
        ];
    }

    /**
     * @param Changeset $changeset
     * @param ChangeStatus|null $status
     * @param int $limit
     * @param int $offset
     * @return array<int, array<string, mixed>>
     */
    private function _changes(Changeset $changeset, ?ChangeStatus $status, int $limit, int $offset): array
    {
        $targets = ContentOps::getInstance()->getTargets();

        return array_map(fn($change) => [
            'entryId' => $change->elementId,
            'site' => Craft::$app->getSites()->getSiteById($change->siteId)?->handle,
            'target' => $targets->label($change->target),
            'status' => $change->status,
            'before' => $this->_plain(Values::decode($change->oldValue)),
            'after' => $this->_plain(Values::decode($change->newValue)),
            'error' => $change->error,
        ], ContentOps::getInstance()->getChangesets()->getChanges($changeset->id, $status, $limit, $offset));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function _plain(mixed $value): mixed
    {
        if (is_string($value) && mb_strlen($value) > 500) {
            return mb_substr($value, 0, 497) . '…';
        }

        return $value;
    }

    /**
     * @param Changeset $changeset
     * @return string
     */
    private function _reviewUrl(Changeset $changeset): string
    {
        $path = $changeset->type->value === 'findReplace' && $changeset->status->value === 'previewed'
            ? "content-ops/find-replace/$changeset->id"
            : "content-ops/history/$changeset->id";

        return UrlHelper::cpUrl($path);
    }

    /**
     * Small changesets run right away (an MCP call waits for the answer); big ones go to the queue.
     *
     * @param Changeset $changeset
     * @param callable $now
     * @param callable $queue
     * @throws Throwable
     */
    private function _run(Changeset $changeset, callable $now, callable $queue): void
    {
        $size = $changeset->getCount('pending') + $changeset->getCount('applied');

        if ($size <= self::SYNC_APPLY_LIMIT) {
            $now();
            return;
        }

        $queue();
    }

    /**
     * @param int $id
     * @return Changeset
     * @throws ToolCallException
     */
    private function _changeset(int $id): Changeset
    {
        $service = ContentOps::getInstance()->getChangesets();
        $changeset = $id > 0 && $id <= 2147483647 ? $service->getChangesetById($id) : null;

        if ($changeset === null || ($this->context->user !== null && !$service->canView($changeset, $this->context->user))) {
            throw new ToolCallException("Changeset $id doesn't exist.");
        }

        return $changeset;
    }
}
