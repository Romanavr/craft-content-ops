<?php

namespace romanavr\contentops\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\StringHelper;
use romanavr\contentops\ContentOps;
use romanavr\contentops\models\FindReplaceScope;
use romanavr\contentops\models\MatchSpec;
use yii\base\InvalidArgumentException;
use yii\console\ExitCode;

/**
 * Find & Replace from the console: previews a changeset, then optionally applies it.
 *
 * ```
 * craft content-ops/find-replace --find="Acme Corp" --replace="Globex Inc" --section=news
 * craft content-ops/find-replace --find="http://old.test" --replace="https://new.test" --links --apply
 * ```
 *
 * @author Romanavr
 * @since 1.0.0
 */
class FindReplaceController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string Text (or regex with --regex) to find.
     */
    public string $find = '';

    /**
     * @var string Replacement.
     */
    public string $replace = '';

    /**
     * @var bool Treat --find as a regular expression (no delimiters; use $1 in --replace).
     */
    public bool $regex = false;

    /**
     * @var bool Case-sensitive matching.
     */
    public bool $caseSensitive = true;

    /**
     * @var bool Whole words only.
     */
    public bool $wholeWord = false;

    /**
     * @var bool Also replace inside href/src attributes of HTML fields.
     */
    public bool $links = false;

    /**
     * @var string|null Section handle(s), comma-separated. Default: all.
     */
    public ?string $section = null;

    /**
     * @var string|null Entry type handle(s), comma-separated. Default: all.
     */
    public ?string $type = null;

    /**
     * @var string|null Site handle(s), comma-separated. Default: all.
     */
    public ?string $site = null;

    /**
     * @var string|null Target handles (fields, `title`, or matrix.type.field), comma-separated. Default: all text fields.
     */
    public ?string $fields = null;

    /**
     * @var bool Search inside Matrix nested entries (Pro).
     */
    public bool $nested = false;

    /**
     * @var bool Apply right after previewing.
     */
    public bool $apply = false;

    /**
     * @var string|null Username/email to act as.
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
            'find', 'replace', 'regex', 'caseSensitive', 'wholeWord', 'links',
            'section', 'type', 'site', 'fields', 'nested', 'apply', 'as',
        ]);
    }

    /**
     * Previews a find & replace (and applies it with --apply).
     *
     * @return int
     */
    public function actionIndex(): int
    {
        $user = $this->as !== null ? Craft::$app->getUsers()->getUserByUsernameOrEmail($this->as) : null;

        if ($this->as !== null && $user === null) {
            $this->stderr("No user found for “{$this->as}”.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        try {
            $changeset = ContentOps::getInstance()->getFindReplace()->preview(
                new MatchSpec([
                    'find' => $this->find,
                    'replace' => $this->replace,
                    'regex' => $this->regex,
                    'caseSensitive' => $this->caseSensitive,
                    'wholeWord' => $this->wholeWord,
                    'html' => $this->links ? MatchSpec::HTML_TEXT_AND_LINKS : MatchSpec::HTML_TEXT,
                ]),
                new FindReplaceScope([
                    'sections' => $this->_list($this->section),
                    'types' => $this->_list($this->type),
                    'siteIds' => array_map(fn(string $handle) => Craft::$app->getSites()->getSiteByHandle($handle)->id ?? throw new InvalidArgumentException("Unknown site “{$handle}”."), $this->_list($this->site)),
                    'targets' => $this->_list($this->fields),
                    'includeNested' => $this->nested,
                ]),
                $user,
            );
        } catch (InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $this->stdout(sprintf("%d matches in %d fields.\n\n", $changeset->getCount('matches'), $changeset->getCount('pending')));
        ChangesetsController::printChangeset($this, $changeset, 20);

        if (!$this->apply) {
            $this->stdout("\nNothing written. Apply with: craft content-ops/changesets/apply $changeset->id\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("\n");

        return $this->run('changesets/apply', [$changeset->id]);
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string|null $value
     * @return string[]
     */
    private function _list(?string $value): array
    {
        return $value === null || $value === '' ? [] : StringHelper::split($value);
    }
}
