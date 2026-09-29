<?php

namespace romanavr\contentops\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use romanavr\contentops\ContentOps;
use romanavr\contentops\mcp\McpContext;
use yii\base\InvalidArgumentException;
use yii\web\Response;

/**
 * Content Ops → AI Access: create and revoke MCP access tokens.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class AiAccessController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('contentOps:mcp');

        return true;
    }

    /**
     * Lists tokens (all of them for admins, otherwise the user's own).
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $user = static::currentUser();
        $tokens = ContentOps::getInstance()->getTokens()->all($user->admin ? null : $user->id);
        $users = [];

        foreach ($tokens as $token) {
            $users[$token->userId] ??= Craft::$app->getUsers()->getUserById($token->userId);
        }

        return $this->renderTemplate('content-ops/ai-access/_index.twig', [
            'tokens' => $tokens,
            'users' => $users,
            'newToken' => Craft::$app->getSession()->getFlash('content-ops-new-token'),
            'endpoint' => UrlHelper::actionUrl('content-ops/mcp'),
            'modes' => [
                McpContext::MODE_READONLY => Craft::t('content-ops', 'Read only'),
                McpContext::MODE_PROPOSE => Craft::t('content-ops', 'Propose changes (a person applies them)'),
                McpContext::MODE_FULL => Craft::t('content-ops', 'Propose and apply changes'),
            ],
        ]);
    }

    /**
     * Creates a token for the current user and shows it once.
     *
     * @return Response|null
     */
    public function actionCreate(): ?Response
    {
        $this->requirePostRequest();

        try {
            [, $plain] = ContentOps::getInstance()->getTokens()->create(
                (string)$this->request->getBodyParam('name'),
                static::currentUser(),
                (string)$this->request->getBodyParam('mode', McpContext::MODE_PROPOSE),
            );
        } catch (InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
            return null;
        }

        Craft::$app->getSession()->setFlash('content-ops-new-token', $plain);
        $this->setSuccessFlash(Craft::t('content-ops', 'Token created. Copy it now — it won’t be shown again.'));

        return $this->redirect('content-ops/ai-access');
    }

    /**
     * Revokes a token.
     *
     * @return Response
     */
    public function actionRevoke(): Response
    {
        $this->requirePostRequest();

        if (ContentOps::getInstance()->getTokens()->revoke((int)$this->request->getRequiredBodyParam('id'), static::currentUser())) {
            $this->setSuccessFlash(Craft::t('content-ops', 'Token revoked.'));
        } else {
            $this->setFailFlash(Craft::t('content-ops', 'Couldn’t revoke that token.'));
        }

        return $this->redirect('content-ops/ai-access');
    }
}
