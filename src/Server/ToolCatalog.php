<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Server;

use Porta\Plugin\Api\ToolEffect;

/**
 * The tools as a client sees them: every plugin's registrations, named for
 * the wire, minus the ones switched off.
 *
 * A tool of THIS plugin keeps its bare id (`open_file`); any other plugin's is
 * prefixed with the plugin's name (`git_status`), so two plugins may both
 * offer a `status` and the caller can still tell which it is calling. A later
 * registration whose wire name is already taken is left out rather than
 * shadowing the first — load order is the tie-break everywhere else too.
 *
 * A tool's {@see ToolEffect} goes out as MCP's annotations — the hints a
 * client decides by whether to run a call without asking the user.
 *
 * Read afresh on every call, never cached: the set moves under a live
 * enable or disable, and the edit switch under a Settings change.
 */
final readonly class ToolCatalog
{
    /**
     * @param \Closure(): list<array{plugin: string, id: string, description: string, inputSchema: array<string, mixed>, handler: \Closure(array<string, mixed>): (string|array<array-key, mixed>), effect: ?ToolEffect}> $tools
     * @param \Closure(): list<string>                                                                                                                                                                $hidden wire names not to offer right now
     */
    public function __construct(
        private \Closure $tools,
        private string $self,
        private \Closure $hidden,
    ) {}

    /** @return list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations?: array<string, bool>}> what `tools/list` answers */
    public function describe(): array
    {
        $described = [];
        foreach ($this->offered() as $name => $tool) {
            $schema = $tool['inputSchema'];
            // PHP's empty array encodes as `[]`, and a schema's properties
            // must be an OBJECT — a client that validates refuses the tool.
            if ([] === ($schema['properties'] ?? null)) {
                $schema['properties'] = new \stdClass();
            }
            $entry = ['name' => $name, 'description' => $tool['description'], 'inputSchema' => $schema];
            if (null !== $tool['effect']) {
                $entry['annotations'] = self::hints($tool['effect']);
            }
            $described[] = $entry;
        }

        return $described;
    }

    /**
     * MCP's four hints for one effect. Destructive is about the machine: an
     * edit the user undoes is not, a comment on a forge is only added to.
     *
     * @return array{readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, openWorldHint: bool}
     */
    private static function hints(ToolEffect $effect): array
    {
        return [
            'readOnlyHint' => ToolEffect::Reads === $effect,
            'destructiveHint' => ToolEffect::Writes === $effect,
            'idempotentHint' => ToolEffect::Reads === $effect,
            'openWorldHint' => ToolEffect::Publishes === $effect,
        ];
    }

    /** @return (\Closure(array<string, mixed>): (string|array<array-key, mixed>))|null the handler behind $name, or null when nothing by that name is offered */
    public function find(string $name): ?\Closure
    {
        return ($this->offered()[$name] ?? null)['handler'] ?? null;
    }

    /** @return array<string, array{description: string, inputSchema: array<string, mixed>, handler: \Closure(array<string, mixed>): (string|array<array-key, mixed>), effect: ?ToolEffect}> */
    private function offered(): array
    {
        $hidden = ($this->hidden)();
        $offered = [];
        foreach (($this->tools)() as $tool) {
            $name = $this->self === $tool['plugin'] ? $tool['id'] : $tool['plugin'].'_'.$tool['id'];
            if (\array_key_exists($name, $offered) || \in_array($name, $hidden, strict: true)) {
                continue;
            }
            $offered[$name] = ['description' => $tool['description'], 'inputSchema' => $tool['inputSchema'], 'handler' => $tool['handler'], 'effect' => $tool['effect']];
        }

        return $offered;
    }
}
