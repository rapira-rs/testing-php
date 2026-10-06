<?php

declare(strict_types=1);

namespace Rapira\Sdk\Tests\Unit\Double;

use Rapira\Dispatcher;
use Rapira\DispatcherInfo;
use Rapira\Exception\ClosedException;
use Rapira\Exception\NoDispatcherError;
use Rapira\Exception\NotInWorkerModeError;
use Rapira\LogLevel;
use Rapira\Mode;
use Rapira\Sdk\Testing\Double\FakeRuntime;
use Rapira\Internal\Double;
use Rapira\Internal\Runtime;
use Rapira\Work;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rapira\get_dispatcher;
use function Rapira\get_mode;
use function Rapira\get_version;
use function Rapira\handle_request;
use function Rapira\log;

/**
 * Drives {@see FakeRuntime} through the `rapira/contract` stub functions, the way code under test sees it.
 */
#[Test]
#[Covers(FakeRuntime::class)]
final class FakeRuntimeTest
{
    #[AfterTest]
    public function resetRuntime(): void
    {
        FakeRuntime::reset();
    }

    public function stubsKeepTheirAnswersWithoutADouble(): void
    {
        Assert::same(get_mode(), Mode::Classic);
        Assert::same(Double::runtime()::class, Runtime::class);
    }

    public function stubsAnswerThroughTheInstalledDouble(): void
    {
        (new FakeRuntime(Mode::Worker, version: '1.2.3'))->install();

        Assert::same(get_mode(), Mode::Worker);
        Assert::same(get_version(), '1.2.3');
    }

    public function resetRestoresTheStubAnswers(): void
    {
        (new FakeRuntime(Mode::Worker))->install();
        FakeRuntime::reset();

        Assert::same(get_mode(), Mode::Classic);
    }

