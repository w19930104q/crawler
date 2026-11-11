<?php

use Spatie\Crawler\Crawler;
use Spatie\Crawler\CrawlQueues\CrawlQueue;
use Spatie\Crawler\CrawlResponse;
use Spatie\Crawler\CrawlUrl;
use Spatie\Crawler\Exceptions\UnsupportedShortestDiscoveryDepthMode;
use Spatie\Crawler\JavaScriptRenderers\JavaScriptRenderer;
use Spatie\Crawler\Test\TestClasses\GatedFakeHandler;

/**
 * @return array{
 *     urls: array<int, string>,
 *     queueDepths: array<string, int>,
 *     crawledDepths: array<string, int>,
 *     requestCount: int,
 *     willCrawlCount: int,
 *     crawledCount: int
 * }
 */
function shortestDepthScenario(
    array $fakes,
    array $completionOrder,
    int $maximumDepth,
    string $startUrl = 'https://example.com/',
    bool $useShortestDiscoveryDepth = true,
    int $concurrency = 2,
): array {
    $handler = new GatedFakeHandler($fakes, $completionOrder);

    $willCrawl = [];
    $crawled = [];
    $crawledDepths = [];

    $crawler = Crawler::create($startUrl, ['handler' => $handler, 'http_errors' => false])
        ->depth($maximumDepth)
        ->concurrency($concurrency)
        ->ignoreRobots()
        ->shortestDiscoveryDepth($useShortestDiscoveryDepth)
        ->onWillCrawl(function (string $url) use (&$willCrawl) {
            $willCrawl[] = $url;
        })
        ->onCrawled(function (string $url, CrawlResponse $response) use (&$crawled, &$crawledDepths) {
            $crawled[] = $url;
            $crawledDepths[$url] = $response->depth();
        });

    $crawler->start();

    $queue = $crawler->getCrawlQueue();
    $queueDepths = [];

    foreach (array_unique(array_merge($willCrawl, $crawled)) as $url) {
        $crawlUrl = $queue->getUrl($url);

        if ($crawlUrl !== null) {
            $queueDepths[$url] = $crawlUrl->depth;
        }
    }

    sort($crawled);
    ksort($queueDepths);
    ksort($crawledDepths);

    return [
        'urls' => array_values(array_unique($crawled)),
        'queueDepths' => $queueDepths,
        'crawledDepths' => $crawledDepths,
        'requestCount' => count($handler->requestedUrls),
        'willCrawlCount' => count($willCrawl),
        'crawledCount' => count($crawled),
    ];
}

it('crawls the same urls and shortest depths regardless of completion order', function () {
    $fakes = [
        'https://example.com/' => '<a href="/long">Long</a><a href="/short">Short</a>',
        'https://example.com/long' => '<a href="/long-hop">Long hop</a>',
        'https://example.com/long-hop' => '<a href="/target">Target</a>',
        'https://example.com/short' => '<a href="/target">Target</a>',
        'https://example.com/target' => '<a href="/successor">Successor</a>',
        'https://example.com/successor' => 'Done',
    ];

    // The long path reaches the target at depth 3 and crawls it before the
    // short path is allowed to complete.
    $longPathFirst = shortestDepthScenario($fakes, [
        'https://example.com/long',
        'https://example.com/long-hop',
        'https://example.com/target',
        'https://example.com/short',
        'https://example.com/successor',
    ], maximumDepth: 3);

    // The short path reaches the target at depth 2 first. The long path still
    // reaches it, but cannot make it any deeper.
    $shortPathFirst = shortestDepthScenario($fakes, [
        'https://example.com/short',
        'https://example.com/target',
        'https://example.com/successor',
        'https://example.com/long',
        'https://example.com/long-hop',
    ], maximumDepth: 3);

    $expectedUrls = [
        'https://example.com/',
        'https://example.com/long',
        'https://example.com/long-hop',
        'https://example.com/short',
        'https://example.com/successor',
        'https://example.com/target',
    ];

    $expectedDepths = [
        'https://example.com/' => 0,
        'https://example.com/long' => 1,
        'https://example.com/long-hop' => 2,
        'https://example.com/short' => 1,
        'https://example.com/successor' => 3,
        'https://example.com/target' => 2,
    ];

    foreach ([$longPathFirst, $shortPathFirst] as $result) {
        expect($result['urls'])->toBe($expectedUrls);
        expect($result['queueDepths'])->toBe($expectedDepths);

        expect($result['requestCount'])->toBe(6);
        expect($result['willCrawlCount'])->toBe(6);
        expect($result['crawledCount'])->toBe(6);
    }

    // In the long-first run the target was really crawled at depth 3 before
    // being relaxed. Observers keep that historical detail.
    expect($longPathFirst['crawledDepths']['https://example.com/target'])->toBe(3);
    expect($shortPathFirst['crawledDepths']['https://example.com/target'])->toBe(2);
});

