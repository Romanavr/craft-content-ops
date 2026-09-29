<?php

/*
| Tests run from the sandbox Craft install (../sandbox) against its `test` database.
| Run them with `ddev pest` from the sandbox directory.
*/

uses(
    markhuot\craftpest\test\TestCase::class,
    markhuot\craftpest\test\RefreshesDatabase::class,
)
    // Tests run against Pro by default; EditionsTest switches to Lite in its own beforeEach.
    ->beforeEach(fn() => useEdition(\romanavr\contentops\ContentOps::EDITION_PRO))
    ->in('./');

/**
 * Sets the plugin edition for the current test, in memory only (switchEdition() would persist it past the test).
 */
function useEdition(string $edition): void
{
    \romanavr\contentops\ContentOps::getInstance()->edition = $edition;
}
