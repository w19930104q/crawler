<?php

use Spatie\Crawler\Crawler;
use Spatie\Crawler\CrawlResponse;
use Spatie\Crawler\Enums\FinishReason;

it('does not parse or enqueue external redirect targets when crawling internal urls only', function () {
    $crawled = [];

    Crawler::create('https://example.com')
        ->fake([
            'https://example.com' => '<a href="/old-page">Old page</a>',
            'https://example.com/old-page' => CrawlResponse::fake('', 302, ['Location' => 'https://other.com/new-page']),
            'https://other.com/new-page' => '<a href="https://other.com/inner">Inner</a><a href="https://example.com/back">Back</a>',
            'https://other.com/inner' => 'inner',
            'https://example.com/back' => 'back',
        ])
        ->ignoreRobots()
        ->internalOnly()
        ->onCrawled(function (string $url) use (&$crawled) {
            $crawled[] = $url;
        })
        ->start();

    // The external final page is not reported, and its links are never enqueued.
    expect($crawled)->toBe(['https://example.com/']);
});

it('crawls cross-host redirect targets when the profile allows all urls', function () {
    $crawled = [];

    Crawler::create('https://example.com')
        ->fake([
            'https://example.com' => '<a href="/old-page">Old page</a>',
            'https://example.com/old-page' => CrawlResponse::fake('', 302, ['Location' => 'https://other.com/new-page']),
            'https://other.com/new-page' => '<a href="https://other.com/inner">Inner</a>',
            'https://other.com/inner' => 'inner',
        ])
        ->ignoreRobots()
        ->onCrawled(function (string $url) use (&$crawled) {
            $crawled[] = $url;
        })
        ->start();

    expect($crawled)->toContain('https://example.com/old-page');
    expect($crawled)->toContain('https://other.com/inner');
});

it('terminates on redirect loops', function () {
    $crawled = [];

    $finishReason = Crawler::create('https://example.com')
        ->fake([
            'https://example.com' => '<a href="/loop-a">Loop</a>',
            'https://example.com/loop-a' => CrawlResponse::fake('', 302, ['Location' => 'https://example.com/loop-b']),
            'https://example.com/loop-b' => CrawlResponse::fake('', 302, ['Location' => 'https://example.com/loop-a']),
        ])
        ->ignoreRobots()
        ->onCrawled(function (string $url) use (&$crawled) {
            $crawled[] = $url;
        })
        ->start();

    expect($finishReason)->toBe(FinishReason::Completed);
    expect($crawled)->toContain('https://example.com/');
    expect(count($crawled))->toBeLessThanOrEqual(3);
});

it('treats a www redirect target as internal when matchWww is enabled', function () {
    $crawled = [];
    $finalUrls = [];

    Crawler::create('https://example.com')
        ->fake([
            'https://example.com' => '<a href="/old">Old</a>',
            'https://example.com/old' => CrawlResponse::fake('', 302, ['Location' => 'https://www.example.com/old']),
            'https://www.example.com/old' => '<a href="https://www.example.com/next">Next</a>',
            'https://www.example.com/next' => 'next',
        ])
        ->ignoreRobots()
        ->internalOnly()
        ->matchWww()
        ->onCrawled(function (string $url, CrawlResponse $response) use (&$crawled, &$finalUrls) {
            $crawled[] = $url;
            $finalUrls[$url] = $response->finalUrl();
        })
        ->start();

    expect($crawled)->toContain('https://example.com/old');
    expect($finalUrls['https://example.com/old'])->toBe('https://www.example.com/old');
    expect($crawled)->toContain('https://www.example.com/next');
});

it('rejects a www redirect target when matchWww is not enabled', function () {
    $crawled = [];

    Crawler::create('https://example.com')
        ->fake([
            'https://example.com' => '<a href="/old">Old</a>',
            'https://example.com/old' => CrawlResponse::fake('', 302, ['Location' => 'https://www.example.com/old']),
            'https://www.example.com/old' => 'www page',
        ])
        ->ignoreRobots()
        ->internalOnly()
        ->onCrawled(function (string $url) use (&$crawled) {
            $crawled[] = $url;
        })
        ->start();

    expect($crawled)->toBe(['https://example.com/']);
});

it('processes the final page only once when multiple aliases redirect to it', function () {
    $crawled = [];
    $finalUrls = [];

    Crawler::create('https://example.com')
        ->fake([
            'https://example.com' => '<a href="/alias-one">1</a><a href="/alias-two">2</a><a href="/final">F</a>',
            'https://example.com/alias-one' => CrawlResponse::fake('', 302, ['Location' => '/final']),
            'https://example.com/alias-two' => CrawlResponse::fake('', 302, ['Location' => 'https://example.com/final']),
            'https://example.com/final' => '<a href="/child">Child</a>',
            'https://example.com/child' => 'child',
        ])
        ->ignoreRobots()
        ->internalOnly()
        ->onCrawled(function (string $url, CrawlResponse $response) use (&$crawled, &$finalUrls) {
            $crawled[] = $url;
            $finalUrls[$url] = $response->finalUrl();
        })
        ->start();

    // Homepage, the final page (via exactly one of the three URLs pointing
    // at it) and the child of the final page.
    expect($crawled)->toHaveCount(3);
    expect($crawled)->toContain('https://example.com/');
    expect($crawled)->toContain('https://example.com/child');

    $finalPageHits = array_filter($finalUrls, fn (?string $finalUrl) => $finalUrl === 'https://example.com/final');

    expect($finalPageHits)->toHaveCount(1);
});

