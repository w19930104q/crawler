<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Spatie\Crawler\CrawlResponse;
use Spatie\Crawler\Crawler;
use Spatie\Crawler\Test\TestClasses\ScriptedTransferHandler;
use Spatie\Crawler\Throttlers\Throttle;
use Spatie\Crawler\TransferEvent;
use Spatie\Crawler\TransferHistory;

/**
 * Build a crawler on top of a fresh HandlerStack wrapping the scripted,
 * fully offline handler. Robots are ignored so only page requests run.
 */
function trackingCrawler(ScriptedTransferHandler $handler, string $baseUrl = 'http://crawler.test/'): Crawler
{
    $stack = HandlerStack::create($handler);

    return Crawler::create($baseUrl, ['handler' => $stack])
        ->ignoreRobots()
        ->concurrency(1)
        ->depth(0);
}

it('is disabled by default and exposes no tracking surface', function () {
    $handler = new ScriptedTransferHandler([
        'http://crawler.test/' => new Response(200, [], 'home'),
    ]);

    $crawler = trackingCrawler($handler);

    expect($crawler->isTrackingTransfers())->toBeFalse();
    expect($crawler->getTransferHistory('http://crawler.test/'))->toBe([]);
    expect($crawler->getTransferHistoryStore())->toBeNull();

    $crawler->start();

    // No task header is attached while tracking is off.
    expect($handler->requests[0]->hasHeader(TransferHistory::TASK_ID_HEADER))->toBeFalse();
    expect($crawler->getTransferHistory('http://crawler.test/'))->toBe([]);
});

it('can be turned off again after being enabled, restoring the default behavior', function () {
    $handler = new ScriptedTransferHandler([
        'http://crawler.test/' => new Response(200, [], 'home'),
    ]);

    $crawler = trackingCrawler($handler)
        ->trackTransfers()
        ->doNotTrackTransfers();

    expect($crawler->isTrackingTransfers())->toBeFalse();
    expect($crawler->getTransferHistoryStore())->toBeNull();

    $crawler->start();

    expect($handler->requests[0]->hasHeader(TransferHistory::TASK_ID_HEADER))->toBeFalse();
    expect($crawler->getTransferHistory('http://crawler.test/'))->toBe([]);
});

it('can be enabled fluently and records request uri, effective uri, status and duration', function () {
    $handler = new ScriptedTransferHandler([
        'http://crawler.test/' => new Response(200, ['Content-Type' => 'text/html'], 'home'),
    ]);

    $crawler = trackingCrawler($handler)->trackTransfers();

    expect($crawler->isTrackingTransfers())->toBeTrue();

    $crawler->start();

    $events = $crawler->getTransferHistory('http://crawler.test/');

    expect($events)->toHaveCount(1);
    expect($events[0])->toBeInstanceOf(TransferEvent::class);
    expect($events[0]->taskId())->toBe('http://crawler.test/');
    expect($events[0]->requestUri())->toBe('http://crawler.test/');
    expect($events[0]->effectiveUri())->toBe('http://crawler.test/');
    expect($events[0]->statusCode())->toBe(200);
    expect($events[0]->networkError())->toBeNull();
    expect($events[0]->wasSuccessful())->toBeTrue();
    expect($events[0]->wasRedirected())->toBeFalse();
    expect($events[0]->transferTimeInMs())->toBe(12.5);
    expect($events[0]->sequence())->toBe(0);

    // The stamped header is really on the wire.
    expect($handler->requests[0]->getHeaderLine(TransferHistory::TASK_ID_HEADER))
        ->toBe(rawurlencode('http://crawler.test/'));
});

