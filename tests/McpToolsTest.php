<?php

use markhuot\craftpest\factories\Entry as EntryFactory;
use markhuot\craftpest\factories\Section as SectionFactory;
use Mcp\Exception\ToolCallException;
use romanavr\contentops\mcp\ContentTools;
use romanavr\contentops\mcp\ProjectTools;

it('describes the project schema', function() {
    $section = SectionFactory::factory()->create();

    $schema = (new ProjectTools())->getProjectSchema();

    expect($schema['sites'])->not->toBeEmpty()
        ->and(array_column($schema['sections'], 'handle'))->toContain($section->handle);
});

it('finds and reads entries', function() {
    $section = SectionFactory::factory()->create();
    $entry = EntryFactory::factory()->section($section->handle)->title('Acme Corp launch')->create();
    $tools = new ContentTools();

    $found = $tools->searchContent(section: $section->handle);
    $read = $tools->readEntry($entry->id);

    expect($found['total'])->toBe(1)
        ->and($found['results'][0]['id'])->toBe($entry->id)
        ->and($read['title'])->toBe('Acme Corp launch')
        ->and($read['section'])->toBe($section->handle)
        ->and($read)->toHaveKeys(['fields', 'availableSites', 'draftCount']);
});

it('reports a missing entry as a tool error', function() {
    (new ContentTools())->readEntry(PHP_INT_MAX);
})->throws(ToolCallException::class);

it('rejects unknown sites', function() {
    (new ContentTools())->searchContent(site: 'nope');
})->throws(ToolCallException::class, 'Unknown site');
