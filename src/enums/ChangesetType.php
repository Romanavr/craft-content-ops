<?php

namespace romanavr\contentops\enums;

/**
 * Which UI created a changeset.
 *
 * @author Romanavr
 * @since 1.0.0
 */
enum ChangesetType: string
{
    case BulkEdit = 'bulkEdit';
    case FindReplace = 'findReplace';
}
