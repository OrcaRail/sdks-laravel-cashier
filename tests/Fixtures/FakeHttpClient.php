<?php

declare(strict_types=1);

namespace OrcaRail\Cashier\Tests\Fixtures;

use OrcaRail\HttpClient\ClientInterface;

final class FakeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, path: string, body: array<string, mixed>|null}> */
    public array $requests = [];

    /** @var array<string, mixed>|callable|null */
    private mixed $handler = null;

    /**
     * @param  array<string, mixed>|callable  $handler
     */
    public function respondWith(array|callable $handler): void
    {
        $this->handler = $handler;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        bool $requireAuth = true,
    ): mixed {
        $this->requests[] = [
            'method' => $method,
            'path' => $path,
            'body' => $body,
        ];

        if (is_callable($this->handler)) {
            return ($this->handler)($method, $path, $body, $requireAuth);
        }

        if (is_array($this->handler)) {
            return $this->handler;
        }

        return [];
    }
}
