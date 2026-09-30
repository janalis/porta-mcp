<?php

declare(strict_types=1);

use Porta\Event\ToolsChanged;
use Porta\Ide;
use Porta\McpPlugin\Server\Session;
use Porta\McpPlugin\Server\SocketPath;
use Porta\McpPlugin\Server\SocketServer;
use Porta\McpPlugin\Server\ToolCatalog;
use Porta\McpPlugin\Tools\CodeTools;
use Porta\McpPlugin\Tools\CoreTools;
use Porta\McpPlugin\Tools\Opener;
use Porta\McpPlugin\Tools\Presence;
use Porta\McpPlugin\Tools\RunTools;
use Porta\McpPlugin\Tools\SearchTools;
use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\DisposablePlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\DiagnosticLog;

/**
 * A Model Context Protocol server inside the IDE: an agent connected with
 * `porta mcp` reads what the user has open — unsaved edits and diagnostics
 * included — shows them a file, edits a buffer they can undo, and calls
 * every tool the other plugins offer with `registerTool()`.
 *
 * It listens on a unix socket in `.porta/` from the moment the IDE is up, and
 * stops when the IDE goes down or the plugin is disabled.
 */
return new class('mcp') extends AbstractPlugin implements DisposablePlugin {
    private ?SocketServer $server = null;

    public function activate(PluginApi $api): void
    {
        $api->registerSetting(
            'allowEdits',
            'Let agents edit open files',
            'bool',
            true,
            hint: 'edit_buffer, replace_buffer, insert_text and format: unsaved, one undo step each. Off hides them',
        );
        $api->registerSetting(
            'allowSave',
            'Let agents save files',
            'bool',
            false,
            hint: 'save_buffer writes an open file to disk as Ctrl+S does; a file changed on disk is never overwritten. With edits allowed too, rename rewrites files that are not open',
        );
        $api->registerSetting(
            'followAgent',
            'Bring the file an agent edits to the front',
            'bool',
            false,
            hint: 'off, an agent edits in a tab behind yours and your caret stays put',
        );
        $api->registerSetting(
            'showAgents',
            'Show agents at work',
            'bool',
            true,
            hint: 'a ✦ in the agent\'s colour on the tab and tree row of each file it reads or edits, and who is doing what in the status bar',
        );
        $api->registerSetting(
            'showAgentCursor',
            'Show an agent\'s cursor in the editor',
            'bool',
            true,
            hint: 'a caret in its colour, with its name at the end of the line, where it reads or edits',
        );
        $api->registerSetting('agentFade', 'Hide an agent after (seconds)', 'int', 10, hint: 'without it reading or editing the file', min: 2, max: 120);
        $presence = new Presence(
            $api,
            static fn (): bool => true === $api->setting('showAgents'),
            static fn (): bool => true === $api->setting('showAgentCursor'),
            static fn (): float => (float) $api->setting('agentFade'),
        );
        $api->registerStatusSegment($presence->statusCell(...));
        $opener = new Opener($api, static fn (): bool => true === $api->setting('followAgent'));
        new CoreTools($api, $opener, $presence)->register();
        new SearchTools($api)->register();
        $api->registerSetting(
            'allowRuns',
            'Let agents run tasks and tests',
            'bool',
            false,
            hint: 'run, run_tests: the Run menu\'s tasks and PHPUnit, in the terminal panel where you can watch them',
        );
        $api->registerSetting('runTimeout', 'Wait for a run to end (seconds)', 'int', 300, hint: 'longer, and the agent is told it is still running', min: 1, max: 3600, step: 30);
        new RunTools($api, static fn (): int => (int) $api->setting('runTimeout'))->register();
        $api->registerSetting(
            'lspTimeout',
            'Wait for a language server (seconds)',
            'int',
            15,
            hint: 'definition, references, hover, symbols: how long before the agent is told it did not answer',
            min: 1,
            max: 300,
        );
        new CodeTools($api, static fn (): int => (int) $api->setting('lspTimeout'), $opener, $presence)->register();

        $catalog = new ToolCatalog(
            $api->tools(...),
            $this->name(),
            static fn (): array => [
                ...(true === $api->setting('allowEdits') ? [] : [...CoreTools::EDITS, CodeTools::FORMAT]),
                ...(true === $api->setting('allowSave') ? [] : [CoreTools::SAVE]),
                // A rename rewrites the files that are not open: an edit AND a save.
                ...(true === $api->setting('allowEdits') && true === $api->setting('allowSave') ? [] : [CodeTools::RENAME]),
                ...(true === $api->setting('allowRuns') ? [] : RunTools::RUNS),
            ],
        );
        $log = DiagnosticLog::forRoot($api->projectRoot(), 'mcp');
        $server = new SocketServer(
            new SocketPath($api->projectRoot()),
            static fn (Closure $send, string $connection): Session => new Session($send, $catalog, Ide::VERSION, $connection, onClose: $presence->left(...)),
            $log->append(...),
        );
        $this->server = $server;

        $api->onReady(static function () use ($api, $server): void {
            $error = $server->start();
            if (null !== $error) {
                $api->notify($error, ToastSeverity::Warn, 6.0);
            }
        });
        $api->on(ToolsChanged::class, $server->toolsChanged(...));
        $api->onSettingChanged($server->toolsChanged(...));
        $api->onSettingChanged(static function (string $key) use ($presence): void {
            if (\in_array($key, ['showAgents', 'showAgentCursor', 'agentFade'], strict: true)) {
                $presence->reset();
            }
        });
        $api->onShutdown($this->deactivate(...));
    }

    public function deactivate(): void
    {
        $this->server?->stop();
    }
};
