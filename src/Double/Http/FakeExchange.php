<?php

declare(strict_types=1);

namespace Rapira\Sdk\Testing\Double\Http;

use Rapira\Exception\AlreadyFinalizedError;
use Rapira\Exception\WorkDiscardedException;
use Rapira\Http\Exception\FileNotSendableException;
use Rapira\Http\Exception\HeadAlreadyWrittenError;
use Rapira\Http\Exception\HeadNotWrittenError;
use Rapira\Http\Exchange;
use Rapira\Http\Multipart;
use Rapira\Http\Request;
use Rapira\InetAddress;

/**
 * In-memory {@see Exchange} that records what a worker writes into it and enforces the ordering rules the
 * host does: one final head, body only until `$eos`, nothing after finalization.
 *
 * Mark it {@see discard()}ed to simulate a host that closed the exchange before the worker took it, or
 * {@see discardOnWrite()} for one that closes it while the request is being handled. The declared
 * `content-length` is not enforced.
 */
final class FakeExchange implements Exchange
{
    /** @var int<100, 599>|null */
    public ?int $status = null;

    /** @var array<non-empty-string, list<string>> */
    public array $headers = [];

    /** @var list<string> Body chunks in write order, empty ones included. */
    public array $chunks = [];

    /** @var list<array{path: non-empty-string, offset: int<0, max>, length: int<1, max>|null}> */
    public array $sentFiles = [];

    /** @var array<non-empty-string, list<string>>|null */
    public ?array $trailers = null;

    public int $flushes = 0;

    /** Makes {@see sendFile()} refuse, as for a path outside the host's sendfile root. */
    public bool $refuseFiles = false;

    private bool $finalized = false;
    private bool $discarded = false;
    private bool $discardOnWrite = false;
    private readonly Request $request;

    /**
     * @param Request|null $request Null is a `GET /`, as {@see for()} builds it.
     */
    public function __construct(?Request $request = null)
    {
        $this->request = $request ?? self::request('/', 'GET', [], '', 'http');
    }

    /**
     * An exchange for a request to $target on `localhost:8080`, from client `10.0.0.7`.
     *
     * @param non-empty-string $target Path with an optional query string.
     * @param non-empty-string $method
     * @param array<non-empty-string, list<string>> $headers
     */
    public static function for(
        string $target,
        string $method = 'GET',
        array $headers = [],
        string|Multipart $body = '',
        string $scheme = 'http',
    ): self {
        return new self(self::request($target, $method, $headers, $body, $scheme));
    }

    /**
     * The host closed the exchange before the worker took it: it is cancelled, and every write throws
     * {@see WorkDiscardedException}.
     */
    public function discard(): void
    {
        $this->discarded = true;
    }

    /**
     * The host closes the exchange while the request is being handled: the first write finds it gone.
     */
    public function discardOnWrite(): void
    {
        $this->discardOnWrite = true;
    }

    /**
     * The body written so far, chunks joined.
     */
    public function getBody(): string
    {
        return \implode('', $this->chunks);
    }

    /**
     * A response header by case-insensitive name, its values joined with `, `; null when absent.
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $values) {
            if (\strcasecmp($key, $name) === 0) {
                return \implode(', ', $values);
            }
        }

        return null;
    }

    #[\Override]
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * @psalm-suppress DocblockTypeContradiction, InvalidCast The host checks what the type only promises.
     */
    #[\Override]
    public function writeHead(int $status, array $headers = []): void
    {
        $this->assertOpen();
        if ($status < 100 || $status > 599) {
            throw new \ValueError("Status {$status} is outside 100-599.");
        }
        // Interim heads are advisory and repeatable; they are not recorded.
        if ($status < 200 && $status !== 101) {
            return;
        }
        if ($this->status !== null) {
            throw new HeadAlreadyWrittenError();
        }

        $this->status = $status;
        $this->headers = $headers;
    }

    #[\Override]
    public function writeBody(string $content, bool $eos = true): void
    {
        $this->assertOpen();
        $this->status ??= 200;
        $this->chunks[] = $content;
        $this->finalized = $eos;
    }

    /**
     * @psalm-suppress DocblockTypeContradiction The host checks what the type only promises.
     */
    #[\Override]
    public function sendFile(string $path, int $offset = 0, ?int $length = null, bool $eos = true): void
    {
        $this->assertOpen();
        if ($offset < 0 || ($length !== null && $length < 1)) {
            throw new \ValueError('The offset must not be negative, and the length must be positive.');
        }
        if ($this->refuseFiles) {
            throw new FileNotSendableException();
        }

        $this->status ??= 200;
        $this->sentFiles[] = ['path' => $path, 'offset' => $offset, 'length' => $length];
        $this->finalized = $eos;
    }

    #[\Override]
    public function writeTrailers(array $trailers): void
    {
        $this->assertOpen();
        $this->status ?? throw new HeadNotWrittenError();

        $this->trailers = $trailers;
        $this->finalized = true;
    }

    #[\Override]
    public function flush(): void
    {
        $this->assertOpen();
        $this->status ??= 200;
        ++$this->flushes;
    }

    #[\Override]
    public function isFinalized(): bool
    {
        return $this->finalized || $this->discarded;
    }

    #[\Override]
    public function isCancelled(): bool
    {
        return $this->discarded;
    }

    /**
     * @param non-empty-string $target
     * @param non-empty-string $method
     * @param array<non-empty-string, list<string>> $headers
     */
    private static function request(
        string $target,
        string $method,
        array $headers,
        string|Multipart $body,
        string $scheme,
    ): Request {
        return new Request(
            method: $method,
            uri: $scheme . '://localhost:8080' . $target,
            target: $target,
            authority: 'localhost:8080',
            protocol: 'HTTP/1.1',
            headers: $headers,
            body: $body,
            remote: new InetAddress('10.0.0.7', 40000),
            server: new InetAddress('127.0.0.1', 8080),
            tls: null,
            receivedAt: 1_700_000_000.25,
        );
    }

    private function assertOpen(): void
    {
        $this->discarded = $this->discarded || $this->discardOnWrite;
        $this->discarded and throw new WorkDiscardedException();
        $this->finalized and throw new AlreadyFinalizedError();
    }
}
