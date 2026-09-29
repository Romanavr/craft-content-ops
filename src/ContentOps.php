<?php

namespace romanavr\contentops;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use romanavr\contentops\elements\actions\BulkEdit;
use romanavr\contentops\errors\ProFeatureException;
use romanavr\contentops\models\Settings;
use romanavr\contentops\services\Applier;
use romanavr\contentops\services\Changesets;
use romanavr\contentops\services\FindReplace;
use romanavr\contentops\services\Operators;
use romanavr\contentops\services\Previewer;
use romanavr\contentops\services\Selections;
use romanavr\contentops\services\Targets;
use romanavr\contentops\services\Tokens;
use romanavr\contentops\web\assets\bulkedit\BulkEditAsset;
use yii\base\Event;

/**
 * Content Ops plugin
 *
 * @method static ContentOps getInstance()
 * @method Settings getSettings()
 * @property-read Applier $applier
 * @property-read Changesets $changesets
 * @property-read FindReplace $findReplace
 * @property-read Operators $operators
 * @property-read Previewer $previewer
 * @property-read Selections $selections
 * @property-read Targets $targets
 * @property-read Tokens $tokens
 * @author Romanavr
 * @copyright Romanavr
 * @license https://craftcms.github.io/license/ Craft License
 */
class ContentOps extends Plugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '1.1.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    /**
     * Returns whether the Pro edition is active.
     *
     * @return bool
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO);
    }

    /**
     * Throws if a Pro feature is used in Lite. Enforced in services, so it covers the CP, console and MCP alike.
     *
     * @param string $feature Human-readable feature name, e.g. “Regular expressions”
     * @throws ProFeatureException
     */
    public function requirePro(string $feature): void
    {
        if (!$this->isPro()) {
            throw new ProFeatureException("$feature requires Content Ops Pro.");
        }
    }

    public static function config(): array
    {
        return [
            'components' => [
                'applier' => Applier::class,
                'changesets' => Changesets::class,
                'findReplace' => FindReplace::class,
                'operators' => Operators::class,
                'previewer' => Previewer::class,
                'selections' => Selections::class,
                'targets' => Targets::class,
                'tokens' => Tokens::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function() {
            $this->_registerCpAssets();
        });
    }

    /**
     * Returns the applier service.
     *
     * @return Applier
     */
    public function getApplier(): Applier
    {
        return $this->get('applier');
    }

    /**
     * Returns the changesets service.
     *
     * @return Changesets
     */
    public function getChangesets(): Changesets
    {
        return $this->get('changesets');
    }

    /**
     * Returns the find & replace service.
     *
     * @return FindReplace
     */
    public function getFindReplace(): FindReplace
    {
        return $this->get('findReplace');
    }

    /**
     * Returns the operators service.
     *
     * @return Operators
     */
    public function getOperators(): Operators
    {
        return $this->get('operators');
    }

    /**
     * Returns the previewer service.
     *
     * @return Previewer
     */
    public function getPreviewer(): Previewer
    {
        return $this->get('previewer');
    }

    /**
     * Returns the selections service.
     *
     * @return Selections
     */
    public function getSelections(): Selections
    {
        return $this->get('selections');
    }

    /**
     * Returns the targets service.
     *
     * @return Targets
     */
    public function getTargets(): Targets
    {
        return $this->get('targets');
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('content-ops', 'Content Ops');
        $item['subnav'] = [
            'history' => ['label' => Craft::t('content-ops', 'History'), 'url' => 'content-ops/history'],
            'guide' => ['label' => Craft::t('content-ops', 'Guide'), 'url' => 'content-ops/guide'],
        ];

        if (Craft::$app->getUser()->checkPermission('contentOps:mcp')) {
            $item['subnav'] = array_slice($item['subnav'], 0, -1, true)
                + ['ai-access' => ['label' => Craft::t('content-ops', 'AI Access'), 'url' => 'content-ops/ai-access']]
                + array_slice($item['subnav'], -1, 1, true);
        }

        if (Craft::$app->getUser()->checkPermission('contentOps:findReplace')) {
            $item['subnav'] = ['find-replace' => ['label' => Craft::t('content-ops', 'Find & Replace'), 'url' => 'content-ops/find-replace']] + $item['subnav'];
        }

        return $item;
    }

    /**
     * Returns the MCP tokens service.
     *
     * @return Tokens
     */
    public function getTokens(): Tokens
    {
        return $this->get('tokens');
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('content-ops/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * Loads the bulk edit script on CP pages for users who can bulk edit (it adds the “Bulk edit” button to entry indexes).
     */
    private function _registerCpAssets(): void
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsCpRequest() || $request->getAcceptsJson()) {
            return;
        }

        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function() {
            $user = Craft::$app->getUser();

            if ($user->getIsGuest() || !$user->checkPermission('contentOps:bulkEdit')) {
                return;
            }

            BulkEditAsset::registerForIndexes(Craft::$app->getView());
        });
    }

    private function attachEventHandlers(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('content-ops', 'Content Ops'),
                'permissions' => [
                    'contentOps:bulkEdit' => ['label' => Craft::t('content-ops', 'Bulk edit elements')],
                    'contentOps:findReplace' => ['label' => Craft::t('content-ops', 'Find and replace')],
                    'contentOps:undo' => ['label' => Craft::t('content-ops', 'Undo changesets')],
                    'contentOps:viewHistory' => ['label' => Craft::t('content-ops', 'View changeset history')],
                    'contentOps:mcp' => ['label' => Craft::t('content-ops', 'Use AI access (MCP tokens)')],
                ],
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['content-ops'] = 'content-ops/history/index';
            $event->rules['content-ops/history'] = 'content-ops/history/index';
            $event->rules['content-ops/history/<changesetId:\\d+>'] = 'content-ops/history/view';
            $event->rules['content-ops/guide'] = ['template' => 'content-ops/guide/_index'];
            $event->rules['content-ops/ai-access'] = 'content-ops/ai-access/index';
            $event->rules['content-ops/find-replace'] = 'content-ops/find-replace/index';
            $event->rules['content-ops/find-replace/<changesetId:\\d+>'] = 'content-ops/find-replace/results';
        });

        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->getChangesets()->purgeOld();
        });

        Event::on(Entry::class, Element::EVENT_REGISTER_ACTIONS, function(RegisterElementActionsEvent $event) {
            if (Craft::$app->getUser()->checkPermission('contentOps:bulkEdit')) {
                $event->actions[] = BulkEdit::class;
            }
        });
    }
}