it('records every hop of a multi redirect chain in order', function () {
    $handler = new ScriptedTransferHandler([
        'http://crawler.test/start' => new Response(302, ['Location' => 'http://crawler.test/middle']),
        'http://crawler.test/middle' => new Response(301, ['Location' => 'http://crawler.test/final']),
        'http://crawler.test/final' => new Response(200, ['Content-Type' => 'text/html'], 'done'),
    ]);

    $crawler = trackingCrawler($handler, 'http://crawler.test/start')->trackTransfers();
    $crawler->start();

    $events = $crawler->getTransferHistory('http://crawler.test/start');

    expect($events)->toHaveCount(3);

    expect($events[0]->requestUri())->toBe('http://crawler.test/start');
    expect($events[0]->statusCode())->toBe(302);
    expect($events[0]->effectiveUri())->toBe('http://crawler.test/start');
    expect($events[0]->wasRedirected())->toBeFalse();

    expect($events[1]->requestUri())->toBe('http://crawler.test/middle');
    expect($events[1]->statusCode())->toBe(301);
    expect($events[1]->taskId())->toBe('http://crawler.test/start');

    expect($events[2]->requestUri())->toBe('http://crawler.test/final');
    expect($events[2]->effectiveUri())->toBe('http://crawler.test/final');
    expect($events[2]->statusCode())->toBe(200);
    expect($events[2]->wasSuccessful())->toBeTrue();

    // Ordering metadata is strictly increasing.
    expect(array_map(fn (TransferEvent $e) => $e->sequence(), $events))
        ->toBe([0, 1, 2]);

    // Every hop carried the same task stamp, including redirected requests.
    expect($handler->requests)->toHaveCount(3);
    foreach ($handler->requests as $request) {
        expect($request->getHeaderLine(TransferHistory::TASK_ID_HEADER))
            ->toBe(rawurlencode('http://crawler.test/start'));
    }
});

it('records retries after redirects and keeps the last transfer for observers', function () {
    $handler = new ScriptedTransferHandler([
        'http://crawler.test/start' => new Response(302, ['Location' => 'http://crawler.test/flaky']),
        // First two attempts fail to connect, the third succeeds.
        'http://crawler.test/flaky' => [
            new ConnectException('Connection refused', new Request('GET', 'http://crawler.test/flaky')),
            new ConnectException('Connection refused', new Request('GET', 'http://crawler.test/flaky')),
            new Response(200, ['Content-Type' => 'text/html'], 'ok'),
        ],
    ]);

    $observedStats = null;

    $crawler = trackingCrawler($handler, 'http://crawler.test/start')
        ->retry(2, 0)
        ->trackTransfers()
        ->onCrawled(function (string $url, CrawlResponse $response) use (&$observedStats) {
            $observedStats = $response->transferStats();
        });

    $crawler->start();

    $events = $crawler->getTransferHistory('http://crawler.test/start');

    // 1 redirect + 2 failed attempts + 1 successful attempt.
    expect($events)->toHaveCount(4);

    expect($events[0]->statusCode())->toBe(302);

    expect($events[1]->statusCode())->toBeNull();
    expect($events[1]->networkError())->toBe('Connection refused');
    expect($events[1]->wasSuccessful())->toBeFalse();
    expect($events[1]->requestUri())->toBe('http://crawler.test/flaky');

    expect($events[2]->networkError())->toBe('Connection refused');
    expect($events[2]->statusCode())->toBeNull();

    expect($events[3]->statusCode())->toBe(200);
    expect($events[3]->networkError())->toBeNull();

    // The success observer's TransferStatistics come from the last transfer.
    expect($observedStats)->not->toBeNull();
    expect($observedStats->effectiveUri())->toBe('http://crawler.test/flaky');
});

it('keeps the full history reachable when the terminal transfer fails', function () {
    $handler = new ScriptedTransferHandler([
        'http://crawler.test/doomed' => new ConnectException(
            'Network is unreachable',
            new Request('GET', 'http://crawler.test/doomed'),
        ),
    ]);

    $failedWithStats = null;

    $crawler = trackingCrawler($handler, 'http://crawler.test/doomed')
        ->retry(1, 0)
        ->trackTransfers()
        ->onFailed(function (
            string $url,
            $exception,
            $progress,
            $foundOn,
            $linkText,
            $type,
            $transferStats,
        ) use (&$failedWithStats) {
            $failedWithStats = $transferStats;
        });

    $crawler->start();

    $events = $crawler->getTransferHistory('http://crawler.test/doomed');

    expect($events)->toHaveCount(2);
    foreach ($events as $event) {
        expect($event->statusCode())->toBeNull();
        expect($event->networkError())->toBe('Network is unreachable');
        expect($event->wasSuccessful())->toBeFalse();
    }

    // Failure observer still receives stats from the last transfer.
    expect($failedWithStats)->not->toBeNull();
    expect($failedWithStats->effectiveUri())->toBe('http://crawler.test/doomed');
});