it('keeps the old first-discovery depth behavior when the mode is disabled', function () {
    $fakes = [
        'https://example.com/' => '<a href="/long">Long</a><a href="/short">Short</a>',
        'https://example.com/long' => '<a href="/long-hop">Long hop</a>',
        'https://example.com/long-hop' => '<a href="/target">Target</a>',
        'https://example.com/short' => '<a href="/target">Target</a>',
        'https://example.com/target' => '<a href="/successor">Successor</a>',
    ];

    $result = shortestDepthScenario($fakes, [
        'https://example.com/long',
        'https://example.com/long-hop',
        'https://example.com/target',
        'https://example.com/short',
    ], maximumDepth: 3, useShortestDiscoveryDepth: false);

    expect($result['urls'])->toBe([
        'https://example.com/',
        'https://example.com/long',
        'https://example.com/long-hop',
        'https://example.com/short',
        'https://example.com/target',
    ]);

    expect($result['queueDepths']['https://example.com/target'])->toBe(3);
    expect($result['requestCount'])->toBe(5);
});

it('applies multiple depth decreases to already crawled pages and their successors', function () {
    $fakes = [
        'https://example.com/' => '<a href="/chain/1">Chain</a><a href="/branch/a">A</a><a href="/branch/c">C</a>',
        'https://example.com/chain/1' => '<a href="/chain/2">Next</a>',
        'https://example.com/chain/2' => '<a href="/chain/3">Next</a>',
        'https://example.com/chain/3' => '<a href="/target">Target</a>',
        'https://example.com/branch/a' => '<a href="/branch/b">B</a>',
        'https://example.com/branch/b' => '<a href="/target">Target</a>',
        'https://example.com/branch/c' => '<a href="/target">Target</a>',
        'https://example.com/target' => '<a href="/successor">Successor</a>',
        'https://example.com/successor' => 'Done',
    ];

    $result = shortestDepthScenario($fakes, [
        // /branch/a discovers /branch/b before the long chain reaches the
        // target. The target is first crawled at depth 4, lowered to 3 by
        // /branch/b and lowered once more to 2 by /branch/c.
        'https://example.com/branch/a',
        'https://example.com/chain/1',
        'https://example.com/chain/2',
        'https://example.com/chain/3',
        'https://example.com/target',
        'https://example.com/branch/b',
        'https://example.com/successor',
        'https://example.com/branch/c',
    ], maximumDepth: 4, concurrency: 3);

    expect($result['urls'])->toBe([
        'https://example.com/',
        'https://example.com/branch/a',
        'https://example.com/branch/b',
        'https://example.com/branch/c',
        'https://example.com/chain/1',
        'https://example.com/chain/2',
        'https://example.com/chain/3',
        'https://example.com/successor',
        'https://example.com/target',
    ]);

    expect($result['queueDepths'])->toBe([
        'https://example.com/' => 0,
        'https://example.com/branch/a' => 1,
        'https://example.com/branch/b' => 2,
        'https://example.com/branch/c' => 1,
        'https://example.com/chain/1' => 1,
        'https://example.com/chain/2' => 2,
        'https://example.com/chain/3' => 3,
        'https://example.com/successor' => 3,
        'https://example.com/target' => 2,
    ]);

    // The target was really crawled at depth 4 and lowered twice afterwards.
    // The successor entered the budget through the first decrease (depth 4)
    // and was lowered to depth 3 before it was crawled. Neither was crawled
    // again.
    expect($result['crawledDepths']['https://example.com/target'])->toBe(4);
    expect($result['crawledDepths']['https://example.com/successor'])->toBe(3);
    expect($result['requestCount'])->toBe(9);
    expect($result['willCrawlCount'])->toBe(9);
    expect($result['crawledCount'])->toBe(9);
});

it('respects a depth of zero', function () {
    $result = shortestDepthScenario([
        'https://example.com/' => '<a href="/a">A</a><a href="/b">B</a>',
        'https://example.com/a' => 'A',
        'https://example.com/b' => 'B',
    ], [], maximumDepth: 0);

    expect($result['urls'])->toBe(['https://example.com/']);
    expect($result['queueDepths'])->toBe(['https://example.com/' => 0]);
    expect($result['requestCount'])->toBe(1);
});

