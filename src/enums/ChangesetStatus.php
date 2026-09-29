<?php

namespace romanavr\contentops\enums;

/**
 * Lifecycle of a changeset: previewed → queued → running → applied|failed → undoing → undone|partiallyUndone.
 *
 * @author Romanavr
 * @since 1.0.0
 */
enum ChangesetStatus: string
{
    case Previewed = 'previewed';
    case Queued = 'queued';
    case Running = 'running';
    case Applied = 'applied';
    case Failed = 'failed';
    case Undoing = 'undoing';
    case Undone = 'undone';
    case PartiallyUndone = 'partiallyUndone';
}
