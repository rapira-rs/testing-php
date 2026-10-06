<?php

declare(strict_types=1);

namespace Rapira\Sdk\Testing\Double;

use Rapira\Dispatcher;
use Rapira\Internal\Double;
use Rapira\Internal\Runtime;
use Rapira\LogLevel;
use Rapira\Mode;

/**
 * Scripted Rapira runtime for tests: once {@see install()}ed, the `rapira/contract` stub functions answer
 * through it until {@see reset()}. It is process-wide, so a test that installs one resets it afterwards.
 *
 * In {@see Mode::Worker} each entry of {@see $requests} is one request {@see handleRequest()} serves: its
 * superglobals are in place while the handler runs, and the previous ones come back afterwards. Like the
 * extension, the worker loop gives out no request at all once the queue is empty, and refuses outside
 * the mode that owns it.
 */
final class FakeRuntime extends Runtime
{
    /** Number of requests {@see handleRequest()} has served. */
    public int $servedRequests = 0;

    /**
     * What the handler printed for each served request, when output is captured.
     *
     * @var list<string>
     */
    public array $outputs = [];

    /** Number of {@see finishRequest()} calls. */
    public int $finishedRequests = 0;

    /** @var list<array{message: string, level: LogLevel, context: array<array-key, mixed>}> */
    public array $logs = [];

    /**
     * @param list<WorkerRequest|array<string, mixed>> $requests Worker requests in order; an array is
     * shorthand for a request with only these `$_SERVER` entries.
     * @param bool $captureOutput Collect what the handler prints into {@see $outputs}, the way the
     * extension sends it to the client, instead of letting it through to the test's own output.
     * @param non-empty-string $version
     */
    public function __construct(
        public Mode $mode = Mode::Classic,
        public ?Dispatcher $dispatcher = null,
        public array $requests = [],
        public bool $captureOutput = false,
        public string $version = '0.0.0',
    ) {}

    /**
     * Return the stub functions to their fixed answers: {@see Mode::Classic}, no dispatcher, no worker
     * requests.
     */
    public static function reset(): void
    {
        Double::setRuntime(null);
    }

    /**
     * Make the stub functions answer through this runtime.
     */
    public function install(): static
    {
        Double::setRuntime($this);

        return $this;
    }

    /**
     * Append a worker request; see {@see WorkerRequest::create()}.
     *
     * @param non-empty-string $method
     * @param non-empty-string $uri
     * @param array<string, mixed> $server
     * @param array<array-key, mixed> $cookies
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $files
     */
    public function queue(
        string $method,
        string $uri,
        array $server = [],
        array $cookies = [],
        array $post = [],
        array $files = [],
    ): static {
        $this->requests[] = WorkerRequest::create($method, $uri, $server, $cookies, $post, $files);

        return $this;
    }

    #[\Override]
    public function version(): string
    {
        return $this->version;
    }

    #[\Override]
    public function mode(): Mode
    {
        return $this->mode;
    }

    #[\Override]
    public function handleRequest(callable $handler): bool
    {
        if ($this->mode !== Mode::Worker) {
            return parent::handleRequest($handler);
        }

        if ($this->servedRequests >= \count($this->requests)) {
            return false;
        }

        $request = $this->requests[$this->servedRequests++];
        \is_array($request) and $request = new WorkerRequest($request);

        $saved = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST];
        $_SERVER = $request->server + $_SERVER;
        $_GET = $request->query;
        $_POST = $request->post;
        $_COOKIE = $request->cookies;
        $_FILES = $request->files;
        $_REQUEST = \array_merge($request->query, $request->post);

        $this->captureOutput and \ob_start();
        try {
            $continue = $handler();
        } finally {
            $this->captureOutput and $this->outputs[] = (string) \ob_get_clean();
            [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST] = $saved;
        }

        return $continue && $this->servedRequests < \count($this->requests);
    }

    #[\Override]
    public function dispatcher(): Dispatcher
    {
        if ($this->mode !== Mode::Dispatcher) {
            return parent::dispatcher();
        }

        return $this->dispatcher ?? throw new \LogicException('FakeRuntime has no dispatcher to hand out.');
    }

    #[\Override]
    public function log(string $message, LogLevel $level, array $context): void
    {
        $this->logs[] = ['message' => $message, 'level' => $level, 'context' => $context];
    }

    #[\Override]
    public function finishRequest(): bool
    {
        $this->mode !== Mode::Dispatcher
            or throw new \Error('rapira_finish_request() is not available in Mode::Dispatcher');

        ++$this->finishedRequests;

        return true;
    }
}
