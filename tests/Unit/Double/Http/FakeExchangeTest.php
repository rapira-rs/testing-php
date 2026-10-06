<?php

declare(strict_types=1);

namespace Rapira\Sdk\Tests\Unit\Double\Http;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exception\FileNotSendableException;
use Rapira\Http\Exception\HeadAlreadyWrittenError;
use Rapira\Http\Exception\HeadNotWrittenError;
use Rapira\Sdk\Testing\Double\Http\FakeExchange;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FakeExchange::class)]
final class FakeExchangeTest
{
    public function forDescribesTheRequest(): void
    {
        $request = FakeExchange::for('/users/42?full=1', 'POST', ['accept' => ['application/json']], '{}', 'https')
            ->getRequest();

        Assert::same($request->method, 'POST');
        Assert::same($request->uri, 'https://localhost:8080/users/42?full=1');
        Assert::same($request->target, '/users/42?full=1');
        Assert::same($request->headers, ['accept' => ['application/json']]);
        Assert::same($request->body, '{}');
    }

    public function recordsTheResponseAndFinalizesAtTheEndOfTheStream(): void
    {
        $exchange = new FakeExchange();

        $exchange->writeHead(201, ['Content-Type' => ['text/plain']]);
        $exchange->writeBody('Hello, ', eos: false);
        Assert::false($exchange->isFinalized());
        $exchange->writeBody('world');

        Assert::same($exchange->status, 201);
        Assert::same($exchange->header('content-type'), 'text/plain');
        Assert::same($exchange->chunks, ['Hello, ', 'world']);
        Assert::same($exchange->getBody(), 'Hello, world');
        Assert::true($exchange->isFinalized());
    }

    public function bodyWithoutAHeadCommitsOk(): void
    {
        $exchange = new FakeExchange();

        $exchange->writeBody('ok');

        Assert::same($exchange->status, 200);
    }

    public function interimHeadsAreNotRecorded(): void
    {
        $exchange = new FakeExchange();

        $exchange->writeHead(103, ['Link' => ['</app.css>; rel=preload']]);
        $exchange->writeHead(200);

        Assert::same($exchange->status, 200);
        Assert::same($exchange->headers, []);
    }

    public function secondFinalHeadIsRefused(): void
    {
        $exchange = new FakeExchange();
        $exchange->writeHead(200);

        Expect::exception(HeadAlreadyWrittenError::class);

        $exchange->writeHead(500);
    }

    public function nothingIsWrittenAfterFinalization(): void
    {
        $exchange = new FakeExchange();
        $exchange->writeBody('done');

        Expect::exception(AlreadyFinalizedError::class);

        $exchange->flush();
    }

    public function trailersNeedAHeadAndEndTheResponse(): void
    {
        $exchange = new FakeExchange();
        $exchange->writeHead(200);
        $exchange->writeBody('data', eos: false);

        $exchange->writeTrailers(['grpc-status' => ['0']]);

        Assert::same($exchange->trailers, ['grpc-status' => ['0']]);
        Assert::true($exchange->isFinalized());
    }

    public function trailersBeforeAHeadAreRefused(): void
    {
        Expect::exception(HeadNotWrittenError::class);

        (new FakeExchange())->writeTrailers([]);
    }

    public function flushesAreCounted(): void
    {
        $exchange = new FakeExchange();

        $exchange->flush();
        $exchange->flush();

        Assert::same($exchange->flushes, 2);
        Assert::same($exchange->status, 200);
    }

    public function sentFilesAreRecorded(): void
    {
        $exchange = new FakeExchange();

        $exchange->sendFile('/srv/report.pdf', 10, 20);

        Assert::same($exchange->sentFiles, [['path' => '/srv/report.pdf', 'offset' => 10, 'length' => 20]]);
        Assert::true($exchange->isFinalized());
    }

    public function refusedFileIsNotSent(): void
    {
        $exchange = new FakeExchange();
        $exchange->refuseFiles = true;

        Expect::exception(FileNotSendableException::class);

        $exchange->sendFile('/etc/passwd');
    }

    public function discardedExchangeIsCancelledAndRefusesWrites(): void
    {
        $exchange = new FakeExchange();
        $exchange->discard();

        Assert::true($exchange->isCancelled());
        Assert::true($exchange->isFinalized());
        Expect::exception(WorkDiscardedException::class);

        $exchange->writeHead(200);
    }

    public function exchangeDiscardedOnWriteLooksOpenUntilTheFirstWrite(): void
    {
        $exchange = new FakeExchange();
        $exchange->discardOnWrite();

        Assert::false($exchange->isCancelled());
        Expect::exception(WorkDiscardedException::class);

        $exchange->writeBody('late');
    }
}
