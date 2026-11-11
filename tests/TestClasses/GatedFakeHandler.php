<?php

namespace Spatie\Crawler\Test\TestClasses;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RedirectMiddleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Spatie\Crawler\CrawlResponse;

/**
 * A fake Guzzle handler that can hold responses until a configured completion
 * order is reached. The first gated request that is awaited releases every
 * currently pending gated response in the configured order.
 *
 * Every request receives its own response object and body stream, so a response
 * can be returned for a redirect and for a regular request without one of them
 * consuming the other's body.
 */
class GatedFakeHandler
{
    protected const MAX_REDIRECTS = 5;

    /** @var array<string, array{status: int, headers: array<string, array<int, string>>, body: string}> */
    protected array $responseSpecs = [];

    /** @var array<string, array{0: Promise, 1: ResponseInterface}> */
    protected array $pending = [];

    /** @var array<int, string> */
    protected array $completionOrder = [];

    /** @var array<int, string> */
    public array $requestedUrls = [];

    /** @param array<string, string|CrawlResponse> $fakes */
    public function __construct(array $fakes, array $completionOrder = [])
    {
        foreach ($fakes as $url => $content) {
            $response = $content instanceof CrawlResponse
                ? $content->toPsrResponse()
                : new Response(200, ['Content-Type' => 'text/html'], $content);

            $this->responseSpecs[$this->normalizeUrl($url)] = [
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body' => (string) $response->getBody(),
            ];
        }

        $this->completionOrder = $completionOrder;
    }

    public function __invoke(RequestInterface $request, array $options = []): PromiseInterface
    {
        $url = (string) $request->getUri();
        $this->requestedUrls[] = $url;

        $response = $this->createResponse($url);

        if (! in_array($url, $this->completionOrder, true)) {
            return Create::promiseFor($response);
        }

        $promise = new Promise(function () use ($url): void {
            $this->releasePendingResponses($url);
        });

        $this->pending[$url] = [$promise, $response];

        return $promise;
    }

    protected function releasePendingResponses(string $currentUrl): void
    {
        foreach ($this->completionOrder as $url) {
            if (! isset($this->pending[$url])) {
                continue;
            }

            [$promise, $response] = $this->pending[$url];
            unset($this->pending[$url]);

            $promise->resolve($response);

            // Run the fulfillment callbacks immediately so that URLs
            // discovered by this response are dispatched before the next
            // response in the configured order is released.
            Utils::queue()->run();
        }

        // Requests discovered while releasing are not part of the configured
        // order yet. Release them in registration order so the crawl can end.
        while ($this->pending !== []) {
            $url = array_key_first($this->pending);
            [$promise, $response] = $this->pending[$url];
            unset($this->pending[$url]);

            $promise->resolve($response);
            Utils::queue()->run();
        }
    }

    protected function createResponse(string $url): ResponseInterface
    {
        $response = $this->findResponse($url);
        $redirectHistory = [];

        for ($i = 0; $i < self::MAX_REDIRECTS; $i++) {
            $statusCode = $response->getStatusCode();

            if ($statusCode < 300 || $statusCode >= 400) {
                break;
            }

            $location = $response->getHeaderLine('Location');

            if ($location === '') {
                break;
            }

            $redirectHistory[] = $location;
            $response = $this->findResponse($location);
        }

        if ($redirectHistory !== []) {
            $response = $response->withHeader(RedirectMiddleware::HISTORY_HEADER, $redirectHistory);
        }

        return $response;
    }

    protected function findResponse(string $url): ResponseInterface
    {
        $normalizedUrl = $this->normalizeUrl($url);

        if (isset($this->responseSpecs[$normalizedUrl])) {
            return $this->buildResponse($this->responseSpecs[$normalizedUrl]);
        }

        $withoutSlash = rtrim($normalizedUrl, '/');
        if (isset($this->responseSpecs[$withoutSlash])) {
            return $this->buildResponse($this->responseSpecs[$withoutSlash]);
        }

        $withSlash = $withoutSlash.'/';
        if (isset($this->responseSpecs[$withSlash])) {
            return $this->buildResponse($this->responseSpecs[$withSlash]);
        }

        if (str_ends_with($normalizedUrl, '/robots.txt')) {
            return new Response(404);
        }

        return new Response(404, [], 'Not Found');
    }

    /**
     * @param array{status: int, headers: array<string, array<int, string>>, body: string} $spec
     */
    protected function buildResponse(array $spec): ResponseInterface
    {
        return new Response($spec['status'], $spec['headers'], $spec['body']);
    }

    protected function normalizeUrl(string $url): string
    {
        return explode('#', $url, 2)[0];
    }
}
