<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Tools;

use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\ToolEffect;
use Porta\Support\FiberWait;

/**
 * The Run menu and the project's tests as tools, in the IDE's terminal panel
 * where the user can watch: each run is a `run:<title>` tab, started without
 * taking the screen, and what it printed is read back as plain text.
 *
 * A call waits for the run to end — up to the `runTimeout` setting — and
 * answers with the exit code and the output's end. A run still going by then
 * keeps going; `run_output` reads it later. So does one started with
 * `wait: false`.
 *
 * Running things is the agent acting on the user's machine through the IDE,
 * so these tools are behind the `allowRuns` setting, off until the user turns
 * it on.
 */
final readonly class RunTools
{
    /** The tools that start a process — what the `allowRuns` setting hides. */
    public const array RUNS = ['run_configurations', 'run', 'run_tests', 'run_output'];

    /** The terminal tab the tests run in. */
    public const string TESTS = 'phpunit';

    /** @param \Closure(): int $timeout seconds a call waits for a run to end, read per call */
    public function __construct(
        private PluginApi $api,
        private \Closure $timeout,
    ) {}

    public function register(): void
    {
        $wait = ['type' => 'boolean', 'description' => 'Wait for it to end (default true); false answers at once — read it later with run_output'];
        $this->api->registerTool(
            'run_configurations',
            'What the IDE\'s Run menu offers right now: the active PHP file, the composer scripts, the Makefile targets.',
            ['type' => 'object', 'properties' => []],
            fn (array $arguments): array => \array_map(
                static fn (array $task): array => ['label' => $task['label'], 'command' => \implode(' ', $task['command'])],
                $this->api->runs()->configurations(),
            ),
            effect: ToolEffect::Reads,
        );
        $this->api->registerTool(
            'run',
            'Run one of run_configurations by its label, in a tab of the IDE\'s terminal panel, and answer with its exit code and the end of its output.',
            ['type' => 'object', 'properties' => ['label' => ['type' => 'string'], 'wait' => $wait], 'required' => ['label']],
            $this->run(...),
            effect: ToolEffect::Writes,
        );
        $this->api->registerTool(
            'run_tests',
            'Run the project\'s PHPUnit tests — all of them, one file or directory, and/or those matching a filter — in the IDE\'s terminal panel, and answer with the exit code and the end of the output.',
            [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'A test file or directory, project-relative'],
                    'filter' => ['type' => 'string', 'description' => 'PHPUnit --filter: a test method or class name, or a pattern'],
                    'wait' => $wait,
                ],
            ],
            $this->tests(...),
            effect: ToolEffect::Writes,
        );
        $this->api->registerTool(
            'run_output',
            'What a run printed so far (its end), whether it is still running, and its exit code — by the label it was started with; '.self::TESTS.' for run_tests.',
            ['type' => 'object', 'properties' => ['label' => ['type' => 'string']], 'required' => ['label']],
            $this->output(...),
            effect: ToolEffect::Reads,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function run(array $arguments): array
    {
        $read = new Arguments($arguments, $this->api->projectRoot());
        $label = $read->text('label');
        $tasks = $this->api->runs()->configurations();
        foreach ($tasks as $task) {
            if ($label === $task['label']) {
                return $this->start($label, $task['command'], $read);
            }
        }

        throw new \InvalidArgumentException(\sprintf('No run configuration "%s" — there are: %s', $label, \implode(', ', \array_column($tasks, 'label'))));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function tests(array $arguments): array
    {
        $read = new Arguments($arguments, $this->api->projectRoot());
        $phpunit = $read->root().'/vendor/bin/phpunit';
        if (!\is_file($phpunit)) {
            throw new \RuntimeException('This project has no vendor/bin/phpunit — composer require --dev phpunit/phpunit, or run a configuration instead');
        }
        $command = [$phpunit];
        $path = $read->path();
        if (null !== $path) {
            $command[] = $path;
        }
        $filter = \is_string($arguments['filter'] ?? null) ? $arguments['filter'] : '';
        if ('' !== $filter) {
            $command[] = '--filter';
            $command[] = $filter;
        }

        return $this->start(self::TESTS, $command, $read);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function output(array $arguments): array
    {
        $label = new Arguments($arguments, $this->api->projectRoot())->text('label');

        return ['label' => $label] + ($this->api->runs()->output($label) ?? throw new \InvalidArgumentException(\sprintf('No run "%s" has been started', $label)));
    }

    /**
     * @param list<string> $command
     *
     * @return array<string, mixed>
     */
    private function start(string $title, array $command, Arguments $read): array
    {
        $runs = $this->api->runs();
        $wait = $read->flagOr('wait', true);
        if (!$wait) {
            $runs->start($title, $command);

            return ['label' => $title, 'running' => true, 'note' => 'Started — read it with run_output'];
        }
        $seconds = ($this->timeout)();
        /** @var int|null $code */
        $code = FiberWait::within($seconds, static fn (\Closure $done) => $runs->start($title, $command, $done), null);
        $result = ['label' => $title] + ($runs->output($title) ?? ['running' => false, 'exitCode' => null, 'output' => '']);
        if (null === $code) {
            $result['note'] = \sprintf('Still running after %ds — read it with run_output', $seconds);
        }
        if (-1 === $code) {
            $result['note'] = 'Stopped before it finished: the run was replaced or its tab closed';
        }

        return $result;
    }
}
