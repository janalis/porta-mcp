<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tools;

use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\ToolCaller;
use Porta\Plugin\Api\ToolEffect;
use Porta\Support\FiberWait;

/**
 * The language servers the IDE already runs, as tools: where a symbol is
 * declared, where it is used, what it is, and which symbols the project has
 * by name. An agent asks the server that understands the code instead of
 * grepping for a name that three unrelated classes share.
 *
 * Any file of the project, open or not — nothing is shown, no tab moves. The
 * wire is 1-based like the other tools; a column counts UTF-16 code units, as
 * the protocol does, which is the character count for everything outside the
 * astral planes.
 *
 * Two tools CHANGE code, as the editor's Rename and Reformat do: `format`
 * edits an open buffer, unsaved, behind `allowEdits`; `rename` also rewrites
 * the files that are not open, so it needs `allowSave` too.
 *
 * A server's request has no timeout of its own, so each call gives up after
 * the `lspTimeout` setting's seconds rather than hanging the agent with it.
 */
final readonly class CodeTools
{
    /** Changes an open buffer only — hidden with the other edits. */
    public const string FORMAT = 'format';

    /** Changes buffers AND files on disk — hidden unless both edits and saves are allowed. */
    public const string RENAME = 'rename';

    /** LSP SymbolKind, 1-based, named for a reader. */
    private const array KINDS = [
        1 => 'file',
        'module',
        'namespace',
        'package',
        'class',
        'method',
        'property',
        'field',
        'constructor',
        'enum',
        'interface',
        'function',
        'variable',
        'constant',
        'string',
        'number',
        'boolean',
        'array',
        'object',
        'key',
        'null',
        'enum member',
        'struct',
        'event',
        'operator',
        'type parameter',
    ];

    /** @param \Closure(): int $timeout seconds to wait for a server, read per call so a Settings edit applies at once */
    public function __construct(
        private PluginApi $api,
        private \Closure $timeout,
        private Opener $opener,
        private Presence $presence,
    ) {}

    public function register(): void
    {
        $at = [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'The file, project-relative or absolute'],
                'line' => ['type' => 'integer', 'minimum' => 1],
                'column' => ['type' => 'integer', 'minimum' => 1, 'description' => 'On the symbol; default 1'],
            ],
            'required' => ['path', 'line'],
        ];
        $this->api->registerTool(
            'definition',
            'Where the symbol at a position is declared, asked of the language server — any file of the project, open or not. Each location has its path, 1-based line and column, and the line\'s text.',
            $at,
            fn (array $arguments, ToolCaller $caller): array => $this->locations($arguments, $this->api->codeIntelligence()->definition(...), $caller),
            effect: ToolEffect::Reads,
        );
        $this->api->registerTool(
            'references',
            'Every place the symbol at a position is used, its declaration included, asked of the language server — the precise answer a text search for its name is not.',
            $at,
            fn (array $arguments, ToolCaller $caller): array => $this->locations($arguments, $this->api->codeIntelligence()->references(...), $caller),
            effect: ToolEffect::Reads,
        );
        $this->api->registerTool(
            'hover',
            'What the language server says about the symbol at a position: its signature and documentation, as text.',
            $at,
            $this->hover(...),
            effect: ToolEffect::Reads,
        );
        $this->api->registerTool(
            'symbols',
            'The project\'s classes, functions, methods and other symbols whose name matches a query, from the running language server.',
            ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            $this->symbols(...),
            effect: ToolEffect::Reads,
        );
        $this->api->registerTool(
            'outline',
            'What one file declares — classes, their methods and properties, functions — outermost first, each with its kind, 1-based line and column, and how deep it is nested; from the language server, any file of the project, open or not.',
            ['type' => 'object', 'properties' => ['path' => $at['properties']['path']], 'required' => ['path']],
            $this->outline(...),
            effect: ToolEffect::Reads,
        );
        $this->api->registerTool(
            self::RENAME,
            'Rename the symbol at a position everywhere the language server finds it, as the editor\'s Rename does: open files are edited and left unsaved, files that are not open are REWRITTEN on disk, and the user takes it all back with Undo Refactoring.',
            [
                'type' => 'object',
                'properties' => [...$at['properties'], 'newName' => ['type' => 'string']],
                'required' => ['path', 'line', 'newName'],
            ],
            $this->rename(...),
            effect: ToolEffect::Writes,
        );
        $this->api->registerTool(
            self::FORMAT,
            'Format a file as its language server does, by the file\'s own indentation — opening it behind the user\'s tab if needed — as ONE undoable edit. Not saved to disk. Without a path, the active file.',
            ['type' => 'object', 'properties' => ['path' => $at['properties']['path']]],
            $this->format(...),
            effect: ToolEffect::Edits,
        );
    }

    /**
     * @param array<string, mixed>                                                                                              $arguments
     * @param \Closure(string, int, int, \Closure(list<array{path: string, line: int, column: int, text: string}>, ?string): void): void $ask
     *
     * @return list<array{path: string, line: int, column: int, text: string}>
     */
    private function locations(array $arguments, \Closure $ask, ToolCaller $caller): array
    {
        [$path, $line, $column] = $this->position($arguments);
        $this->presence->reading($caller, $path, $line, $column);
        /** @var array{0: list<array{path: string, line: int, column: int, text: string}>, 1: ?string} $answer */
        $answer = $this->wait(static fn (\Closure $done) => $ask($path, $line, $column, static fn (array $rows, ?string $error) => $done([$rows, $error])));
        [$rows, $error] = $answer;
        if (null !== $error) {
            throw new \RuntimeException($error);
        }

        return \array_map(static fn (array $row): array => ['line' => $row['line'] + 1, 'column' => $row['column'] + 1] + $row, $rows);
    }

    /** @param array<string, mixed> $arguments */
    private function hover(array $arguments, ToolCaller $caller): string
    {
        [$path, $line, $column] = $this->position($arguments);
        $this->presence->reading($caller, $path, $line, $column);
        /** @var array{0: ?string, 1: ?string} $answer */
        $answer = $this->wait(fn (\Closure $done) => $this->api->codeIntelligence()->hover($path, $line, $column, static fn (?string $text, ?string $error) => $done([$text, $error])));
        [$text, $error] = $answer;
        if (null !== $error) {
            throw new \RuntimeException($error);
        }

        return $text ?? 'The language server has nothing to say about this position';
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<array{name: string, kind: string, path: string, line: int}>
     */
    private function symbols(array $arguments): array
    {
        $query = new Arguments($arguments, $this->api->projectRoot())->text('query');
        /** @var array{0: list<array{path: string, line: int, name: string, kind: int}>, 1: ?string} $answer */
        $answer = $this->wait(fn (\Closure $done) => $this->api->codeIntelligence()->symbols($query, static fn (array $symbols, ?string $error) => $done([$symbols, $error])));
        [$symbols, $error] = $answer;
        if (null !== $error) {
            throw new \RuntimeException($error);
        }

        return \array_map(static fn (array $symbol): array => [
            'name' => $symbol['name'],
            'kind' => (string) (self::KINDS[$symbol['kind']] ?? 'symbol'),
            'path' => $symbol['path'],
            'line' => $symbol['line'] + 1,
        ], $symbols);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<array{name: string, kind: string, line: int, column: int, depth: int}>
     */
    private function outline(array $arguments, ToolCaller $caller): array
    {
        $path = new Arguments($arguments, $this->api->projectRoot())->requiredPath();
        $this->presence->reading($caller, $path);
        /** @var array{0: list<array{name: string, kind: int, line: int, column: int, depth: int}>, 1: ?string} $answer */
        $answer = $this->wait(fn (\Closure $done) => $this->api->codeIntelligence()->outline($path, static fn (array $symbols, ?string $error) => $done([$symbols, $error])));
        [$symbols, $error] = $answer;
        if (null !== $error) {
            throw new \RuntimeException($error);
        }

        return \array_map(static fn (array $symbol): array => [
            'name' => $symbol['name'],
            'kind' => (string) (self::KINDS[$symbol['kind']] ?? 'symbol'),
            'line' => $symbol['line'] + 1,
            'column' => $symbol['column'] + 1,
            'depth' => $symbol['depth'],
        ], $symbols);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{files: int, skipped: list<string>, note: string}
     */
    private function rename(array $arguments, ToolCaller $caller): array
    {
        [$path, $line, $column] = $this->position($arguments);
        $newName = new Arguments($arguments, $this->api->projectRoot())->text('newName');
        if ('' === \trim($newName)) {
            throw new \InvalidArgumentException('newName is required');
        }
        $this->presence->editing($caller, $path, $line, $column);
        /** @var array{0: array{files: int, skipped: list<string>}|null, 1: ?string} $answer */
        $answer = $this->wait(fn (\Closure $done) => $this->api->codeIntelligence()->rename($path, $line, $column, $newName, static fn (?array $outcome, ?string $error) => $done([$outcome, $error])));
        [$outcome, $error] = $answer;
        if (null === $outcome) {
            throw new \RuntimeException($error ?? 'The rename was not applied');
        }

        return $outcome + ['note' => 'Open files are edited and unsaved; the others were rewritten on disk. Undo Refactoring takes it all back.'];
    }

    /** @param array<string, mixed> $arguments */
    private function format(array $arguments, ToolCaller $caller): string
    {
        $path = $this->opener->open(new Arguments($arguments, $this->api->projectRoot())->path());
        $this->presence->before($caller, $path, (string) $this->api->openBuffers()->text($path));
        $this->presence->editing($caller, $path);
        /** @var array{0: int, 1: ?string} $answer */
        $answer = $this->wait(fn (\Closure $done) => $this->api->codeIntelligence()->format($path, static fn (int $applied, ?string $error) => $done([$applied, $error])));
        [$applied, $error] = $answer;
        if (null !== $error) {
            throw new \RuntimeException($error);
        }

        return $applied > 0 ? \sprintf('Formatted %s — unsaved; the user can undo it in one step', $path) : \sprintf('%s is already formatted', $path);
    }

    /**
     * The 0-based position the API takes, from the 1-based one the wire carries.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function position(array $arguments): array
    {
        $read = new Arguments($arguments, $this->api->projectRoot());

        return [$read->requiredPath(), $read->requiredPosition('line') - 1, ($read->position('column') ?? 1) - 1];
    }

    /**
     * @param \Closure(\Closure(mixed): void): void $start
     */
    private function wait(\Closure $start): mixed
    {
        $seconds = ($this->timeout)();

        return FiberWait::within($seconds, $start, null) ?? throw new \RuntimeException(\sprintf('The language server did not answer within %ds', $seconds));
    }
}
