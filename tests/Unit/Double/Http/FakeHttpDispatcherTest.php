<?php

declare(strict_types=1);

namespace Rapira\Sdk\Tests\Unit\Double\Http;

use Rapira\Exception\ClosedException;
use Rapira\Sdk\Testing\Double\Http\FakeExchange;
use Rapira\Sdk\Testing\Double\Http\FakeHttpDispatcher;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FakeHttpDispatcher::class)]
final class FakeHttpDispatcherTest
{
    public function handsOutExchangesInOrderThenDrains(): void
    {
        $first = new FakeExchange();
        $second = new FakeExchange();
        $dispatcher = (new FakeHttpDispatcher($first))->push($second);

        Assert::same($dispatcher->receive(), $first);
        Assert::same($dispatcher->receive(), $second);
        Expect::exception(ClosedException::class);

        $dispatcher->receive();
    }

    public function tryReceiveAnswersNullOnceEmpty(): void
    {
        Assert::null((new FakeHttpDispatcher())->tryReceive());
    }

    public function receivesAreCountedAndAnnounced(): void
    {
        $announced = 0;
        $dispatcher = new FakeHttpDispatcher(new FakeExchange());
        $dispatcher->beforeReceive = static function () use (&$announced): void {
            ++$announced;
        };

        $dispatcher->receive();
        try {
            $dispatcher->receive();
        } catch (ClosedException) {
        }

        Assert::same($dispatcher->receives, 2);
        Assert::same($announced, 2);
    }

    public function speaksHttp(): void
    {
        Assert::same((new FakeHttpDispatcher())->name(), 'http');
    }
}
