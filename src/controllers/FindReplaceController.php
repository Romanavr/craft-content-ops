<?php

namespace romanavr\contentops\controllers;

use Craft;
use craft\web\Controller;
use romanavr\contentops\ContentOps;
use romanavr\contentops\enums\ChangesetType;
use romanavr\contentops\helpers\ChangeRows;
use romanavr\contentops\models\Changeset;
use romanavr\contentops\models\FindReplaceScope;
use romanavr\contentops\models\MatchSpec;
use yii\base\InvalidArgumentException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Find & Replace CP pages: search form, results with per-match exclusion, apply.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class FindReplaceController extends Controller
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Changes (fields) listed per results page.
     */
    public const PAGE_SIZE = 50;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws ForbiddenHttpException
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('contentOps:findReplace');

        return true;
    }

    /**
     * Shows the search form: prefilled from an earlier search (`?from=`), or with the values of a search
     * that just failed validation.
     *
     * @param MatchSpec|null $spec
     * @param FindReplaceScope|null $scope
     * @return Response
     */
    public function actionIndex(?MatchSpec $spec = null, ?FindReplaceScope $scope = null): Response
    {
        $from = (int)$this->request->getQueryParam('from');
        $previous = $from ? ContentOps::getInstance()->getChangesets()->getChangesetById($from) : null;
        $options = $previous?->operations[0]->options ?? [];

        return $this->renderTemplate('content-ops/find-replace/_index.twig', [
            'spec' => $spec ?? new MatchSpec(array_intersect_key($options, array_flip(['find', 'replace', 'regex', 'caseSensitive', 'wholeWord', 'html']))),
            'scope' => $scope ?? new FindReplaceScope($previous->options['scope'] ?? []),
            'sections' => Craft::$app->getEntries()->getEditableSections(),
            'sites' => Craft::$app->getSites()->getEditableSites(),
        ]);
    }

    /**
     * Starts a search (the preview runs in the background).
     *
     * @return Response|null
     */
    public function actionSearch(): ?Response
    {
        $this->requirePostRequest();
        $body = $this->request;

        $spec = new MatchSpec([
            'find' => (string)$body->getBodyParam('find'),
            'replace' => (string)$body->getBodyParam('replace'),
            'regex' => (bool)$body->getBodyParam('regex'),
            'caseSensitive' => (bool)$body->getBodyParam('caseSensitive'),
            'wholeWord' => (bool)$body->getBodyParam('wholeWord'),
            'html' => $body->getBodyParam('links') ? MatchSpec::HTML_TEXT_AND_LINKS : MatchSpec::HTML_TEXT,
        ]);

        try {
            $scope = new FindReplaceScope([
                'sections' => $this->_selection('sections', Craft::t('content-ops', 'Choose at least one section.')),
                'siteIds' => array_map('intval', $this->_selection('siteIds', Craft::t('content-ops', 'Choose at least one site.'))),
                'includeNested' => (bool)$body->getBodyParam('includeNested'),
            ]);

            $id = ContentOps::getInstance()->getFindReplace()->queuePreview($spec, $scope, static::currentUser());
        } catch (InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
            Craft::$app->getUrlManager()->setRouteParams(['spec' => $spec, 'scope' => $scope ?? new FindReplaceScope()]);

            return null;
        }

        ContentOps::getInstance()->getChangesets()->setOptions($id, ['scope' => $scope->toArray()]);

        return $this->redirect("content-ops/find-replace/$id");
    }

    /**
     * Shows a search's matches (or its progress).
     *
     * @param int $changesetId
     * @return Response
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionResults(int $changesetId): Response
    {
        $changeset = $this->_changeset($changesetId);
        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $rows = [];

        if ($changeset->status->value === 'previewed') {
            $matches = ContentOps::getInstance()->getFindReplace()->matches($changeset, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
            $display = ChangeRows::build($changeset, array_map(fn(array $row) => $row['change'], $matches));

            foreach ($matches as $i => $row) {
                $rows[] = $row + ['display' => $display[$i]];
            }
        }

        $fieldCount = $changeset->getCount('pending') + $changeset->getCount('skipped');

        return $this->renderTemplate('content-ops/find-replace/_results.twig', [
            'changeset' => $changeset,
            'rows' => $rows,
            'page' => $page,
            'totalPages' => max(1, (int)ceil($fieldCount / self::PAGE_SIZE)),
            'canApply' => ContentOps::getInstance()->getChangesets()->canApply($changeset, static::currentUser()),
        ]);
    }

    /**
     * Saves which matches on the current results page are excluded.
     *
     * @return Response
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionExclude(): Response
    {
        $this->requirePostRequest();
        $changeset = $this->_changeset((int)$this->request->getRequiredBodyParam('changesetId'));

        $shown = (array)$this->request->getBodyParam('shown', []);
        $included = (array)$this->request->getBodyParam('include', []);
        $excluded = [];

        foreach ($shown as $changeId => $indexes) {
            $excluded[(int)$changeId] = array_values(array_diff(
                array_map('intval', (array)$indexes),
                array_map('intval', (array)($included[$changeId] ?? [])),
            ));
        }

        try {
            ContentOps::getInstance()->getFindReplace()->exclude($changeset, $excluded);
        } catch (InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
            return $this->redirectToPostedUrl();
        }

        if ($this->request->getBodyParam('apply')) {
            return $this->_apply($changeset->id);
        }

        $this->setSuccessFlash(Craft::t('content-ops', 'Selection saved.'));

        return $this->redirectToPostedUrl();
    }

    // Private Methods
    // =========================================================================

    /**
     * Reads a “checkbox select with All” field: `*` (or no field at all, e.g. Sites on a single-site install)
     * means everything; an explicit empty choice is an error rather than silently meaning everything.
     *
     * @param string $name
     * @param string $emptyMessage
     * @return string[] Empty for “all”
     * @throws InvalidArgumentException
     */
    private function _selection(string $name, string $emptyMessage): array
    {
        $value = $this->request->getBodyParam($name);

        if ($value === null || $value === '*') {
            return [];
        }

        $values = array_values(array_filter((array)$value, fn($v) => $v !== '' && $v !== null));

        if (empty($values)) {
            throw new InvalidArgumentException($emptyMessage);
        }

        return $values;
    }

    /**
     * @param int $changesetId
     * @return Response
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     */
    private function _apply(int $changesetId): Response
    {
        $changeset = $this->_changeset($changesetId);
        $service = ContentOps::getInstance()->getChangesets();

        if (!$service->canApply($changeset, static::currentUser())) {
            throw new ForbiddenHttpException('You can’t apply this changeset.');
        }

        $service->queueApply($changeset->id);
        $this->setSuccessFlash(Craft::t('content-ops', 'Applying changeset #{id}…', ['id' => $changeset->id]));

        return $this->redirect("content-ops/history/$changeset->id");
    }

    /**
     * @param int $id
     * @return Changeset
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    private function _changeset(int $id): Changeset
    {
        $changeset = ContentOps::getInstance()->getChangesets()->getChangesetById($id);

        if ($changeset === null || $changeset->type !== ChangesetType::FindReplace) {
            throw new NotFoundHttpException("Find & Replace changeset $id doesn’t exist.");
        }

        if (!ContentOps::getInstance()->getChangesets()->canView($changeset, static::currentUser())) {
            throw new ForbiddenHttpException('You can’t view this changeset.');
        }

        return $changeset;
    }
}
