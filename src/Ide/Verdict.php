<?php

declare(strict_types=1);

namespace Porta\McpPlugin\Ide;

/** What the user decided about a change Claude proposed: keep it, or put the file back. */
enum Verdict
{
    case Keep;
    case PutBack;
}