it('adds a url that enters the depth budget through a shorter path', function () {
    $fakes = [
        'https://example.com/' => '<a href="/long/1">Long</a><a href="/short">Short</a>',
        'https://example.com/long/1' => '<a href="/long/2">Next</a>',
        'https://example.com/long/2' => '<a href="/target">Target</a>',
        'https://example.com/short' => '<a href="/target">Target</a>',
        'https://example.com/target' => '<a href="/leaf">Leaf</a>',
        'https://example.com/leaf' => 'Leaf',
    ];

    $longFirst = shortestDepthScenario($fakes, [
        'https://example.com/long/1',
        'https://example.com/long/2',
        'https://example.com/short',
        'https://example.com/target',
        'https://example.com/leaf',
    ], maximumDepth: 2);

    $shortFirst = shortestDepthScenario($fakes, [
        'https://example.com/short',
        'https://example.com/target',
        'https://example.com/leaf',
        'https://example.com/long/1',
        'https://example.com/long/2',
    ], maximumDepth: 2);

    $expectedUrls = [
        'https://example.com/',
        'https://example.com/long/1',
        'https://example.com/long/2',
        'https://example.com/short',
        'https://example.com/target',
    ];

    $expectedDepths = [
        'https://example.com/' => 0,
        'https://example.com/long/1' => 1,
        'https://example.com/long/2' => 2,
        'https://example.com/short' => 1,
        'https://example.com/target' => 2,
    ];

    foreach ([$longFirst, $shortFirst] as $result) {
        expect($result['urls'])->toBe($expectedUrls);
        expect($result['queueDepths'])->toBe($expectedDepths);
        expect($result['requestCount'])->toBe(5);
    }

    // /leaf would be at depth 3, so it never enters the queue.
    expect($longFirst['queueDepths'])->not->toHaveKey('https://example.com/leaf');
    expect($shortFirst['queueDepths'])->not->toHaveKey('https://example.com/leaf');
});

it('does not add depth for redirects', function () {
    $result = shortestDepthScenario([
        'https://example.com/redirect-root' => CrawlResponse::fake('', 301, [
            'Location' => 'https://example.com/home',
        ]),
        'https://example.com/home' => '<a href="/child">Child</a>',
        'https://example.com/child' => 'Child',
    ], [], maximumDepth: 1, startUrl: 'https://example.com/redirect-root');

    expect($result['urls'])->toBe([
        'https://example.com/child',
        'https://example.com/redirect-root',
    ]);

    expect($result['queueDepths'])->toBe([
        'https://example.com/child' => 1,
        'https://example.com/redirect-root' => 0,
    ]);

    // The redirect target is fetched as part of the entry request, not
    // scheduled as an extra crawl.
    expect($result['requestCount'])->toBe(2);
});

it('lets a redirect lower the depth of an already crawled target', function () {
    $fakes = [
        'https://example.com/' => '<a href="/deep">Deep</a><a href="/redirect-entry">Redirect</a>',
        'https://example.com/deep' => '<a href="/target">Target</a>',
        'https://example.com/redirect-entry' => CrawlResponse::fake('', 301, [
            'Location' => 'https://example.com/target',
        ]),
        'https://example.com/target' => '<a href="/leaf">Leaf</a>',
        'https://example.com/leaf' => 'Leaf',
    ];

    $longFirst = shortestDepthScenario($fakes, [
        'https://example.com/deep',
        'https://example.com/target',
        'https://example.com/redirect-entry',
        'https://example.com/leaf',
    ], maximumDepth: 2);

    $redirectFirst = shortestDepthScenario($fakes, [
        'https://example.com/redirect-entry',
        'https://example.com/leaf',
        'https://example.com/deep',
        'https://example.com/target',
    ], maximumDepth: 2);

    $expectedUrls = [
        'https://example.com/',
        'https://example.com/deep',
        'https://example.com/leaf',
        'https://example.com/redirect-entry',
        'https://example.com/target',
    ];

    $expectedDepths = [
        'https://example.com/' => 0,
        'https://example.com/deep' => 1,
        'https://example.com/leaf' => 2,
        'https://example.com/redirect-entry' => 1,
        'https://example.com/target' => 1,
    ];

    foreach ([$longFirst, $redirectFirst] as $result) {
        expect($result['urls'])->toBe($expectedUrls);
        expect($result['queueDepths'])->toBe($expectedDepths);
        expect($result['requestCount'])->toBe(5);
    }
});

