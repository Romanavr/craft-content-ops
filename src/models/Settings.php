<?php

namespace romanavr\contentops\models;

use craft\base\Model;

/**
 * Content Ops settings
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Settings extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether saves create entry revisions (tagged with the changeset ID). Slower, but a second safety net.
     */
    public bool $createRevisions = true;

    /**
     * @var int Element/site pairs per queue job batch.
     */
    public int $batchSize = 50;

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['batchSize'], 'integer', 'min' => 1, 'max' => 1000];
        $rules[] = [['createRevisions'], 'boolean'];

        return $rules;
    }
}
