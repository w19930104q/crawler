<?php

namespace Spatie\Crawler;

use GuzzleHttp\TransferStats;

/**
 * A single, immutable HTTP transfer recorded for one crawl queue task.
 *
 * One task can produce several events: every redirect hop and every retry
 * attempt is a separate transfer. The request URI is the URI the handler was
 * asked to fetch for this transfer, while the effective URI is where the
 * transfer ended up (they differ after a redirect). A transfer either ends
 * with an HTTP status code or with a network error, never both.
 */
readonly class TransferEvent
{
    public function __construct(
        protected string $taskId,
        protected string $requestUri,
        protected string $effectiveUri,
        protected ?int $statusCode,
        protected ?string $networkError,
        protected float $transferTimeInSeconds,
        protected int $sequence,
    ) {}

    /**
     * Builds an event from a Guzzle TransferStats. The crawler calls this
     * internally through TransferHistory; it stays public so custom handlers
     * can record transfers using the same conversion.
     */
    public static function fromTransferStats(string $taskId, TransferStats $stats, int $sequence): self
    {
        $response = $stats->getResponse();

        $networkError = $response === null
            ? self::describeNetworkError($stats)
            : null;

        return new self(
            taskId: $taskId,
            requestUri: (string) $stats->getRequest()->getUri(),
            effectiveUri: (string) $stats->getEffectiveUri(),
            statusCode: $response?->getStatusCode(),
            networkError: $networkError,
            transferTimeInSeconds: (float) ($stats->getTransferTime() ?? 0.0),
            sequence: $sequence,
        );
    }

    /**
     * Identifier of the crawl queue task this transfer belongs to. This is the
     * CrawlUrl id, so concurrent tasks that end on the same destination stay
     * distinguishable.
     */
    public function taskId(): string
    {
        return $this->taskId;
    }

    /**
     * URI requested for this specific transfer. After a redirect this is the
     * URI of the hop, not the original queue URL.
     */
    public function requestUri(): string
    {
        return $this->requestUri;
    }

    /**
     * URI the transfer actually reached. Equals the request URI unless the
     * transfer was redirected.
     */
    public function effectiveUri(): string
    {
        return $this->effectiveUri;
    }

    /**
     * HTTP status code returned by the transfer, or null when the transfer
     * failed before a response was received.
     */
    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Human readable network error message, or null when a response was
     * received. Failed terminal tasks therefore remain inspectable.
     */
    public function networkError(): ?string
    {
        return $this->networkError;
    }

    public function wasSuccessful(): bool
    {
        return $this->statusCode !== null;
    }

    public function wasRedirected(): bool
    {
        return $this->requestUri !== $this->effectiveUri;
    }

    public function transferTimeInSeconds(): float
    {
        return $this->transferTimeInSeconds;
    }

    public function transferTimeInMs(): float
    {
        return round($this->transferTimeInSeconds * 1000, 3);
    }

    /**
     * Zero-based position of this transfer within its task's history.
     */
    public function sequence(): int
    {
        return $this->sequence;
    }

    private static function describeNetworkError(TransferStats $stats): string
    {
        $errorData = $stats->getHandlerErrorData();

        if (is_string($errorData) && $errorData !== '') {
            return $errorData;
        }

        if (is_int($errorData) || is_float($errorData) || is_bool($errorData)) {
            return (string) $errorData;
        }

        $curlError = $stats->getHandlerStat('error');

        return is_string($curlError) && $curlError !== '' ? $curlError : 'Network error';
    }
}