it('attributes concurrent tasks landing on the same endpoint to their own task', function () {
    // Two queue tasks both end up on /shared after their own redirect.
    $handler = new ScriptedTransferHandler([
        'http://crawler.test/a' => new Response(302, ['Location' => 'http://crawler.test/shared']),
        'http://crawler.test/b' => new Response(302, ['Location' => 'http://crawler.test/shared']),
        'http://crawler.test/shared' => new Response(200, ['Content-Type' => 'text/html'], 'shared'),
    ]);

    $stack = HandlerStack::create($handler);

    $crawler = Crawler::create('http://crawler.test/a', ['handler' => $stack])
        ->ignoreRobots()
        ->concurrency(2)
        ->depth(1)
        ->trackTransfers();

    // Seed the second task explicitly so both are queued before crawling.
    $crawler->getCrawlQueue()->add(new \Spatie\Crawler\CrawlUrl('http://crawler.test/b'));

    $crawler->start();

    $eventsA = $crawler->getTransferHistory('http://crawler.test/a');
    $eventsB = $crawler->getTransferHistory('http://crawler.test/b');

    expect($eventsA)->toHaveCount(2);
    expect($eventsB)->toHaveCount(2);

    foreach ($eventsA as $event) {
        expect($event->taskId())->toBe('http://crawler.test/a');
    }
    foreach ($eventsB as $event) {
        expect($event->taskId())->toBe('http://crawler.test/b');
    }

    // Both histories end on the shared endpoint but stay independent.
    expect($eventsA[1]->effectiveUri())->toBe('http://crawler.test/shared');
    expect($eventsB[1]->effectiveUri())->toBe('http://crawler.test/shared');

    $all = $crawler->getTransferHistoryStore()->all();
    expect($all)->toHaveCount(4);

    $sequences = array_map(fn (TransferEvent $e) => $e->sequence(), $all);
    expect($sequences)->toBe([0, 1, 2, 3]);
});

it('does not mix robots.txt transfers into page history', function () {
    $handler = new ScriptedTransferHandler([
        'http://crawler.test/robots.txt' => new Response(200, [], "User-agent: *\nDisallow:\n"),
        'http://crawler.test/' => new Response(200, ['Content-Type' => 'text/html'], 'home'),
    ]);

    $crawler = trackingCrawler($handler)->respectRobots()->trackTransfers();
    $crawler->start();

    // The robots request physically happened and produced stats...
    expect($handler->requestedUris())->toContain('http://crawler.test/robots.txt');
    expect($handler->statsCount())->toBe(2);

    // ...but it carries no task stamp and never enters the page history.
    $robotsRequest = $handler->requests[0];
    expect($robotsRequest->getUri()->__toString())->toBe('http://crawler.test/robots.txt');
    expect($robotsRequest->hasHeader(TransferHistory::TASK_ID_HEADER))->toBeFalse();

    $events = $crawler->getTransferHistory('http://crawler.test/');
    expect($events)->toHaveCount(1);
    expect($events[0]->requestUri())->toBe('http://crawler.test/');
});

