<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tests\Server;

use PHPUnit\Framework\TestCase;
use Porta\McpPlugin\Server\ToolCatalog;
use Porta\Plugin\Api\ToolEffect;

final class ToolCatalogTest extends TestCase
{
    /**
     * @return array{plugin: string, id: string, description: string, inputSchema: array<string, mixed>, handler: \Closure(array<string, mixed>): string, effect: ?ToolEffect}
     */
    private static function tool(string $plugin, string $id, string $answer = ''): array
    {
        return [
            'plugin' => $plugin,
            'id' => $id,
            'description' => $plugin.' '.$id,
            'inputSchema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
            'handler' => static fn (array $arguments): string => $answer,
            'effect' => null,
        ];
    }

    /**
     * @param list<array{plugin: string, id: string, description: string, inputSchema: array<string, mixed>, handler: \Closure(array<string, mixed>): string, effect: ?ToolEffect}> $tools
     * @param list<string>                                                                                                                                  $hidden
     */
    private static function catalog(array $tools, array $hidden = []): ToolCatalog
    {
        return new ToolCatalog(static fn (): array => $tools, 'mcp', static fn (): array => $hidden);
    }

    public function testOwnToolsKeepTheirIdAndOtherPluginsArePrefixed(): void
    {
        $catalog = self::catalog([self::tool('mcp', 'context'), self::tool('git', 'status')]);

        $this->assertSame(
            [
                ['name' => 'context', 'description' => 'mcp context', 'inputSchema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]]],
                ['name' => 'git_status', 'description' => 'git status', 'inputSchema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]]],
            ],
            $catalog->describe(),
        );
    }

    public function testAnEffectIsSentAsTheHintsAClientDecidesBy(): void
    {
        $tools = [];
        foreach (ToolEffect::cases() as $effect) {
            $tool = self::tool('mcp', \strtolower($effect->name));
            $tool['effect'] = $effect;
            $tools[] = $tool;
        }
        $hints = \array_column(self::catalog($tools)->describe(), 'annotations', 'name');

        $this->assertSame(
            [
                'reads' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
                'edits' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
                'writes' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
                'publishes' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true],
            ],
            $hints,
        );
        $this->assertArrayNotHasKey('annotations', self::catalog([self::tool('mcp', 'silent')])->describe()[0], 'no effect, no hints');
    }

    public function testEmptyPropertiesAreSentAsAJsonObject(): void
    {
        $tool = self::tool('mcp', 'context');
        $tool['inputSchema'] = ['type' => 'object', 'properties' => []];

        $this->assertSame(
            '{"type":"object","properties":{}}',
            \json_encode(self::catalog([$tool])->describe()[0]['inputSchema']),
        );
    }

    public function testTheFirstRegistrationOfAWireNameWins(): void
    {
        $catalog = self::catalog([self::tool('git', 'status', 'first'), self::tool('mcp', 'git_status', 'second')]);

        $this->assertCount(1, $catalog->describe());
        $handler = $catalog->find('git_status');
        $this->assertNotNull($handler);
        $this->assertSame('first', $handler([]));
    }

    public function testAHiddenToolIsNeitherListedNorCallable(): void
    {
        $catalog = self::catalog([self::tool('mcp', 'context'), self::tool('mcp', 'insert_text')], ['insert_text']);

        $this->assertSame(['context'], \array_column($catalog->describe(), 'name'));
        $this->assertNull($catalog->find('insert_text'), 'hiding is the guard, not just the listing');
        $this->assertNotNull($catalog->find('context'));
    }

    public function testAnUnknownNameFindsNothing(): void
    {
        $this->assertNull(self::catalog([])->find('nope'));
    }

    public function testTheSetIsReadAfreshOnEveryCall(): void
    {
        $tools = [self::tool('mcp', 'context')];
        $catalog = new ToolCatalog(
            static function () use (&$tools): array {
                return $tools;
            },
            'mcp',
            static fn (): array => [],
        );
        $this->assertCount(1, $catalog->describe());

        $tools[] = self::tool('git', 'status');

        $this->assertCount(2, $catalog->describe());
    }
}
