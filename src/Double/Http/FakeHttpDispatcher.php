<?php

declare(strict_types=1);

namespace Rapira\Sdk\Testing\Double\Http;

use Rapira\Exception\ClosedException;
use Rapira\Http\Exchange;
use Rapira\Http\HttpDispatcher;
use Rapira\Http\HttpDispatcherInfo;

/**
 * In-memory {@see HttpDispatcher}: hands out the queued exchanges in order, then reports itself drained
 * with {@see ClosedException}, the way the host does at shutdown.
 */
final class FakeHttpDispatcher implements HttpDispatcher
{
    /** @var \Closure(): void|null Runs at the start of every {@see receive()}. */
    public ?\Closure $beforeReceive = null;

    /** Number of {@see receive()} calls, the one that found the queue drained included. */
    public int $receives = 0;

    /** @var list<Exchange> */
    private array $queue;

    public function __construct(Exchange ...$exchanges)
    {
        $this->queue = \array_values($exchanges);
    }

    /**
     * Append exchanges to the queue.
     */
    public function push(Exchange ...$exchanges): static
    {
        \array_push($this->queue, ...\array_values($exchanges));

        return $this;
    }

    #[\Override]
    public function name(): string
    {
        return 'http';
    }

    #[\Override]
    public function tryReceive(): ?Exchange
    {
        return \array_shift($this->queue);
    }

    #[\Override]
    public function receive(int $timeout = -1): Exchange
    {
        ++$this->receives;
        ($this->beforeReceive)?->__invoke();

        return \array_shift($this->queue) ?? throw new ClosedException('Drained.');
    }

    #[\Override]
    public function getInfo(): HttpDispatcherInfo
    {
        throw new \LogicException('Not supported by the fake.');
    }
}
