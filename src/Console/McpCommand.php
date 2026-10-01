<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Console;

use Porta\Ide;
use Porta\Install\PortaBinary;
use Porta\McpPlugin\Protocol\Relay;
use Porta\McpPlugin\Server\SocketPath;
use Porta\Plugin\Api\PluginCommand;
use Porta\Support\LineFramer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `porta mcp` — the stdio end of the MCP server that runs inside the IDE.
 *
 * An MCP client launches a server as a process and talks to it over its
 * stdin and stdout; the server this one stands for runs in the IDE open on
 * the project. So this carries lines: stdin to the IDE's socket, the socket
 * to stdout — the IDE's {@see \Porta\McpPlugin\Server\Session} is the
 * protocol.
 *
 * The client launches this once, when its own session starts, and the IDE
 * may not be up yet, or may quit and come back. So the bridge WAITS for it,
 * retrying the socket, and outlives it: {@see Relay} answers for an absent
 * IDE and hands a new one the client's handshake. `--wait` bounds how long
 * it goes without one.
 *
 * A plain blocking `stream_select` loop, because this process has nothing
 * else to do; it ends when the client hangs up.
 */
#[AsCommand(
    name: 'mcp',
    description: 'Connect an MCP client to the Porta IDE open on this project',
)]
final class McpCommand extends PluginCommand
{
    /** Seconds between two tries at the socket while no IDE is up. */
    public const float RETRY = 0.5;

    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    /**
     * @param string|null                                $root    the project; null finds it from the working directory
     * @param (\Closure(string): (resource|null))|null   $connect opens the IDE's socket; null connects for real
     * @param resource|null                              $input   the client's requests; null is the real stdin
     * @param resource|null                              $output  where answers go; null is the real stdout
     * @param float                                      $retry   seconds between two tries at the socket
     */
    public function __construct(
        ?string $root = null,
        private readonly ?\Closure $connect = null,
        $input = null,
        $output = null,
        private readonly float $retry = self::RETRY,
    ) {
        parent::__construct($root);
        $this->input = $input ?? \STDIN;
        $this->output = $output ?? \STDOUT;
        $this->setHelp(\sprintf(
            <<<'HELP'
                Relays the Model Context Protocol between an MCP client, over stdin and stdout,
                and the Porta IDE open on the project with the <info>mcp</info> plugin enabled.

                  <info>claude mcp add porta -- %s mcp</info>

                Run it from the project directory, or anywhere under it. It waits for the IDE
                when it is not open yet, and reconnects when the IDE restarts; <info>--wait</info> gives up
                after that many seconds without one.
                HELP,
            PortaBinary::path(),
        ));
    }

    public function __invoke(
        OutputInterface $output,
        #[Option('Seconds to go without an IDE before giving up; omit to wait for good')]
        ?int $wait = null,
    ): int {
        $errors = self::errors($output);
        $root = $this->projectRoot();
        $paths = new SocketPath($root);
        $relay = new Relay($root, Ide::VERSION);
        $dropped = static fn (string $why) => $errors->writeln('Dropped '.$why);
        $client = new LineFramer($dropped);
        $ide = new LineFramer($dropped);
        $socket = $this->open($paths);
        // What giving up says: nothing ever answered, or the IDE went.
        $absent = \sprintf('<error>No Porta is serving MCP for %s.</error> Open the project in Porta with the mcp plugin enabled, then retry.', $root);
        $since = \hrtime(true);
        $told = false;

        while (true) {
            if (null === $socket && null !== $wait && ((\hrtime(true) - $since) / 1e9) >= $wait) {
                $errors->writeln($absent);

                return self::FAILURE;
            }
            if (null === $socket && !$told) {
                $errors->writeln(\sprintf('Waiting for Porta to open %s with the mcp plugin enabled…', $root));
                $told = true;
            }
            $read = null === $socket ? [$this->input] : [$socket, $this->input];
            $write = null;
            $except = null;
            $seconds = null === $socket ? 0 : null;
            $micro = null === $socket ? (int) ($this->retry * 1e6) : null;
            if (false === \stream_select($read, $write, $except, $seconds, $micro)) {
                // A select interrupted by a signal: not reproducible on demand.
                // @codeCoverageIgnoreStart
                return self::FAILURE;

                // @codeCoverageIgnoreEnd
            }
            // The client's side first, then the IDE's: a request goes out
            // before the answers come in, so an IDE that answered and then
            // quit leaves only what it did NOT answer owed — and what it
            // already said reaches the client even as the client hangs up.
            $ended = false;
            if (\in_array($this->input, $read, strict: true)) {
                $bytes = self::take($this->input);
                $ended = null === $bytes;
                foreach ($client->feed($bytes ?? '') as $line) {
                    if (null === $socket) {
                        $this->send(\array_filter([$relay->offline($line)]));

                        continue;
                    }
                    $relay->toIde($line);
                    self::put($socket, [$line]);
                }
            }
            if (null !== $socket && \in_array($socket, $read, strict: true)) {
                $bytes = self::take($socket);
                $this->send(\array_filter($ide->feed($bytes ?? ''), $relay->fromIde(...)));
                if (null === $bytes) {
                    \fclose($socket);
                    $socket = null;
                    $ide = new LineFramer($dropped);
                    $this->send($relay->lost());
                    $absent = 'Porta closed the connection.';
                    $since = \hrtime(true);
                    $told = false;
                }
            }
            if ($ended) {
                return self::SUCCESS;
            }
            if (null === $socket) {
                $socket = $this->open($paths);
                if (null !== $socket) {
                    $resume = $relay->resume();
                    self::put($socket, $resume['ide']);
                    $this->send($resume['client']);
                }
            }
        }
    }

    /** @return resource|null the IDE's socket, when one is listening */
    private function open(SocketPath $paths)
    {
        // Asked afresh each time: a long-rooted project's IDE listens under a
        // new random name every time it starts.
        $path = $paths->current();

        return null !== $this->connect ? ($this->connect)($path) : self::connect($path);
    }

    /** @return resource|null */
    private static function connect(string $path)
    {
        // @mago-expect lint:no-error-control-operator -- no IDE listening warns; the false return is what is reported
        $socket = @\stream_socket_client('unix://'.$path, timeout: 2.0);

        return false === $socket ? null : $socket;
    }

    /**
     * What $from has now: '' when nothing yet, null once it has hung up.
     *
     * @param resource $from
     */
    private static function take($from): ?string
    {
        $bytes = \fread($from, length: 65_536);
        if (false === $bytes || '' === $bytes) {
            return \feof($from) ? null : '';
        }

        return $bytes;
    }

    /** @param iterable<string> $lines */
    private function send(iterable $lines): void
    {
        self::put($this->output, $lines);
    }

    /**
     * @param resource         $to
     * @param iterable<string> $lines
     */
    private static function put($to, iterable $lines): void
    {
        foreach ($lines as $line) {
            // An IDE that just went fails the write; its hang-up is read next
            // turn, and what it owed is answered then.
            // @mago-expect lint:no-error-control-operator -- a broken pipe is the IDE quitting, handled on the read side
            @\fwrite($to, $line."\n");
        }
        \fflush($to);
    }
}
