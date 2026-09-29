<?php

/*
| Tests run from the sandbox Craft install (../sandbox) against its `test` database.
| Run them with `ddev pest` from the sandbox directory.
*/

uses(
    markhuot\craftpest\test\TestCase::class,
    markhuot\craftpest\test\RefreshesDatabase::class,
)->in('./');
