<?php

namespace romanavr\contentops\enums;

/**
 * Status of a single element/site/target change within a changeset.
 *
 * @author Romanavr
 * @since 1.0.0
 */
enum ChangeStatus: string
{
    /** Previewed, not yet applied. */
    case Pending = 'pending';
    case Applied = 'applied';
    /** Not applied, e.g. the user can't save the element or it no longer exists. */
    case Skipped = 'skipped';
    /** The stored value changed between preview and apply, so it wasn't applied. */
    case Conflict = 'conflict';
    case Failed = 'failed';
    case Undone = 'undone';
    /** Applied, but undo found the value changed since; undo again with `force` to overwrite. */
    case UndoConflict = 'undoConflict';
}
