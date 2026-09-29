<?php

namespace romanavr\contentops\services;

use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Matrix;
use craft\helpers\Json;
use craft\helpers\Queue;
use romanavr\contentops\ContentOps;
use romanavr\contentops\enums\ChangesetStatus;
use romanavr\contentops\enums\ChangesetType;
use romanavr\contentops\enums\ChangeStatus;
use romanavr\contentops\helpers\Matcher;
use romanavr\contentops\helpers\Values;
use romanavr\contentops\jobs\PreviewFindReplace;
use romanavr\contentops\models\Changeset;
use romanavr\contentops\models\FindReplaceScope;
use romanavr\contentops\models\MatchSpec;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Selection;
use romanavr\contentops\models\Target;
use romanavr\contentops\operators\FindReplaceOperator;
use romanavr\contentops\records\Change;
use romanavr\contentops\records\Changeset as ChangesetRecord;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Find & Replace across entries: builds a changeset whose rows are the fields containing matches.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class FindReplace extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Previews a find & replace and records it as a changeset.
     *
     * @param MatchSpec $spec
     * @param FindReplaceScope $scope
     * @param User|null $user
     * @param int|null $changesetId Fill in this (previewing) changeset instead of creating one
     * @return Changeset
     * @throws InvalidArgumentException if the spec is invalid or nothing is searchable
     * @throws \yii\db\Exception
     */
    public function preview(MatchSpec $spec, FindReplaceScope $scope, ?User $user = null, ?int $changesetId = null): Changeset
    {
        if (!$spec->validate()) {
            throw new InvalidArgumentException(implode(' ', $spec->getFirstErrors()));
        }

        Matcher::validate($spec);

        $siteIds = $scope->siteIds ?: Craft::$app->getSites()->getAllSiteIds();
        $scopeIds = $this->_scopeElementIds($scope, $siteIds);
        $candidateIds = $this->canPrefilter($spec) ? array_values(array_intersect($scopeIds, $this->prefilter($spec, $siteIds, $scope->includeNested))) : $scopeIds;
        $targets = $scope->targets ?: $this->textTargets($candidateIds, $scope->includeNested);

        if (empty($targets)) {
            throw new InvalidArgumentException('There are no text fields to search in this scope.');
        }

        $plugin = ContentOps::getInstance();
        $changeset = $plugin->getPreviewer()->preview(
            new Selection([
                'elementType' => Entry::class,
                'criteria' => ['id' => $candidateIds ?: [0]],
                'siteIds' => $siteIds,
            ]),
            array_map(fn(string $target) => new Operation([
                'target' => $target,
                'operator' => FindReplaceOperator::handle(),
                'operation' => 'replace',
                'options' => $spec->toArray(),
            ]), $targets),
            $user,
            ChangesetType::FindReplace,
            ignoreMissingTargets: true,
            changesetId: $changesetId,
        );

        $plugin->getChangesets()->refreshCounts($changeset->id, ['matches' => $this->countMatches($changeset)]);

        if ($changesetId !== null) {
            $plugin->getChangesets()->setStatus($changeset->id, ChangesetStatus::Previewed);
        }

        return $plugin->getChangesets()->getChangesetById($changeset->id);
    }

    /**
     * Starts a preview in the background. Returns the changeset (status `previewing`) it will fill in.
     *
     * @param MatchSpec $spec
     * @param FindReplaceScope $scope
     * @param User|null $user
     * @return int The changeset ID
     * @throws InvalidArgumentException if the spec is invalid
     * @throws \yii\db\Exception
     */
    public function queuePreview(MatchSpec $spec, FindReplaceScope $scope, ?User $user = null): int
    {
        if (!$spec->validate()) {
            throw new InvalidArgumentException(implode(' ', $spec->getFirstErrors()));
        }

        Matcher::validate($spec);

        $record = new ChangesetRecord();
        $record->type = ChangesetType::FindReplace->value;
        $record->status = ChangesetStatus::Previewing->value;
        $record->userId = $user?->id;
        $record->selection = Json::encode(['elementType' => Entry::class, 'criteria' => [], 'siteIds' => $scope->siteIds ?: null]);
        $record->operations = Json::encode([[
            'target' => '*',
            'operator' => FindReplaceOperator::handle(),
            'operation' => 'replace',
            'options' => $spec->toArray(),
        ]]);
        $record->save(false);

        Queue::push(new PreviewFindReplace([
            'changesetId' => $record->id,
            'spec' => $spec->toArray(),
            'scope' => $scope->toArray(),
            'userId' => $user?->id,
        ]));

        return $record->id;
    }

    /**
     * Lists the matches of a changeset's pending changes: `[['change' => Change, 'target' => Target|null, 'matches' => [...]], …]`.
     *
     * @param Changeset $changeset
     * @param int|null $limit Max changes
     * @param int $offset
     * @return array<int, array<string, mixed>>
     */
    public function matches(Changeset $changeset, ?int $limit = null, int $offset = 0): array
    {
        $result = [];

        $excluded = (array)($changeset->options['excluded'] ?? []);

        foreach (ContentOps::getInstance()->getChangesets()->getChanges($changeset->id, null, $limit, $offset) as $change) {
            if (!in_array($change->status, [ChangeStatus::Pending->value, ChangeStatus::Skipped->value], true)) {
                continue;
            }

            $old = Values::decode($change->oldValue);

            if (!is_string($old)) {
                continue;
            }

            $result[] = [
                'change' => $change,
                'excluded' => array_map('intval', (array)($excluded[$change->id] ?? [])),
                'matches' => Matcher::findAll($old, $this->_spec($changeset, $change->target), $this->_isHtml($change->target)),
            ];
        }

        return $result;
    }

    /**
     * Excludes individual matches from a previewed changeset and recomputes the affected changes.
     * A change whose matches are all excluded is marked as skipped.
     *
     * @param Changeset $changeset
     * @param array<int, int[]> $excluded Match indexes to leave alone, keyed by change ID (replaces earlier exclusions)
     * @throws InvalidArgumentException if the changeset isn't a previewed find & replace
     * @throws \yii\db\Exception
     */
    public function exclude(Changeset $changeset, array $excluded): void
    {
        if ($changeset->type !== ChangesetType::FindReplace || $changeset->status->value !== 'previewed') {
            throw new InvalidArgumentException('Only previewed Find & Replace changesets can be refined.');
        }

        /** @var Change[] $changes */
        $changes = Change::find()->where(['changesetId' => $changeset->id, 'id' => array_keys($excluded)])->all();

        foreach ($changes as $change) {
            $old = Values::decode($change->oldValue);

            if (!is_string($old)) {
                continue;
            }

            $skip = array_values(array_unique(array_map('intval', $excluded[$change->id] ?? [])));
            [$new, $count] = Matcher::replace($old, $this->_spec($changeset, $change->target), $this->_isHtml($change->target), $skip);

            $change->newValue = Values::encode($count === 0 ? $old : $new);
            $change->status = $count === 0 ? ChangeStatus::Skipped->value : ChangeStatus::Pending->value;
            $change->error = $count === 0 ? 'All matches excluded.' : null;
            $change->save(false);
        }

        $all = array_replace((array)($changeset->options['excluded'] ?? []), $excluded);
        $all = array_filter($all);
        $changesets = ContentOps::getInstance()->getChangesets();
        $changesets->setOptions($changeset->id, ['excluded' => $all]);
        $changesets->refreshCounts($changeset->id, ['excludedMatches' => array_sum(array_map('count', $all))]);
    }

    /**
     * Returns whether the SQL pre-filter gives a reliable superset for this spec.
     *
     * Content is stored as JSON, whose escaping (unicode, quotes, backslashes) differs between MySQL and Postgres,
     * so only printable ASCII without quotes/backslashes is pre-filtered; everything else scans the whole scope.
     *
     * @param MatchSpec $spec
     * @return bool
     */
    public function canPrefilter(MatchSpec $spec): bool
    {
        return !$spec->regex && preg_match('/^[\x20-\x7E]+$/', $spec->find) === 1 && !preg_match('/["\\\\]/', $spec->find);
    }

    /**
     * Returns IDs of entries whose title or content (or, with `$includeNested`, whose nested entries' content)
     * contains the search text in any of the sites. A superset of real matches; exact matching happens in PHP.
     *
     * @param MatchSpec $spec
     * @param int[] $siteIds
     * @param bool $includeNested
     * @return int[]
     */
    public function prefilter(MatchSpec $spec, array $siteIds, bool $includeNested): array
    {
        $db = Craft::$app->getDb();
        $content = $db->getIsPgsql() ? 'CAST([[es.content]] AS TEXT)' : 'CAST([[es.content]] AS CHAR)';
        $title = '[[es.title]]';

        if (!$spec->caseSensitive) {
            $content = "LOWER($content)";
            $title = "LOWER($title)";
        }

        $needle = '%' . addcslashes($spec->caseSensitive ? $spec->find : strtolower($spec->find), '%_\\') . '%';
        $condition = ['or', ['like', $content, $needle, false], ['like', $title, $needle, false]];

        $own = (new Query())
            ->select(['es.elementId'])
            ->distinct()
            ->from(['es' => CraftTable::ELEMENTS_SITES])
            ->where(['es.siteId' => $siteIds])
            ->andWhere($condition)
            ->column();

        $nested = !$includeNested ? [] : (new Query())
            ->select(['en.primaryOwnerId'])
            ->distinct()
            ->from(['es' => CraftTable::ELEMENTS_SITES])
            ->innerJoin(['en' => CraftTable::ENTRIES], '[[en.id]] = [[es.elementId]]')
            ->where(['es.siteId' => $siteIds])
            ->andWhere(['not', ['en.primaryOwnerId' => null]])
            ->andWhere($condition)
            ->column();

        return array_values(array_unique(array_map('intval', [...$own, ...$nested])));
    }

    /**
     * Returns the text targets on the given entries' field layouts: `title`, Plain Text and CKEditor fields,
     * and (with `$includeNested`) the same inside their Matrix fields' entry types.
     *
     * @param int[] $elementIds
     * @param bool $includeNested
     * @return string[]
     */
    public function textTargets(array $elementIds, bool $includeNested): array
    {
        if (empty($elementIds)) {
            return [];
        }

        $operator = new FindReplaceOperator();
        $layoutIds = (new Query())
            ->select(['fieldLayoutId'])
            ->distinct()
            ->from(CraftTable::ELEMENTS)
            ->where(['id' => $elementIds])
            ->column();

        $targets = ['title'];
        $fields = Craft::$app->getFields();

        foreach ($layoutIds as $layoutId) {
            foreach ($fields->getLayoutById((int)$layoutId)?->getCustomFields() ?? [] as $field) {
                if ($operator->supports(new Target(['handle' => $field->handle, 'field' => $field]))) {
                    $targets[] = $field->handle;
                    continue;
                }

                if (!$includeNested || !$field instanceof Matrix) {
                    continue;
                }

                foreach ($field->getEntryTypes() as $entryType) {
                    foreach ($entryType->getFieldLayout()->getCustomFields() as $innerField) {
                        if ($operator->supports(new Target(['handle' => $innerField->handle, 'field' => $innerField]))) {
                            $targets[] = implode(Targets::PATH_SEPARATOR, [$field->handle, $entryType->handle, $innerField->handle]);
                        }
                    }
                }
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * Counts matches across a changeset's pending changes.
     *
     * @param Changeset $changeset
     * @return int
     */
    public function countMatches(Changeset $changeset): int
    {
        $count = 0;

        foreach ($this->matches($changeset) as $row) {
            $count += count($row['matches']);
        }

        return $count;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param FindReplaceScope $scope
     * @param int[] $siteIds
     * @return int[]
     */
    private function _scopeElementIds(FindReplaceScope $scope, array $siteIds): array
    {
        $query = Entry::find()
            ->section($scope->sections ?: '*')
            ->siteId($siteIds)
            ->status(null)
            ->unique();

        if ($scope->types) {
            $query->type($scope->types);
        }

        return array_map('intval', $query->ids());
    }

    /**
     * @param Changeset $changeset
     * @param string $target
     * @return Operation|null
     */
    private function _operationFor(Changeset $changeset, string $target): ?Operation
    {
        return $changeset->getOperationsForTarget($target)[0] ?? null;
    }

    /**
     * @param Changeset $changeset
     * @param string $target
     * @return MatchSpec
     */
    private function _spec(Changeset $changeset, string $target): MatchSpec
    {
        return FindReplaceOperator::spec($this->_operationFor($changeset, $target)->options ?? []);
    }

    /**
     * @param string $handle
     * @return bool
     */
    private function _isHtml(string $handle): bool
    {
        $parts = explode(Targets::PATH_SEPARATOR, $handle);
        $field = Craft::$app->getFields()->getFieldByHandle(end($parts));

        return $field !== null && FindReplaceOperator::isHtml(new Target(['handle' => $handle, 'field' => $field]));
    }
}
