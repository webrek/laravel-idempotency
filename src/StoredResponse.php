<?php

namespace Webrek\Idempotency;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A response captured for replay, plus the fingerprint of the request that
 * produced it so a reused key carrying a different payload can be detected.
 */
final class StoredResponse
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $flash
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly string $fingerprint,
        public readonly array $flash = [],
    ) {}

    /**
     * @param  list<string>  $persistHeaders
     * @param  array<string, mixed>  $flash
     */
    public static function capture(SymfonyResponse $response, string $fingerprint, array $persistHeaders, array $flash = []): self
    {
        $headers = [];

        foreach ($persistHeaders as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = (string) $response->headers->get($name);
            }
        }

        return new self(
            $response->getStatusCode(),
            (string) $response->getContent(),
            $headers,
            $fingerprint,
            $flash,
        );
    }

    /**
     * @param  array{status: int|string, body: string, headers?: array<string, string>, fingerprint: string, flash?: array<string, mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['status'],
            (string) $data['body'],
            $data['headers'] ?? [],
            (string) $data['fingerprint'],
            $data['flash'] ?? [],
        );
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string>, fingerprint: string, flash: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'body' => $this->body,
            'headers' => $this->headers,
            'fingerprint' => $this->fingerprint,
            'flash' => $this->flash,
        ];
    }

    public function toResponse(): Response
    {
        return new Response($this->body, $this->status, $this->headers);
    }
}
