<?php

namespace romanavr\contentops\models;

use craft\base\Model;

/**
 * Where Find & Replace searches.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class FindReplaceScope extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string[] Section handles; empty means all sections.
     */
    public array $sections = [];

    /**
     * @var string[] Entry type handles; empty means all types.
     */
    public array $types = [];

    /**
     * @var int[] Site IDs; empty means all sites.
     */
    public array $siteIds = [];

    /**
     * @var string[] Target handles (field handles, `title`, or nested paths); empty means every text field.
     */
    public array $targets = [];

    /**
     * @var bool Also search text fields inside Matrix nested entries (Pro).
     */
    public bool $includeNested = false;
}
