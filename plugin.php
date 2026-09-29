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
            hint: 'replace_buffer and insert_text: unsaved, one undo step each. Off hides both',
        );
        $api->registerSetting(
            'allowSave',
            'Let agents save files',
            'bool',
            false,
            hint: 'save_buffer writes an open file to disk as Ctrl+S does; a file changed on disk is never overwritten',
        );
        new CoreTools($api)->register();
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
        new CodeTools($api, static fn (): int => (int) $api->setting('lspTimeout'))->register();

        $catalog = new ToolCatalog(
            $api->tools(...),
            $this->name(),
            static fn (): array => [
                ...(true === $api->setting('allowEdits') ? [] : CoreTools::EDITS),
                ...(true === $api->setting('allowSave') ? [] : [CoreTools::SAVE]),
                ...(true === $api->setting('allowRuns') ? [] : RunTools::RUNS),
            ],
        );
        $log = DiagnosticLog::forRoot($api->projectRoot(), 'mcp');
        $server = new SocketServer(
            new SocketPath($api->projectRoot()),
            static fn (Closure $send): Session => new Session($send, $catalog, Ide::VERSION),
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
        $api->onShutdown($this->deactivate(...));
    }

    public function deactivate(): void
    {
        $this->server?->stop();
    }
};
