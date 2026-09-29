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
use romanavr\contentops\elements\actions\BulkEdit;
use romanavr\contentops\models\Settings;
use romanavr\contentops\services\Applier;
use romanavr\contentops\services\Changesets;
use romanavr\contentops\services\FindReplace;
use romanavr\contentops\services\Operators;
use romanavr\contentops\services\Previewer;
use romanavr\contentops\services\Selections;
use romanavr\contentops\services\Targets;
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
 * @author Romanavr
 * @copyright Romanavr
 * @license https://craftcms.github.io/license/ Craft License
 */
class ContentOps extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

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
            // ...
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
        ];

        return $item;
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
                ],
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['content-ops'] = 'content-ops/history/index';
            $event->rules['content-ops/history'] = 'content-ops/history/index';
            $event->rules['content-ops/history/<changesetId:\\d+>'] = 'content-ops/history/view';
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
