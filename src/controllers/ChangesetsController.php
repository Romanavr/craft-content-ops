<?php

namespace romanavr\contentops\controllers;

use craft\web\Controller;
use romanavr\contentops\ContentOps;
use romanavr\contentops\models\Changeset;
use yii\base\InvalidArgumentException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Applies changesets and reports their progress.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class ChangesetsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Queues a previewed changeset.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     */
    public function actionApply(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('contentOps:bulkEdit');
        $changeset = $this->_changeset((int)$this->request->getRequiredBodyParam('changesetId'));

        if ($changeset->userId !== static::currentUser()->id && !static::currentUser()->admin) {
            throw new ForbiddenHttpException('You can only apply your own changesets.');
        }

        try {
            ContentOps::getInstance()->getChangesets()->queueApply($changeset->id);
        } catch (InvalidArgumentException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess("Applying changeset #{$changeset->id}…", ['changesetId' => $changeset->id]);
    }

    /**
     * Returns a changeset's status and counts.
     *
     * @param int $id
     * @return Response
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     */
    public function actionStatus(int $id): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('contentOps:bulkEdit');
        $changeset = $this->_changeset($id);

        return $this->asJson([
            'id' => $changeset->id,
            'status' => $changeset->status->value,
            'counts' => $changeset->counts,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * @param int $id
     * @return Changeset
     * @throws NotFoundHttpException
     */
    private function _changeset(int $id): Changeset
    {
        return ContentOps::getInstance()->getChangesets()->getChangesetById($id)
            ?? throw new NotFoundHttpException("Changeset $id doesn’t exist.");
    }
}
