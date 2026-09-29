<?php

namespace romanavr\contentops;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use romanavr\contentops\models\Settings;
use romanavr\contentops\services\Applier;
use romanavr\contentops\services\Changesets;
use romanavr\contentops\services\Operators;
use romanavr\contentops\services\Previewer;
use romanavr\contentops\services\Selections;
use romanavr\contentops\services\Targets;

/**
 * Content Ops plugin
 *
 * @method static ContentOps getInstance()
 * @method Settings getSettings()
 * @property-read Applier $applier
 * @property-read Changesets $changesets
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

    public static function config(): array
    {
        return [
            'components' => [
                'applier' => Applier::class,
                'changesets' => Changesets::class,
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
        // Register event handlers here ...
        // (see https://craftcms.com/docs/5.x/extend/events.html to get started)
    }
}
