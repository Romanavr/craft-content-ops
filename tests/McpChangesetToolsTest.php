<?php

use craft\elements\Entry;
use Mcp\Exception\ToolCallException;
use romanavr\contentops\ContentOps;
use romanavr\contentops\mcp\ChangesetTools;
use romanavr\contentops\mcp\McpContext;

function tools(string $mode = McpContext::MODE_PROPOSE, ?\craft\elements\User $user = null): ChangesetTools
{
    return new ChangesetTools(new McpContext($mode, $user, 'test-client'));
}

it('lists edit options for a section', function() {
    [$section, $field] = seedSection(['a']);

    $options = tools()->getEditOptions(section: $section);
    $targets = collect($options['targets'])->keyBy('target');

    expect($options['entries'])->toBe(1)
        ->and($targets)->toHaveKeys(['title', $field])
        ->and(collect($targets[$field]['operations'])->pluck('operation'))->toContain('append');
});

it('proposes a bulk edit without saving and attributes it to the AI client', function() {
    [$section, $field, $entries] = seedSection(['one', 'two']);

    $proposal = tools()->proposeBulkEdit(
        changes: [['target' => $field, 'operation' => 'append', 'options' => ['value' => '!']]],
        section: $section,
    );

    expect($proposal['status'])->toBe('previewed')
        ->and($proposal['counts']['pending'])->toBe(2)
        ->and($proposal['source'])->toBe('ai:test-client')
        ->and($proposal['sampleChanges'][0]['after'])->toBe('one!')
        ->and($proposal['next'])->toContain('review and apply it in the Craft control panel')
        ->and(Entry::find()->id($entries[0]->id)->one()->getFieldValue($field))->toBe('one');
});

it('does not let a propose-mode session apply', function() {
    [$section, $field] = seedSection(['one']);
    $proposal = tools()->proposeBulkEdit(changes: [['target' => $field, 'operation' => 'append', 'options' => ['value' => '!']]], section: $section);

    tools()->applyChangeset($proposal['id']);
})->throws(ToolCallException::class, 'can only propose');

it('does not let a read-only session propose', function() {
    [$section, $field] = seedSection(['one']);

    tools(McpContext::MODE_READONLY)->proposeBulkEdit(changes: [['target' => $field, 'operation' => 'append', 'options' => ['value' => '!']]], section: $section);
})->throws(ToolCallException::class, 'read-only');

it('applies and undoes in full mode', function() {
    [$section, $field, $entries] = seedSection(['one']);
    $tools = tools(McpContext::MODE_FULL);

    $proposal = $tools->proposeBulkEdit(changes: [['target' => $field, 'operation' => 'set', 'options' => ['value' => 'AI value']]], section: $section);
    $applied = $tools->applyChangeset($proposal['id']);

    expect($applied['status'])->toBe('applied')
        ->and(Entry::find()->id($entries[0]->id)->one()->getFieldValue($field))->toBe('AI value');

    $undone = $tools->undoChangeset($proposal['id']);

    expect($undone['status'])->toBe('undone')
        ->and(Entry::find()->id($entries[0]->id)->one()->getFieldValue($field))->toBe('one');
});

it('rejects targets that the entries do not have', function() {
    [$section] = seedSection(['one']);

    tools()->proposeBulkEdit(changes: [['target' => 'nope', 'operation' => 'set', 'options' => ['value' => 'x']]], section: $section);
})->throws(ToolCallException::class, 'get_edit_options');

it('enforces the element limit for AI changesets', function() {
    [$section, $field] = seedSection(['a', 'b', 'c']);
    ContentOps::getInstance()->getSettings()->mcpMaxElements = 2;

    try {
        tools()->proposeBulkEdit(changes: [['target' => $field, 'operation' => 'append', 'options' => ['value' => '!']]], section: $section);
    } finally {
        ContentOps::getInstance()->getSettings()->mcpMaxElements = 1000;
    }
})->throws(ToolCallException::class, 'limited to 2');

it('proposes a find and replace', function() {
    $s = seedFindReplace();

    $proposal = tools()->proposeFindReplace(find: 'Acme', replace: 'Globex', sections: [$s['section']->handle]);

    expect($proposal['type'])->toBe('findReplace')
        ->and($proposal['counts']['matches'])->toBeGreaterThan(0)
        ->and($proposal['reviewUrl'])->toContain('content-ops/find-replace/');
});

it('counts AI proposals awaiting review and lists them in the history', function() {
    [$section, $field] = seedSection(['one']);
    $service = ContentOps::getInstance()->getChangesets();
    $before = $service->countAwaitingReview();

    $proposal = tools()->proposeBulkEdit(changes: [['target' => $field, 'operation' => 'append', 'options' => ['value' => '!']]], section: $section);

    expect($service->countAwaitingReview())->toBe($before + 1);

    $this->actingAsAdmin()
        ->get('/admin/content-ops/history?awaiting=1')
        ->assertOk()
        ->assertSee("#{$proposal['id']}")
        ->assertSee('AI · test-client');

    $service->applyNow($proposal['id']);

    expect($service->countAwaitingReview())->toBe($before);
});

it('shares the site team notes with AI sessions', function() {
    ContentOps::getInstance()->getSettings()->aiContext = 'Never edit the Legal section.';

    try {
        $instructions = \romanavr\contentops\mcp\ServerFactory::instructions(new McpContext());
    } finally {
        ContentOps::getInstance()->getSettings()->aiContext = '';
    }

    expect($instructions)->toContain('Never edit the Legal section.');
});
