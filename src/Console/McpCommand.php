<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Console;

use Porta\McpPlugin\Server\SocketPath;
use Porta\Plugin\Api\PluginCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `porta mcp` — the stdio end of the MCP server that runs inside the IDE.
 *
 * An MCP client launches a server as a process and talks to it over its
 * stdin and stdout; the server this one stands for is already running, in the
 * IDE open on the project. So this only carries bytes: stdin to the IDE's
 * socket, the socket to stdout, line-framed JSON both ways and nothing parsed
 * — the IDE's {@see \Porta\McpPlugin\Server\Session} is the protocol.
 *
 * A plain blocking `stream_select` loop, because this process has nothing
 * else to do; it ends when either side hangs up.
 */
#[AsCommand(
    name: 'mcp',
    description: 'Connect an MCP client to the Porta IDE open on this project',
    help: <<<'HELP'
        The IDE must be running on the project with the <info>mcp</info> plugin enabled; this
        connects to it and relays the Model Context Protocol over stdin and stdout.

          <info>claude mcp add porta -- porta mcp</info>

        Run it from the project directory, or anywhere under it.
        HELP,
)]
final class McpCommand extends PluginCommand
{
    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    /**
     * @param string|null                                $root    the project; null finds it from the working directory
     * @param (\Closure(string): (resource|null))|null   $connect opens the IDE's socket; null connects for real
     * @param resource|null                              $input   the client's requests; null is the real stdin
     * @param resource|null                              $output  where answers go; null is the real stdout
     */
    public function __construct(
        ?string $root = null,
        private readonly ?\Closure $connect = null,
        $input = null,
        $output = null,
    ) {
        parent::__construct($root);
        $this->input = $input ?? \STDIN;
        $this->output = $output ?? \STDOUT;
    }

    public function __invoke(OutputInterface $output): int
    {
        $errors = self::errors($output);
        $root = $this->projectRoot();
        $path = new SocketPath($root)->current();
        $socket = null !== $this->connect ? ($this->connect)($path) : self::connect($path);
        if (null === $socket) {
            $errors->writeln(\sprintf('<error>No Porta is serving MCP for %s.</error> Open the project in Porta with the mcp plugin enabled, then retry.', $root));

            return self::FAILURE;
        }

        while (true) {
            $read = [$socket, $this->input];
            $write = null;
            $except = null;
            if (false === \stream_select($read, $write, $except, seconds: null)) {
                // A select interrupted by a signal: not reproducible on demand.
                // @codeCoverageIgnoreStart
                return self::FAILURE;

                // @codeCoverageIgnoreEnd
            }
            // The IDE's side first: what it already said reaches the client
            // even when the client's side has just closed.
            if (\in_array($socket, $read, strict: true) && !self::relay($socket, $this->output)) {
                $errors->writeln('Porta closed the connection.');

                return self::FAILURE;
            }
            if (\in_array($this->input, $read, strict: true) && !self::relay($this->input, $socket)) {
                return self::SUCCESS;
            }
        }
    }

    /** @return resource|null */
    private static function connect(string $path)
    {
        // @mago-expect lint:no-error-control-operator -- no IDE listening warns; the false return is what is reported
        $socket = @\stream_socket_client('unix://'.$path, timeout: 2.0);

        return false === $socket ? null : $socket;
    }

    /**
     * Move what $from has now to $to; false once $from has hung up.
     *
     * @param resource $from
     * @param resource $to
     */
    private static function relay($from, $to): bool
    {
        $bytes = \fread($from, length: 65_536);
        if (false === $bytes || '' === $bytes) {
            return !\feof($from);
        }
        \fwrite($to, $bytes);
        \fflush($to);

        return true;
    }
}
