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

    /**
     * @var int|null Days to keep finished changesets (and the ability to undo them). `null` keeps them forever.
     * Previews that were never applied are deleted after a day.
     */
    public ?int $historyRetentionDays = 90;

    /**
     * @var int|null Max items (element/site pairs, or fields for Find & Replace) in a changeset proposed through MCP.
     */
    public ?int $mcpMaxElements = 1000;

    // Public Methods
    // =========================================================================

    /**
     * Casts form input (strings, blanks) to the property types.
     *
     * @inheritdoc
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        if (array_key_exists('historyRetentionDays', $values)) {
            $values['historyRetentionDays'] = $values['historyRetentionDays'] === '' || $values['historyRetentionDays'] === null ? null : (int)$values['historyRetentionDays'];
        }

        if (array_key_exists('mcpMaxElements', $values)) {
            $values['mcpMaxElements'] = $values['mcpMaxElements'] === '' || $values['mcpMaxElements'] === null ? null : (int)$values['mcpMaxElements'];
        }

        if (isset($values['batchSize'])) {
            $values['batchSize'] = (int)$values['batchSize'];
        }

        if (isset($values['createRevisions'])) {
            $values['createRevisions'] = (bool)$values['createRevisions'];
        }

        parent::setAttributes($values, $safeOnly);
    }

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
        $rules[] = [['historyRetentionDays'], 'integer', 'min' => 1];
        $rules[] = [['mcpMaxElements'], 'integer', 'min' => 1];

        return $rules;
    }
}