it('returns snapshots that later transfers cannot mutate', function () {
    $handler = new ScriptedTransferHandler([
        'http://crawler.test/' => new Response(200, ['Content-Type' => 'text/html'], 'home'),
        'http://crawler.test/other' => new Response(200, ['Content-Type' => 'text/html'], 'other'),
    ]);

    $crawler = trackingCrawler($handler)->trackTransfers();
    $crawler->getCrawlQueue()->add(new \Spatie\Crawler\CrawlUrl('http://crawler.test/other'));
    $crawler->start();

    $snapshot = $crawler->getTransferHistory('http://crawler.test/');
    $storeSnapshot = $crawler->getTransferHistoryStore()->all();

    expect($snapshot)->toHaveCount(1);
    expect($storeSnapshot)->toHaveCount(2);

    // Mutating the returned arrays must not touch the internal state.
    $snapshot[] = 'tampered';
    $storeSnapshot[] = 'tampered';

    expect($crawler->getTransferHistory('http://crawler.test/'))->toHaveCount(1);
    expect($crawler->getTransferHistoryStore()->all())->toHaveCount(2);
});

it('clears all tracking when start runs again', function () {
    $handler = new ScriptedTransferHandler([
        'http://crawler.test/' => new Response(200, ['Content-Type' => 'text/html'], 'home'),
    ]);

    $crawler = trackingCrawler($handler)->trackTransfers();

    $crawler->start();
    expect($crawler->getTransferHistory('http://crawler.test/'))->toHaveCount(1);

    // A second run on a fresh queue leaves no trace of the first.
    $crawler->crawlQueue(new \Spatie\Crawler\CrawlQueues\ArrayCrawlQueue);
    $crawler->start();

    expect($crawler->getTransferHistory('http://crawler.test/'))->toHaveCount(1);
    expect($crawler->getTransferHistoryStore()->all())->toHaveCount(1);
    expect($handler->statsCount())->toBe(2); // two runs, one transfer each
});

it('keeps two crawlers on a shared HandlerStack isolated from each other and from direct requests', function () {
    $handler = new ScriptedTransferHandler([
        'http://shared.test/one' => new Response(200, ['Content-Type' => 'text/html'], 'one'),
        'http://shared.test/two' => new Response(200, ['Content-Type' => 'text/html'], 'two'),
    ]);

    $sharedStack = HandlerStack::create($handler);

    $crawlerOne = Crawler::create('http://shared.test/one', ['handler' => $sharedStack])
        ->ignoreRobots()->depth(0)->concurrency(1)->trackTransfers();

    $crawlerTwo = Crawler::create('http://shared.test/two', ['handler' => $sharedStack])
        ->ignoreRobots()->depth(0)->concurrency(1)->trackTransfers();

    // An external client built on the very same stack issues a direct request.
    $externalClient = new Client(['handler' => $sharedStack]);
    $externalClient->get('http://shared.test/one');

    $crawlerOne->start();
    $crawlerTwo->start();

    expect($crawlerOne->getTransferHistory('http://shared.test/one'))->toHaveCount(1);
    expect($crawlerOne->getTransferHistory('http://shared.test/two'))->toBe([]);

    expect($crawlerTwo->getTransferHistory('http://shared.test/two'))->toHaveCount(1);
    expect($crawlerTwo->getTransferHistory('http://shared.test/one'))->toBe([]);

    // The external request had no task stamp.
    $externalRequest = $handler->requests[0];
    expect($externalRequest->hasHeader(TransferHistory::TASK_ID_HEADER))->toBeFalse();
});

it('invokes on_stats exactly once per actual transfer and never double counts the throttle', function () {
    $handler = new ScriptedTransferHandler([
        'http://crawler.test/flaky' => [
            new ConnectException('boom', new Request('GET', 'http://crawler.test/flaky')),
            new Response(200, ['Content-Type' => 'text/html'], 'ok'),
        ],
    ]);

    $recordedTimes = [];
    $throttle = new class($recordedTimes) implements Throttle
    {
        public function __construct(protected array &$recordedTimes) {}

        public function sleep(): void {}

        public function recordResponseTime(float $seconds): void
        {
            $this->recordedTimes[] = $seconds;
        }
    };

    $crawler = trackingCrawler($handler, 'http://crawler.test/flaky')
        ->retry(1, 0)
        ->throttle($throttle)
        ->trackTransfers();

    $crawler->start();

    // One stats callback per physical transfer: 1 failure + 1 success.
    expect($handler->statsCount())->toBe(2);
    expect(count($recordedTimes))->toBe(2);
    expect($crawler->getTransferHistory('http://crawler.test/flaky'))->toHaveCount(2);
});

