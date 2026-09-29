<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tools;

use Porta\Plugin\Api\PluginApi;
use Porta\Search\ProjectSearch;
use Porta\Search\SearchOptions;
use Porta\Support\FiberWait;

/**
 * The IDE's Find in Files as a tool: the same engine, the same excludes
 * (`.git`, `vendor`, `node_modules`…), the same cap.
 *
 * Each call searches on a PRIVATE engine. The IDE's own is shared, and every
 * search cancels the one before it — an agent's query would have killed the
 * user's Find in Files halfway, and the other way round.
 *
 * It reads the files ON DISK, like Find in Files: an unsaved edit is not
 * searched. The results say so, since `read_buffer` is the way to see one.
 */
final readonly class SearchTools
{
    /**
     * The engine runs grep with no deadline of its own, and a recursive grep
     * can block for good on what is not a file — a FIFO in the tree. A search
     * that has not answered by then is not going to.
     */
    private const float TIMEOUT = 60.0;

    public function __construct(
        private PluginApi $api,
    ) {}

    public function register(): void
    {
        $this->api->registerTool(
            'search',
            'Find text in the project\'s files, like the IDE\'s Find in Files: each match with its file, 1-based line and the line\'s text. Files on disk — unsaved edits are not searched. At most '
            .ProjectSearch::MAX_RESULTS
            .' matches.',
            [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'regex' => ['type' => 'boolean', 'description' => 'The query is a regular expression'],
                    'caseSensitive' => ['type' => 'boolean'],
                    'wholeWord' => ['type' => 'boolean'],
                    'include' => ['type' => 'string', 'description' => 'File names to search, comma-separated globs: *.php, src/*.{js,ts}'],
                    'paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only under these files or directories'],
                ],
                'required' => ['query'],
            ],
            $this->search(...),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{matches: list<array{path: string, line: int, text: string}>, truncated: bool, note: string}
     */
    private function search(array $arguments): array
    {
        $read = new Arguments($arguments, $this->api->projectRoot());
        $query = $read->text('query');
        if ('' === \trim($query)) {
            throw new \InvalidArgumentException('query is required');
        }
        $options = new SearchOptions(regex: $read->flag('regex'), caseSensitive: $read->flag('caseSensitive'), wholeWord: $read->flag('wholeWord'));
        $root = $read->root();
        $scope = '.';
        $paths = $read->paths('paths');
        if (null !== $paths) {
            $scope = \array_map(static fn (string $path): string => $path === $root ? '.' : \substr($path, \strlen($root) + 1), $paths);
        }
        $mask = \is_string($arguments['include'] ?? null) ? $arguments['include'] : '';
        $engine = new ProjectSearch($root);

        /** @var list<array{path: string, line: int, text: string}>|null $rows */
        $rows = FiberWait::within(self::TIMEOUT, static fn (\Closure $done) => $engine->search($query, $done, $options, $scope, $mask), null);
        if (null === $rows) {
            // A grep that never finishes needs a filesystem trap no test can
            // build the same way on every platform.
            // @codeCoverageIgnoreStart
            $engine->cancel();

            throw new \RuntimeException(\sprintf('The search did not finish within %ds', (int) self::TIMEOUT));

            // @codeCoverageIgnoreEnd
        }
        $matches = \array_map(static fn (array $row): array => ['path' => $root.'/'.$row['path'], 'line' => $row['line'] + 1, 'text' => $row['text']], $rows);

        return [
            'matches' => $matches,
            'truncated' => \count($matches) >= ProjectSearch::MAX_RESULTS,
            'note' => 'Files on disk: a file with unsaved edits in the editor may differ — read_buffer shows it as it is.',
        ];
    }
}
