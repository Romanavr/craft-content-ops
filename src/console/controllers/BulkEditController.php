<?php

namespace romanavr\contentops\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use craft\helpers\StringHelper;
use romanavr\contentops\ContentOps;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Selection;
use romanavr\contentops\models\Target;
use romanavr\contentops\services\Targets;
use yii\base\InvalidArgumentException;
use yii\console\ExitCode;

/**
 * Bulk edits entries from the console: previews a changeset, then optionally applies it.
 *
 * ```
 * craft content-ops/bulk-edit --section=news --field=title --op=append --value=" (2026)"
 * craft content-ops/bulk-edit --section=news --site=en,de --field=summary --op=replace --find=Acme --replace=Globex --apply
 * ```
 *
 * @author Romanavr
 * @since 1.0.0
 */
class BulkEditController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string|null Section handle(s), comma-separated.
     */
    public ?string $section = null;

    /**
     * @var string|null Entry type handle(s), comma-separated.
     */
    public ?string $type = null;

    /**
     * @var string|null Entry IDs, comma-separated.
     */
    public ?string $ids = null;

    /**
     * @var string|null Entry status, e.g. `live` or `disabled`. Default: any.
     */
    public ?string $status = null;

    /**
     * @var string|null Site handle(s), comma-separated, or `*` for all. Default: the primary site.
     */
    public ?string $site = null;

    /**
     * @var int|null Max number of entries.
     */
    public ?int $limit = null;

    /**
     * @var string The field handle or attribute (`title`, `slug`) to change.
     */
    public string $field = '';

    /**
     * @var string The operator. Default: the first one that supports the field.
     */
    public string $operator = '';

    /**
     * @var string The operation, e.g. `set`, `clear`, `prepend`, `append`, `replace`.
     */
    public string $op = '';

    /**
     * @var string|null Value for `set`/`prepend`/`append`.
     */
    public ?string $value = null;

    /**
     * @var string|null Search string for `replace`.
     */
    public ?string $find = null;

    /**
     * @var string|null Replacement for `replace`.
     */
    public ?string $replace = null;

    /**
     * @var bool Case-sensitive `replace`.
     */
    public bool $caseSensitive = true;

    /**
     * @var string|null Extra operator options as JSON, e.g. `{"type":"quoteBlock","contains":"Acme"}` for the matrix operator.
     */
    public ?string $options = null;

    /**
     * @var bool Apply the changeset right after previewing.
     */
    public bool $apply = false;

    /**
     * @var bool With --apply: push a queue job instead of applying synchronously.
     */
    public bool $queue = false;

    /**
     * @var string|null Username/email to act as (permission checks + attribution). Default: none (no permission checks).
     */
    public ?string $as = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'section', 'type', 'ids', 'status', 'site', 'limit',
            'field', 'operator', 'op', 'value', 'find', 'replace', 'caseSensitive',
            'options', 'apply', 'queue', 'as',
        ]);
    }

    /**
     * Previews a bulk edit (and applies it with --apply).
     *
     * @return int
     */
    public function actionIndex(): int
    {
        if ($this->field === '' || $this->op === '') {
            $this->stderr("--field and --op are required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $plugin = ContentOps::getInstance();
        $user = null;

        if ($this->as !== null) {
            $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($this->as);

            if ($user === null) {
                $this->stderr("No user found for “{$this->as}”.\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
        }

        try {
            $operation = new Operation([
                'target' => $this->field,
                'operator' => $this->operator ?: $this->_guessOperator(),
                'operation' => $this->op,
                'options' => array_merge(array_filter([
                    'value' => $this->value,
                    'find' => $this->find,
                    'replace' => $this->replace,
                    'caseSensitive' => $this->op === 'replace' ? $this->caseSensitive : null,
                ], fn($value) => $value !== null), $this->_jsonOptions()),
            ]);

            $this->stdout('Previewing … ');
            $changeset = $plugin->getPreviewer()->preview($this->_selection(), [$operation], $user, onProgress: function(int $examined) {
                $this->stdout('.');
            });
            $this->stdout(" done\n\n");
        } catch (InvalidArgumentException $e) {
            $this->stderr("\n" . $e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        ChangesetsController::printChangeset($this, $changeset, 20);

        if (!$this->apply) {
            $this->stdout("\nNothing written. Apply with: craft content-ops/changesets/apply $changeset->id\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("\n");

        return $this->run('changesets/apply', [$changeset->id, 'queue' => $this->queue]);
    }

    // Private Methods
    // =========================================================================

    /**
     * @return Selection
     */
    private function _selection(): Selection
    {
        $criteria = array_filter([
            'section' => $this->_list($this->section),
            'type' => $this->_list($this->type),
            'id' => $this->_list($this->ids),
            'status' => $this->status,
            'limit' => $this->limit,
        ], fn($value) => $value !== null);

        $siteIds = null;

        if ($this->site === '*') {
            $siteIds = Craft::$app->getSites()->getAllSiteIds();
        } elseif ($this->site !== null) {
            $siteIds = array_map(function(string $handle) {
                $site = Craft::$app->getSites()->getSiteByHandle($handle);
                if ($site === null) {
                    throw new InvalidArgumentException("Unknown site “{$handle}”.");
                }
                return $site->id;
            }, $this->_list($this->site));
        }

        return new Selection([
            'elementType' => Entry::class,
            'criteria' => $criteria,
            'siteIds' => $siteIds,
        ]);
    }

    /**
     * Picks the first operator that supports the field, judged by the field's global definition.
     *
     * @return string
     * @throws InvalidArgumentException
     */
    private function _guessOperator(): string
    {
        $plugin = ContentOps::getInstance();
        $field = Craft::$app->getFields()->getFieldByHandle($this->field);
        $isAttribute = Targets::isAttribute($this->field);

        if (!$field && !$isAttribute) {
            throw new InvalidArgumentException("Unknown field “{$this->field}”.");
        }

        $target = new Target([
            'handle' => $this->field,
            'field' => $isAttribute ? null : $field,
            'attribute' => $isAttribute ? $this->field : null,
        ]);
        $operators = $plugin->getOperators()->getOperatorsForTarget($target);

        if (empty($operators)) {
            throw new InvalidArgumentException("No operator supports “{$this->field}” yet.");
        }

        return $operators[0]::handle();
    }

    /**
     * @return array<string, mixed>
     * @throws InvalidArgumentException if --options isn't a JSON object
     */
    private function _jsonOptions(): array
    {
        if ($this->options === null || $this->options === '') {
            return [];
        }

        $options = json_decode($this->options, true);

        if (!is_array($options)) {
            throw new InvalidArgumentException('--options must be a JSON object.');
        }

        return $options;
    }

    /**
     * @param string|null $value
     * @return string[]|null
     */
    private function _list(?string $value): ?array
    {
        return $value === null ? null : StringHelper::split($value);
    }
}
