<?php

namespace Spatie\Crawler\Test\TestClasses;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Deterministic, fully offline Guzzle handler used by the transfer tracking
 * tests. It performs no real I/O: responses, redirects and network failures
 * are scripted per request URI, and on_stats is invoked exactly once per
 * handler invocation (including retries) just like the real curl handler.
 */
class ScriptedTransferHandler
{
    /** @var list<RequestInterface> Every request reaching the handler, in order. */
    public array $requests = [];

    /** @var list<TransferStats> Every on_stats payload, in order. */
    public array $stats = [];

    /**
     * Per-URI script. A value is either a ResponseInterface (a redirect
     * response is followed by Guzzle's RedirectMiddleware), an Exception that
     * rejects the transfer, or a callable receiving the request and returning
     * one of those. The same URI can be scripted multiple times by passing a
     * list of scripts, one per attempt.
     *
     * @var array<string, list<ResponseInterface|\Throwable|callable>>
     */
    protected array $scripts = [];

    /** @var array<string, int> */
    protected array $attempts = [];

    /**
     * @param array<string, ResponseInterface|\Throwable|callable|list<mixed>> $scripts
     */
    public function __construct(array $scripts = [])
    {
        foreach ($scripts as $uri => $script) {
            $this->scripts[$this->normalize($uri)] = is_array($script)
                ? array_values($script)
                : [$script];
        }
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->requests[] = $request;

        $normalized = $this->normalize((string) $request->getUri());
        $attempt = $this->attempts[$normalized] ?? 0;
        $this->attempts[$normalized] = $attempt + 1;

        $script = $this->scripts[$normalized][$attempt]
            ?? end($this->scripts[$normalized])
            ?? new Response(404, [], 'Not Found');

        if (is_callable($script)) {
            $script = $script($request, $options);
        }

        $transferTime = $options['scripted_transfer_time'] ?? 0.0125;

        if ($script instanceof \Throwable) {
            $stats = new TransferStats($request, null, $transferTime, $script->getMessage(), [
                'total_time' => $transferTime,
                'error' => $script->getMessage(),
            ]);

            if (isset($options['on_stats'])) {
                ($options['on_stats'])($stats);
            }

            $this->stats[] = $stats;

            return Create::rejectionFor($script);
        }

        $stats = new TransferStats($request, $script, $transferTime, null, [
            'total_time' => $transferTime,
        ]);

        if (isset($options['on_stats'])) {
            ($options['on_stats'])($stats);
        }

        $this->stats[] = $stats;

        return Create::promiseFor($script);
    }

    public function statsCount(): int
    {
        return count($this->stats);
    }

    /** @return list<string> */
    public function requestedUris(): array
    {
        return array_map(
            fn (RequestInterface $request) => (string) $request->getUri(),
            $this->requests,
        );
    }

    protected function normalize(string $uri): string
    {
        return explode('#', $uri, 2)[0];
    }
}
