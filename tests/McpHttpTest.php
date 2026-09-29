<?php

use romanavr\contentops\ContentOps;
use romanavr\contentops\mcp\McpContext;

function mcpToken(string $mode, ?array $permissions = null): string
{
    $user = userWithPermissions($permissions ?? ['contentops:mcp', 'contentops:bulkedit']);
    [, $plain] = ContentOps::getInstance()->getTokens()->create('test', $user, $mode);

    return $plain;
}

function mcpPost($test, string $token, array $body, ?string $session = null)
{
    $request = $test->http('post', '/actions/content-ops/mcp')
        ->addHeader('Authorization', "Bearer $token")
        ->addHeader('Content-Type', 'application/json')
        ->addHeader('Accept', 'application/json, text/event-stream')
        ->addHeader('Host', 'localhost')
        ->addHeader('MCP-Protocol-Version', '2025-06-18')
        ->setBody($body);

    if ($session) {
        $request->addHeader('Mcp-Session-Id', $session);
    }

    return $request->send();
}

it('creates, authenticates and revokes tokens (hashed at rest)', function() {
    $tokens = ContentOps::getInstance()->getTokens();
    $user = userWithPermissions(['contentops:mcp']);

    [$record, $plain] = $tokens->create('Laptop', $user, McpContext::MODE_READONLY);

    expect($plain)->toStartWith('co_')
        ->and($record->tokenHash)->toBe(hash('sha256', $plain))
        ->and($record->tokenHash)->not->toContain($plain)
        ->and($tokens->authenticate($plain)?->id)->toBe($record->id)
        ->and($tokens->authenticate('co_wrong'))->toBeNull();

    expect($tokens->revoke($record->id, $user))->toBeTrue()
        ->and($tokens->authenticate($plain))->toBeNull();
});

it('rejects requests without a valid token', function() {
    $this->withExceptionHandling()
        ->http('post', '/actions/content-ops/mcp')
        ->addHeader('Authorization', 'Bearer co_nope')
        ->setBody(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->send()
        ->assertStatus(401);
});

it('rejects tokens of users without the MCP permission', function() {
    $token = mcpToken(McpContext::MODE_READONLY, ['contentops:bulkedit']);

    $this->withExceptionHandling()
        ->http('post', '/actions/content-ops/mcp')
        ->addHeader('Authorization', "Bearer $token")
        ->setBody(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->send()
        ->assertStatus(401);
});

it('serves MCP over HTTP with the tools of the token mode', function(string $mode, bool $canPropose) {
    $token = mcpToken($mode);

    $init = mcpPost($this, $token, [
        'jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'pest', 'version' => '0']],
    ]);
    $init->assertOk();
    $session = $init->getHeaders()->get('Mcp-Session-Id');

    mcpPost($this, $token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $session);
    $list = mcpPost($this, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $session);
    $body = $list->content;
    $names = array_column(json_decode(substr($body, strpos($body, '{')), true)['result']['tools'], 'name');

    expect($names)->toContain('search_content')
        ->and(in_array('propose_bulk_edit', $names, true))->toBe($canPropose)
        ->and($names)->not->toContain('apply_changeset');
})->with([
    'readonly' => [McpContext::MODE_READONLY, false],
    'propose' => [McpContext::MODE_PROPOSE, true],
]);