it('lets a redirect lower the depth of an already crawled empty page', function () {
    $fakes = [
        'https://example.com/' => '<a href="/deep">Deep</a><a href="/redirect-entry">Redirect</a>',
        'https://example.com/deep' => '<a href="/target">Target</a>',
        'https://example.com/redirect-entry' => CrawlResponse::fake('', 301, [
            'Location' => 'https://example.com/target',
        ]),
        'https://example.com/target' => '',
    ];

    $result = shortestDepthScenario($fakes, [
        'https://example.com/deep',
        'https://example.com/target',
        'https://example.com/redirect-entry',
    ], maximumDepth: 2);

    expect($result['urls'])->toBe([
        'https://example.com/',
        'https://example.com/deep',
        'https://example.com/redirect-entry',
        'https://example.com/target',
    ]);

    expect($result['queueDepths']['https://example.com/target'])->toBe(1);
    expect($result['crawledDepths']['https://example.com/target'])->toBe(2);
    expect($result['requestCount'])->toBe(4);
});

it('preserves robots, nofollow and scope filtering', function () {
    $crawled = [];

    Crawler::create('https://example.com')
        ->fake([
            'https://example.com/robots.txt' => "User-agent: *\nDisallow: /blocked\n",
            'https://example.com/' => '<a href="/allowed">Allowed</a><a href="/blocked">Blocked</a><a href="/nofollow" rel="nofollow">Nofollow</a><a href="https://external.example.org/">External</a>',
            'https://example.com/allowed' => 'Allowed',
            'https://example.com/blocked' => 'Blocked',
            'https://example.com/nofollow' => 'Nofollow',
            'https://external.example.org/' => 'External',
        ])
        ->shortestDiscoveryDepth()
        ->internalOnly()
        ->depth(2)
        ->onCrawled(function (string $url) use (&$crawled) {
            $crawled[] = $url;
        })
        ->start();

    sort($crawled);

    expect($crawled)->toBe([
        'https://example.com/',
        'https://example.com/allowed',
    ]);
});

it('terminates on cycles without recrawling urls', function () {
    $crawled = [];

    $crawler = Crawler::create('https://example.com')
        ->fake([
            'https://example.com/' => '<a href="/a">A</a>',
            'https://example.com/a' => '<a href="/b">B</a>',
            'https://example.com/b' => '<a href="/a">A</a><a href="/">Home</a>',
        ])
        ->ignoreRobots()
        ->shortestDiscoveryDepth()
        ->depth(3)
        ->onCrawled(function (string $url) use (&$crawled) {
            $crawled[] = $url;
        });

    $crawler->start();

    sort($crawled);

    expect($crawled)->toBe([
        'https://example.com/',
        'https://example.com/a',
        'https://example.com/b',
    ]);

    expect($crawler->getCrawlQueue()->getUrl('https://example.com/a')->depth)->toBe(1);
    expect($crawler->getCrawlQueue()->getUrl('https://example.com/b')->depth)->toBe(2);
});

it('rejects shortest discovery depth mode with javascript rendering', function () {
    $renderer = new class implements JavaScriptRenderer
    {
        public function getRenderedHtml(string $url): string
        {
            return '';
        }
    };

    Crawler::create('https://example.com')
        ->fake(['https://example.com/' => 'Home'])
        ->shortestDiscoveryDepth()
        ->executeJavaScript($renderer)
        ->start();
})->throws(UnsupportedShortestDiscoveryDepthMode::class);

it('rejects shortest discovery depth mode with a custom queue', function () {
    $queue = new class implements CrawlQueue
    {
        public function add(CrawlUrl $url): CrawlQueue
        {
            return $this;
        }

        public function has(string $url): bool
        {
            return false;
        }

        public function hasPendingUrls(): bool
        {
            return false;
        }

        public function getUrlById(mixed $id): CrawlUrl
        {
            return new CrawlUrl('https://example.com/');
        }

        public function getPendingUrl(): ?CrawlUrl
        {
            return null;
        }

        public function hasAlreadyBeenProcessed(CrawlUrl $url): bool
        {
            return false;
        }

        public function markAsProcessed(CrawlUrl $crawlUrl): void {}

        public function getProcessedUrlCount(): int
        {
            return 0;
        }

        public function getUrlCount(): int
        {
            return 0;
        }

        public function getPendingUrlCount(): int
        {
            return 0;
        }
    };

    Crawler::create('https://example.com')
        ->fake(['https://example.com/' => 'Home'])
        ->crawlQueue($queue)
        ->shortestDiscoveryDepth()
        ->start();
})->throws(UnsupportedShortestDiscoveryDepthMode::class);
