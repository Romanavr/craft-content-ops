<?php

namespace romanavr\contentops\controllers;

use Craft;
use craft\web\Controller;
use romanavr\contentops\ContentOps;
use romanavr\contentops\enums\ChangeStatus;
use romanavr\contentops\helpers\ChangeRows;
use romanavr\contentops\models\Changeset;
use yii\base\InvalidArgumentException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Changeset history: list, detail, apply and undo.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class HistoryController extends Controller
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Changesets per page.
     */
    public const PAGE_SIZE = 50;

    /**
     * @var int Changes per page on the detail view.
     */
    public const CHANGES_PAGE_SIZE = 100;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws ForbiddenHttpException if the user can neither bulk edit nor view history
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $user = static::currentUser();

        if (!$user->can('contentOps:viewHistory') && !$user->can('contentOps:bulkEdit')) {
            throw new ForbiddenHttpException('You can’t view the Content Ops history.');
        }

        return true;
    }

    /**
     * Lists changesets (all of them with “View history”, otherwise the user's own).
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $user = static::currentUser();
        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $changesets = ContentOps::getInstance()->getChangesets();

        [$items, $total] = $changesets->getChangesetsPage(
            $user->can('contentOps:viewHistory') ? null : $user->id,
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE,
        );

        return $this->renderTemplate('content-ops/history/_index.twig', [
            'changesets' => $items,
            'users' => $this->_users($items),
            'page' => $page,
            'totalPages' => max(1, (int)ceil($total / self::PAGE_SIZE)),
            'total' => $total,
        ]);
    }

    /**
     * Shows a changeset and its changes.
     *
     * @param int $changesetId
     * @return Response
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionView(int $changesetId): Response
    {
        $changeset = $this->_viewableChangeset($changesetId);
        $service = ContentOps::getInstance()->getChangesets();
        $user = static::currentUser();
        $status = ChangeStatus::tryFrom((string)$this->request->getQueryParam('status'));
        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $filteredCount = $status ? $changeset->getCount($status->value) : array_sum(array_map(fn(ChangeStatus $s) => $changeset->getCount($s->value), ChangeStatus::cases()));

        return $this->renderTemplate('content-ops/history/_view.twig', [
            'changeset' => $changeset,
            'user' => $changeset->userId ? Craft::$app->getUsers()->getUserById($changeset->userId) : null,
            'rows' => ChangeRows::build($changeset, $service->getChanges($changeset->id, $status, self::CHANGES_PAGE_SIZE, ($page - 1) * self::CHANGES_PAGE_SIZE)),
            'status' => $status,
            'statuses' => ChangeStatus::cases(),
            'page' => $page,
            'totalPages' => max(1, (int)ceil($filteredCount / self::CHANGES_PAGE_SIZE)),
            'canUndo' => $service->canUndo($changeset, $user),
            'canApply' => $service->canApply($changeset, $user),
        ]);
    }

    /**
     * Queues an undo.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionUndo(): ?Response
    {
        $this->requirePostRequest();
        $changeset = $this->_viewableChangeset((int)$this->request->getRequiredBodyParam('changesetId'));
        $service = ContentOps::getInstance()->getChangesets();

        if (!$service->canUndo($changeset, static::currentUser())) {
            throw new ForbiddenHttpException('You can’t undo this changeset.');
        }

        try {
            $service->queueUndo($changeset->id, (bool)$this->request->getBodyParam('force'));
        } catch (InvalidArgumentException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('content-ops', 'Undoing changeset #{id}…', ['id' => $changeset->id]));
    }

    /**
     * Queues a previewed changeset (e.g. one previewed from the console or an abandoned modal).
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionApply(): ?Response
    {
        $this->requirePostRequest();
        $changeset = $this->_viewableChangeset((int)$this->request->getRequiredBodyParam('changesetId'));
        $service = ContentOps::getInstance()->getChangesets();

        if (!$service->canApply($changeset, static::currentUser())) {
            throw new ForbiddenHttpException('You can’t apply this changeset.');
        }

        try {
            $service->queueApply($changeset->id);
        } catch (InvalidArgumentException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('content-ops', 'Applying changeset #{id}…', ['id' => $changeset->id]));
    }

    // Private Methods
    // =========================================================================

    /**
     * @param int $id
     * @return Changeset
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    private function _viewableChangeset(int $id): Changeset
    {
        $changeset = ContentOps::getInstance()->getChangesets()->getChangesetById($id)
            ?? throw new NotFoundHttpException("Changeset $id doesn’t exist.");

        if (!ContentOps::getInstance()->getChangesets()->canView($changeset, static::currentUser())) {
            throw new ForbiddenHttpException('You can’t view this changeset.');
        }

        return $changeset;
    }

    /**
     * @param Changeset[] $changesets
     * @return array<int, \craft\elements\User>
     */
    private function _users(array $changesets): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn(Changeset $changeset) => $changeset->userId, $changesets))));

        if (empty($ids)) {
            return [];
        }

        $users = [];

        foreach (\craft\elements\User::find()->id($ids)->status(null)->all() as $user) {
            $users[$user->id] = $user;
        }

        return $users;
    }
}
