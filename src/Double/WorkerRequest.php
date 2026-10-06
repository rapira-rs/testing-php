<?php

declare(strict_types=1);

namespace Rapira\Sdk\Testing\Double;

/**
 * One request {@see FakeRuntime} hands to the worker loop: the superglobals the handler sees while it
 * serves it.
 */
final readonly class WorkerRequest
{
    /**
     * @param array<string, mixed> $server The whole `$_SERVER` of the request, save the `REQUEST_TIME` pair.
     * @param array<array-key, mixed> $query `$_GET`.
     * @param array<array-key, mixed> $post `$_POST`.
     * @param array<array-key, mixed> $cookies `$_COOKIE`.
     * @param array<array-key, mixed> $files `$_FILES`.
     */
    public function __construct(
        public array $server = [],
        public array $query = [],
        public array $post = [],
        public array $cookies = [],
        public array $files = [],
    ) {}

    /**
     * A request as a web server would describe it: method, URI and the usual `$_SERVER` entries derived
     * from them, with the query string parsed into `$_GET`.
     *
     * A non-empty $post gets `CONTENT_TYPE: application/x-www-form-urlencoded` unless $server sets one,
     * since PSR-7 factories parse the body only for a form content type.
     *
     * @param non-empty-string $method
     * @param non-empty-string $uri Path with an optional query string, e.g. `/search?q=rapira`.
     * @param array<string, mixed> $server Entries that override the derived ones.
     * @param array<array-key, mixed> $cookies
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $files
     */
    public static function create(
        string $method,
        string $uri,
        array $server = [],
        array $cookies = [],
        array $post = [],
        array $files = [],
    ): self {
        $queryString = \explode('?', $uri, 2)[1] ?? '';
        \parse_str($queryString, $query);

        $defaults = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => $queryString,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => 80,
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
        ];
        if ($post !== []) {
            $defaults['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        }

        return new self($server + $defaults, $query, $post, $cookies, $files);
    }
}