it('behaves identically with tracking disabled: throttle still records robots and pages', function () {
    $handler = new ScriptedTransferHandler([
        'http://crawler.test/robots.txt' => new Response(200, []),
        'http://crawler.test/' => new Response(200, ['Content-Type' => 'text/html'], 'home'),
    ]);

    $recordedTimes = [];
    $throttle = new class($recordedTimes) implements Throttle
    {
        public function __construct(protected array &$recordedTimes) {}

        public function sleep(): void {}

        public function recordResponseTime(float $seconds): void
        {
            $this->recordedTimes[] = $seconds;
        }
    };

    $crawler = trackingCrawler($handler)->respectRobots()->throttle($throttle);
    $crawler->start();

    expect($crawler->isTrackingTransfers())->toBeFalse();
    // Robots + page both feed the throttle in the default mode.
    expect(count($recordedTimes))->toBe(2);
});

it('records retries triggered by 5xx responses and succeeds on the last attempt', function () {
    $handler = new ScriptedTransferHandler([
        'http://crawler.test/flaky' => [
            new Response(503, [], 'service unavailable'),
            new Response(500, [], 'server error'),
            new Response(200, ['Content-Type' => 'text/html'], 'ok'),
        ],
    ]);

    $crawler = trackingCrawler($handler, 'http://crawler.test/flaky')
        ->retry(2, 0)
        ->trackTransfers();

    $crawler->start();

    $events = $crawler->getTransferHistory('http://crawler.test/flaky');

    expect($events)->toHaveCount(3);
    expect($events[0]->statusCode())->toBe(503);
    expect($events[0]->wasSuccessful())->toBeTrue(); // a response was received
    expect($events[1]->statusCode())->toBe(500);
    expect($events[2]->statusCode())->toBe(200);

    // All attempts belong to the same task and stay ordered.
    foreach ($events as $index => $event) {
        expect($event->taskId())->toBe('http://crawler.test/flaky');
        expect($event->sequence())->toBe($index);
    }

    // on_stats fires once per physical attempt.
    expect($handler->statsCount())->toBe(3);
});

it('keeps the crawl working through the fake handler with tracking enabled', function () {
    $crawled = [];

    Crawler::create('https://example.com')
        ->fake([
            'https://example.com' => '<html><body><a href="/page">Page</a></body></html>',
            'https://example.com/page' => 'Page content',
        ])
        ->ignoreRobots()
        ->trackTransfers()
        ->onCrawled(function (string $url) use (&$crawled) {
            $crawled[] = $url;
        })
        ->start();

    // The fake handler never invokes on_stats, so history stays empty while
    // crawling itself is unaffected.
    expect($crawled)->toContain('https://example.com/');
    expect($crawled)->toContain('https://example.com/page');
});

it('does not stamp or track requests when a javascript renderer is active', function () {
    $renderer = new class implements \Spatie\Crawler\JavaScriptRenderers\JavaScriptRenderer
    {
        public function getRenderedHtml(string $url): string
        {
            return '<html><body>rendered</body></html>';
        }
    };

    $handler = new ScriptedTransferHandler([
        'http://crawler.test/' => new Response(200, ['Content-Type' => 'text/html'], 'home'),
    ]);

    $crawler = trackingCrawler($handler)
        ->executeJavaScript($renderer)
        ->trackTransfers();

    $crawler->start();

    // The Guzzle request still happens, but it carries no task stamp and no
    // history is recorded because rendering is outside the HTTP collector.
    expect($handler->requests[0]->hasHeader(TransferHistory::TASK_ID_HEADER))->toBeFalse();
    expect($crawler->getTransferHistory('http://crawler.test/'))->toBe([]);
    expect($crawler->isTrackingTransfers())->toBeTrue();
});
