<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Ide;

use Revolt\EventLoop;

/**
 * Porta as the IDE a Claude Code CLI connects to: the {@see IdeServer}
 * listening and announced, the {@see SelectionFeed} ticking while it is, and
 * the "send this to Claude" verb. Started and stopped as a whole — by the IDE
 * coming up and going down, and by the `claudeCode` setting.
 */
final class ClaudeCodeLink
{
    /** Seconds between two looks at the selection: fast enough to feel live, idle otherwise. */
    public const float TICK = 0.25;

    private ?string $ticker = null;

    public function __construct(
        private readonly IdeServer $server,
        private readonly SelectionFeed $selection,
        private readonly float $tick = self::TICK,
    ) {}

    /** Listen and start telling; the reason when it cannot, null when it does. */
    public function start(): ?string
    {
        $why = $this->server->start();
        if (null === $why && null === $this->ticker) {
            $this->ticker = EventLoop::unreference(EventLoop::repeat($this->tick, fn () => $this->selection->tick($this->server)));
        }

        return $why;
    }

    public function stop(): void
    {
        if (null !== $this->ticker) {
            EventLoop::cancel($this->ticker);
            $this->ticker = null;
        }
        $this->server->stop();
    }

    /**
     * Point the connected Claude at what is selected — or at the file, with
     * nothing selected — as its `@` mention; how many clients were told.
     */
    public function mention(): int
    {
        $current = $this->selection->current();
        $clients = $this->server->clientCount();
        if (null === $current || 0 === $clients) {
            return 0;
        }
        $mention = ['filePath' => $current['filePath']];
        if (!$current['selection']['isEmpty']) {
            $mention['lineStart'] = $current['selection']['start']['line'];
            $end = $current['selection']['end'];
            // Ending at a line's first column, a selection takes none of that
            // line: Shift+Down over two lines names those two, not three.
            $mention['lineEnd'] = 0 === $end['character'] ? $end['line'] - 1 : $end['line'];
        }
        $this->server->broadcast('at_mentioned', $mention);

        return $clients;
    }
}
