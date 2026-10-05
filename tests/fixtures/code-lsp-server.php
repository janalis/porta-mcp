<?php

declare(strict_types=1);

/**
 * A language server that knows one fact — class A is declared on line 2 of
 * src/A.php and used on line 2 of src/B.php — and answers every code
 * intelligence question from it, so the mcp tools can be driven end to end.
 *
 * Positions on line 9 (0-based 8) answer an ERROR; hover on line 10 never
 * answers, which is what a timeout is for; hover on line 1 has nothing to
 * say. A symbol query of `boom` is an error, and the symbol list carries one
 * kind no reader has a name for.
 *
 * It also changes code: renaming to `boom` is an error and to `nothing` has
 * no answer; any other name renames A in A.php, B.php and C.php. Formatting
 * B.php adds a comment line, by the options it was sent; A.php is already
 * formatted.
 *
 * SIGTERM is ignored under a bounded alarm, as the other stubs do, so the
 * server answers instead of dying before its first read.
 */
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
pcntl_alarm(30);

$in = \STDIN;
$out = \STDOUT;
$root = '';

require dirname(__DIR__, levels: 4).'/tests/Support/lsp-stub.php';

// By reference: the root is only known once initialize has arrived.
$location = static function (string $file, int $line, int $character) use (&$root): array {
    return [
        'uri' => $root.'/src/'.$file,
        'range' => ['start' => ['line' => $line, 'character' => $character], 'end' => ['line' => $line, 'character' => $character + 1]],
    ];
};
$reply = static function (array $message, mixed $result) use ($out): void {
    sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result]);
};
$refuse = static function (array $message) use ($out): void {
    sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32_603, 'message' => 'Index not ready']]);
};

foreach (readFrames($in) as $message) {
    $method = $message['method'] ?? '';
    $line = $message['params']['position']['line'] ?? null;
    if ('initialize' === $method) {
        $root = (string) ($message['params']['rootUri'] ?? '');
        $reply($message, ['capabilities' => [
            'textDocumentSync' => 1,
            'definitionProvider' => true,
            'referencesProvider' => true,
            'hoverProvider' => true,
            'workspaceSymbolProvider' => true,
            'documentSymbolProvider' => true,
            'renameProvider' => true,
            'documentFormattingProvider' => true,
        ]]);
        continue;
    }
    if (8 === $line && \in_array($method, ['textDocument/definition', 'textDocument/references', 'textDocument/hover'], strict: true)) {
        $refuse($message);
        continue;
    }
    match ($method) {
        'textDocument/definition' => $reply($message, [$location('A.php', line: 1, character: 6)]),
        'textDocument/references' => $reply($message, [$location('A.php', line: 1, character: 6), $location('B.php', line: 1, character: 4)]),
        'textDocument/hover' => match ($line) {
            0 => $reply($message, result: null),
            9 => null, // never answered
            default => $reply($message, ['contents' => ['kind' => 'markdown', 'value' => "```php\nclass A\n```\nThe **one** class."]]),
        },
        'workspace/symbol' => 'boom' === ($message['params']['query'] ?? '')
            ? $refuse($message)
            : $reply($message, [
                ['name' => 'A', 'kind' => 5, 'location' => $location('A.php', line: 1, character: 6)],
                ['name' => 'odd', 'kind' => 99, 'location' => $location('A.php', line: 0, character: 0)],
            ]),
        'textDocument/documentSymbol' => $reply($message, [[
            'name' => 'A',
            'kind' => 5,
            'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 10]],
            'selectionRange' => ['start' => ['line' => 1, 'character' => 6], 'end' => ['line' => 1, 'character' => 7]],
            'children' => [['name' => 'run', 'kind' => 6, 'selectionRange' => ['start' => ['line' => 1, 'character' => 9], 'end' => ['line' => 1, 'character' => 9]]]],
        ]]),
        'textDocument/rename' => match ($message['params']['newName'] ?? '') {
            'boom' => $refuse($message),
            'nothing' => $reply($message, result: null),
            default => $reply($message, ['changes' => \array_merge(...\array_map(
                static fn (array $at): array => [$at[0]['uri'] => [['range' => $at[0]['range'], 'newText' => $message['params']['newName']]]],
                [
                    [$location('A.php', line: 1, character: 6)],
                    [$location('B.php', line: 1, character: 4)],
                    [$location('C.php', line: 1, character: 4)],
                ],
            ))]),
        },
        'textDocument/formatting' => \str_ends_with((string) ($message['params']['textDocument']['uri'] ?? ''), 'B.php')
            ? $reply($message, [[
                'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]],
                'newText' => '// formatted, tabSize '.($message['params']['options']['tabSize'] ?? '?')."\n",
            ]])
            : $reply($message, result: []),
        'exit' => exit(0),
        default => null,
    };
}
