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

/**
 * Creates a user with the given permissions (plus CP access).
 *
 * On Postgres, craft-pest's per-test rollback can leave permission rows behind for a reused user ID,
 * so any leftovers are cleared before granting.
 */
function userWithPermissions(array $permissions): \craft\elements\User
{
    $user = \markhuot\craftpest\factories\User::factory()->create();
    Craft::$app->getDb()->createCommand()->delete(\craft\db\Table::USERPERMISSIONS_USERS, ['userId' => $user->id])->execute();
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, ['accesscp', ...$permissions]);

    return $user;
}
