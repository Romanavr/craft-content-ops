<?php

namespace romanavr\contentops\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\controllers\ElementIndexesController;
use romanavr\contentops\ContentOps;
use romanavr\contentops\enums\ChangesetType;
use romanavr\contentops\helpers\ChangeRows;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Selection;
use yii\base\InvalidArgumentException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Bulk edit endpoints for the element index modal.
 *
 * Extends Craft's element index controller so "all matching elements" is resolved with exactly the
 * index's own query (source, search, filters, condition rules, site).
 *
 * @author Romanavr
 * @since 1.0.0
 */
class BulkEditController extends ElementIndexesController
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Change rows rendered in the preview table.
     */
    public const PREVIEW_ROWS = 100;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws ForbiddenHttpException if the user can't bulk edit
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('contentOps:bulkEdit');

        return true;
    }

    /**
     * Returns the editable targets for the selection.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionTargets(): Response
    {
        $ids = $this->_resolveElementIds();

        return $this->asJson([
            'total' => count($ids),
            'targets' => ContentOps::getInstance()->getTargets()->describeTargets($this->elementType, $ids),
        ]);
    }

    /**
     * Previews operations on the selection and returns the changeset summary and a preview table.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException if the user can't edit one of the sites
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();

        $ids = $this->_resolveElementIds();
        $siteIds = $this->_resolveSiteIds();
        $operations = array_map(fn(array $config) => new Operation([
            'target' => (string)($config['target'] ?? ''),
            'operator' => (string)($config['operator'] ?? ''),
            'operation' => (string)($config['operation'] ?? ''),
            'options' => $this->_normalizeOptions((array)($config['options'] ?? [])),
        ]), (array)$this->request->getRequiredBodyParam('operations'));

        try {
            $changeset = ContentOps::getInstance()->getPreviewer()->preview(
                new Selection([
                    'elementType' => $this->elementType,
                    'criteria' => ['id' => $ids ?: [0]],
                    'siteIds' => $siteIds,
                ]),
                $operations,
                static::currentUser(),
                ChangesetType::BulkEdit,
            );
        } catch (InvalidArgumentException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asJson([
            'changesetId' => $changeset->id,
            'counts' => $changeset->counts,
            'html' => $this->getView()->renderTemplate('content-ops/_bulk-edit/preview.twig', [
                'changeset' => $changeset,
                'rows' => ChangeRows::build($changeset, ContentOps::getInstance()->getChangesets()->getChanges($changeset->id, limit: self::PREVIEW_ROWS)),
                'limit' => self::PREVIEW_ROWS,
            ]),
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the IDs of the elements the modal targets: the selected ones, or all that match the index query.
     *
     * @return int[]
     * @throws BadRequestHttpException
     */
    private function _resolveElementIds(): array
    {
        $scope = $this->request->getBodyParam('scope', 'selected');

        if ($scope === 'all') {
            return array_map('intval', (clone $this->elementQuery)
                ->limit(null)
                ->offset(null)
                ->unique()
                ->ids());
        }

        $ids = array_filter(array_map('intval', (array)$this->request->getBodyParam('elementIds', [])));

        if (empty($ids)) {
            throw new BadRequestHttpException('No elements selected.');
        }

        /** @var class-string<ElementInterface> $elementType */
        $elementType = $this->elementType;

        return array_map('intval', $elementType::find()->id($ids)->site('*')->unique()->status(null)->ids());
    }

    /**
     * @return int[]
     * @throws ForbiddenHttpException
     */
    private function _resolveSiteIds(): array
    {
        $sites = Craft::$app->getSites();
        $siteIds = array_map('intval', (array)$this->request->getBodyParam('siteIds', []));

        if (empty($siteIds)) {
            // Default to the site the index is showing.
            $siteIds = [(int)($this->request->getBodyParam('criteria')['siteId'] ?? $sites->getCurrentSite()->id)];
        }

        foreach ($siteIds as $siteId) {
            $site = $sites->getSiteById($siteId);

            if ($site === null || ($sites->getTotalSites() > 1 && !static::currentUser()->can("editSite:$site->uid"))) {
                throw new ForbiddenHttpException('You can’t edit one of the chosen sites.');
            }
        }

        return $siteIds;
    }

    /**
     * Casts form values: `"1"`/`"0"` lightswitches to booleans.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function _normalizeOptions(array $options): array
    {
        if (array_key_exists('caseSensitive', $options)) {
            $options['caseSensitive'] = filter_var($options['caseSensitive'], FILTER_VALIDATE_BOOLEAN);
        }

        return $options;
    }
}