it('respects the robots.txt of the final host after a cross-domain redirect', function () {
    $crawled = [];

    Crawler::create('https://old-site.com')
        ->fake([
            'https://old-site.com/robots.txt' => "User-agent: *\nAllow: /",
            'https://old-site.com' => CrawlResponse::fake('', 302, ['Location' => 'https://new-site.com/']),
            'https://new-site.com/robots.txt' => "User-agent: *\nDisallow: /private",
            'https://new-site.com' => '<a href="https://old-site.com/private">Private</a><a href="https://old-site.com/public">Public</a>',
            'https://old-site.com/private' => CrawlResponse::fake('', 302, ['Location' => 'https://new-site.com/private']),
            'https://new-site.com/private' => 'private content',
            'https://old-site.com/public' => CrawlResponse::fake('', 302, ['Location' => 'https://new-site.com/public']),
            'https://new-site.com/public' => 'public content',
        ])
        ->respectRobots()
        ->onCrawled(function (string $url) use (&$crawled) {
            $crawled[] = $url;
        })
        ->start();

    expect($crawled)->toContain('https://old-site.com/');
    expect($crawled)->toContain('https://old-site.com/public');
    expect($crawled)->not->toContain('https://old-site.com/private');
    expect($crawled)->not->toContain('https://new-site.com/private');
});

it('applies neverCrawl patterns to redirect targets', function () {
    $crawled = [];

    Crawler::create('https://example.com')
        ->fake([
            'https://example.com' => '<a href="/jump">Jump</a>',
            'https://example.com/jump' => CrawlResponse::fake('', 302, ['Location' => 'https://other.com/target']),
            'https://other.com/target' => 'target',
        ])
        ->ignoreRobots()
        ->neverCrawl(['*other.com*'])
        ->onCrawled(function (string $url) use (&$crawled) {
            $crawled[] = $url;
        })
        ->start();

    expect($crawled)->toBe(['https://example.com/']);
});

it('applies alwaysCrawl patterns to redirect targets', function () {
    $crawled = [];

    Crawler::create('https://example.com')
        ->fake([
            'https://example.com' => '<a href="/jump">Jump</a>',
            'https://example.com/jump' => CrawlResponse::fake('', 302, ['Location' => 'https://other.com/target']),
            'https://other.com/target' => 'target',
        ])
        ->ignoreRobots()
        ->internalOnly()
        ->alwaysCrawl(['https://other.com/*'])
        ->onCrawled(function (string $url) use (&$crawled) {
            $crawled[] = $url;
        })
        ->start();

    expect($crawled)->toContain('https://example.com/jump');
});

it('does not count redirects towards the depth limit', function () {
    $crawled = [];

    Crawler::create('https://example.com')
        ->fake([
            'https://example.com' => '<a href="/jump">Jump</a>',
            'https://example.com/jump' => CrawlResponse::fake('', 302, ['Location' => 'https://example.com/landing']),
            'https://example.com/landing' => '<a href="/too-deep">Too deep</a>',
            'https://example.com/too-deep' => 'too deep',
        ])
        ->ignoreRobots()
        ->depth(1)
        ->onCrawled(function (string $url) use (&$crawled) {
            $crawled[] = $url;
        })
        ->start();

    expect($crawled)->toContain('https://example.com/');
    expect($crawled)->toContain('https://example.com/jump');
    expect($crawled)->not->toContain('https://example.com/too-deep');
});

it('exposes the request url, final url and redirect chain to observers', function () {
    $seen = null;

    Crawler::create('https://example.com')
        ->fake([
            'https://example.com' => '<a href="/step1">Go</a>',
            'https://example.com/step1' => CrawlResponse::fake('', 302, ['Location' => '/step2']),
            'https://example.com/step2' => CrawlResponse::fake('', 301, ['Location' => 'https://example.com/step3']),
            'https://example.com/step3' => 'final content',
        ])
        ->ignoreRobots()
        ->onCrawled(function (string $url, CrawlResponse $response) use (&$seen) {
            if ($url === 'https://example.com/step1') {
                $seen = [
                    'requestUrl' => $url,
                    'requestedUrl' => $response->requestedUrl(),
                    'finalUrl' => $response->finalUrl(),
                    'redirectHistory' => $response->redirectHistory(),
                    'wasRedirected' => $response->wasRedirected(),
                ];
            }
        })
        ->start();

    expect($seen)->toBe([
        'requestUrl' => 'https://example.com/step1',
        'requestedUrl' => 'https://example.com/step1',
        'finalUrl' => 'https://example.com/step3',
        'redirectHistory' => ['https://example.com/step2', 'https://example.com/step3'],
        'wasRedirected' => true,
    ]);
});

it('reports the requested url as final url when no redirect occurred', function () {
    $finalUrls = [];

    Crawler::create('https://example.com')
        ->fake([
            'https://example.com' => '<a href="/page">Page</a>',
            'https://example.com/page' => 'page',
        ])
        ->ignoreRobots()
        ->onCrawled(function (string $url, CrawlResponse $response) use (&$finalUrls) {
            $finalUrls[$url] = [$response->finalUrl(), $response->wasRedirected()];
        })
        ->start();

    expect($finalUrls['https://example.com/page'])->toBe(['https://example.com/page', false]);
});
