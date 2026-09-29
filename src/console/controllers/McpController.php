<?php

namespace romanavr\contentops\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use Mcp\Server\Transport\StdioTransport;
use romanavr\contentops\mcp\McpContext;
use romanavr\contentops\mcp\ServerFactory;
use yii\console\ExitCode;

/**
 * Runs the Content Ops MCP server.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class McpController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string What the AI may do: `readonly`, `propose` (a person applies changes in the CP) or `full`.
     */
    public string $mode = McpContext::MODE_PROPOSE;

    /**
     * @var string|null Username or email to act as (permissions + attribution). Default: none (console access).
     */
    public ?string $as = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['mode', 'as']);
    }

    /**
     * Serves MCP over stdio (JSON-RPC on stdin/stdout). Point an MCP client at `php craft content-ops/mcp`.
     * Nothing else may write to stdout while this runs.
     *
     * @return int
     */
    public function actionIndex(): int
    {
        if (!in_array($this->mode, [McpContext::MODE_READONLY, McpContext::MODE_PROPOSE, McpContext::MODE_FULL], true)) {
            $this->stderr("--mode must be readonly, propose or full.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $user = null;

        if ($this->as !== null) {
            $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($this->as);

            if ($user === null) {
                $this->stderr("No user found for “{$this->as}”.\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
        }

        return ServerFactory::create(new McpContext($this->mode, $user, 'stdio'))->run(new StdioTransport());
    }
}