    public function workerServesEveryQueuedRequestThroughTheSuperglobals(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker, requests: [
            ['REQUEST_URI' => '/first'],
            ['REQUEST_URI' => '/second'],
        ]))->install();
        $seen = [];

        while (handle_request(static function () use (&$seen): bool {
            $seen[] = $_SERVER['REQUEST_URI'];
            return true;
        }));

        Assert::same($seen, ['/first', '/second']);
        Assert::same($runtime->servedRequests, 2);
    }

    public function workerRestoresTheSuperglobalsAfterEachRequest(): void
    {
        $_SERVER['RAPIRA_TEST_KEPT'] = 'original';
        (new FakeRuntime(Mode::Worker, requests: [
            ['RAPIRA_TEST_KEPT' => 'request', 'RAPIRA_TEST_ADDED' => 'request'],
        ]))->install();

        try {
            handle_request(static fn(): bool => true);

            Assert::same($_SERVER['RAPIRA_TEST_KEPT'], 'original');
            Assert::false(\array_key_exists('RAPIRA_TEST_ADDED', $_SERVER));
        } finally {
            unset($_SERVER['RAPIRA_TEST_KEPT']);
        }
    }

    public function requestServerHoldsNothingFromTheProcess(): void
    {
        $_SERVER['HTTP_RAPIRA_TEST_BOOT'] = 'boot';
        (new FakeRuntime(Mode::Worker, requests: [['REQUEST_URI' => '/']]))->install();
        $seen = [];

        try {
            handle_request(static function () use (&$seen): bool {
                $seen = \array_keys($_SERVER);
                return true;
            });
        } finally {
            unset($_SERVER['HTTP_RAPIRA_TEST_BOOT']);
        }

        Assert::same($seen, ['REQUEST_URI', 'REQUEST_TIME_FLOAT', 'REQUEST_TIME']);
    }

    public function queuedRequestDescribesItselfAsAWebServerWould(): void
    {
        (new FakeRuntime(Mode::Worker))->queue('GET', '/search?q=rapira&page=2', cookies: ['sid' => 'abc'])->install();
        $seen = [];

        handle_request(static function () use (&$seen): bool {
            $seen = [$_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING'], $_GET, $_COOKIE];
            return true;
        });

        Assert::same($seen, ['GET', '/search?q=rapira&page=2', 'q=rapira&page=2', ['q' => 'rapira', 'page' => '2'], ['sid' => 'abc']]);
    }

    public function queuedFormPostGetsAFormContentType(): void
    {
        (new FakeRuntime(Mode::Worker))->queue('POST', '/login', post: ['user' => 'alice'])->install();
        $seen = [];

        handle_request(static function () use (&$seen): bool {
            $seen = [$_SERVER['CONTENT_TYPE'], $_POST, $_REQUEST];
            return true;
        });

        Assert::same($seen, ['application/x-www-form-urlencoded', ['user' => 'alice'], ['user' => 'alice']]);
    }

    public function workerRestoresEverySuperglobalAfterARequest(): void
    {
        $_GET = ['outer' => '1'];
        (new FakeRuntime(Mode::Worker))->queue('POST', '/?inner=1', post: ['field' => 'x'])->install();

        try {
            handle_request(static fn(): bool => true);

            Assert::same($_GET, ['outer' => '1']);
            Assert::same($_POST, []);
        } finally {
            $_GET = [];
        }
    }

    public function capturedOutputIsCollectedPerRequest(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker, requests: [[], []], captureOutput: true))->install();

        while (handle_request(static function (): bool {
            echo 'served';
            return true;
        }));

        Assert::same($runtime->outputs, ['served', 'served']);
    }

    public function everyServedRequestReturnsTrueAndTheDrainingCallServesNothing(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker, requests: [[], []]))->install();
        $calls = 0;
        $handler = static function () use (&$calls): bool {
            ++$calls;
            return true;
        };

        Assert::same([handle_request($handler), handle_request($handler), handle_request($handler)], [true, true, false]);
        Assert::same($calls, 2);
        Assert::same($runtime->servedRequests, 2);
    }

    public function handlerReturningFalseDoesNotStopTheWorker(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker, requests: [[], []]))->install();

        Assert::true(handle_request(static fn(): bool => false));
        Assert::true(handle_request(static fn(): bool => false));
        Assert::same($runtime->servedRequests, 2);
    }

    public function workerWithAnEmptyQueueNeverRunsTheHandler(): void
    {
        (new FakeRuntime(Mode::Worker))->install();
        $called = false;

        Assert::false(handle_request(static function () use (&$called): bool {
            $called = true;
            return true;
        }));
        Assert::false($called);
    }

    public function handleRequestRefusesOutsideWorkerMode(): void
    {
        (new FakeRuntime(Mode::Classic, requests: [[]]))->install();

        Expect::exception(NotInWorkerModeError::class);

        handle_request(static fn(): bool => true);
    }

    public function dispatcherModeHandsOutTheDispatcher(): void
    {
        $dispatcher = self::dispatcher();
        (new FakeRuntime(Mode::Dispatcher, $dispatcher))->install();

        Assert::same(get_dispatcher(), $dispatcher);
    }

    public function getDispatcherRefusesOutsideDispatcherMode(): void
    {
        (new FakeRuntime(Mode::Worker, self::dispatcher()))->install();

        Expect::exception(NoDispatcherError::class);

        get_dispatcher();
    }

    public function logsAreRecorded(): void
    {
        $runtime = (new FakeRuntime())->install();

        log('boot failed', LogLevel::Error, ['attempt' => 2]);

        Assert::same($runtime->logs, [
            ['message' => 'boot failed', 'level' => LogLevel::Error, 'context' => ['attempt' => 2]],
        ]);
    }

    public function finishRequestIsCounted(): void
    {
        $runtime = (new FakeRuntime(Mode::Worker))->install();

        Assert::true(rapira_finish_request());
        Assert::same($runtime->finishedRequests, 1);
    }

    private static function dispatcher(): Dispatcher
    {
        return new class implements Dispatcher {
            public function name(): string
            {
                return 'fake';
            }

            public function tryReceive(): ?Work
            {
                return null;
            }

            public function receive(int $timeout = -1): Work
            {
                throw new ClosedException();
            }

            public function getInfo(): DispatcherInfo
            {
                throw new \LogicException('Not scripted.');
            }
        };
    }
}
