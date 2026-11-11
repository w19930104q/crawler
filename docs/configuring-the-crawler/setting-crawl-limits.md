---
title: Limits
weight: 2
---

By default, the crawler continues until it has crawled every page it can find. This behavior might cause issues if you are working in an environment with limitations such as a serverless environment.

## Crawl depth

You can limit how deep the crawler will go using the `depth` method.

```php
use Spatie\Crawler\Crawler;

Crawler::create('https://example.com')
    ->depth(2)
    ->start();
```

A depth of 0 means only the start URL will be crawled. A depth of 1 means the start URL and any pages it links to, and so on.

### Shortest discovery depth

By default a URL is assigned the depth at which it was first discovered. If a
shorter path to the same URL is found later, the URL keeps its original depth.
This can make the set of crawled pages depend on the order in which responses
complete.

Enable `shortestDiscoveryDepth()` to always enforce the depth limit using the
shortest path discovered so far:

```php
use Spatie\Crawler\Crawler;

Crawler::create('https://example.com')
    ->depth(2)
    ->shortestDiscoveryDepth()
    ->start();
```

With this mode, the start URL has depth 0, every followed link adds one, and
redirects add no extra depth. When a shorter path is found, the final depth of
pending, in-flight, and already crawled pages is updated, and pages that now
fall within the depth limit are added to the queue. URLs are still crawled
only once; pages are not recrawled to correct their depth. Observers are called
when a page is actually crawled and receive the depth it had at that moment.

The mode is disabled by default and can be disabled again with
`withoutShortestDiscoveryDepth()`. It only supports non-rendered HTTP crawls
with the built-in `ArrayCrawlQueue`: combining it with JavaScript rendering or
a custom crawl queue throws a
`Spatie\Crawler\Exceptions\UnsupportedShortestDiscoveryDepthMode`
exception when the crawl starts.

## Crawl and time limits

The crawl behavior can be controlled with these options:

- `limit()`: the maximum number of URLs to crawl across all executions
- `limitPerExecution()`: how many URLs to process during the current crawl
- `timeLimit()`: the maximum execution time in seconds across all executions
- `timeLimitPerExecution()`: the maximum execution time in seconds for the current crawl

When any of these limits are reached, the crawler stops and returns a `FinishReason` from `start()`. See [tracking progress](/docs/crawler/v9/basic-usage/tracking-progress) for details.

## Using the total crawl limit

The `limit()` method allows you to limit the total number of URLs to crawl, no matter how often you call the crawler.

```php
use Spatie\Crawler\Crawler;
use Spatie\Crawler\Enums\FinishReason;

$queue = <your queue implementation>;

// Crawls 5 URLs and ends.
$reason = Crawler::create('https://example.com')
    ->crawlQueue($queue)
    ->limit(5)
    ->start();

// $reason will be FinishReason::CrawlLimitReached

// Doesn't crawl further as the total limit is reached.
Crawler::create('https://example.com')
    ->crawlQueue($queue)
    ->limit(5)
    ->start();
```

## Using the current crawl limit

The `limitPerExecution()` method limits how many URLs will be crawled in a single execution. This is especially useful when [crawling across multiple requests](/docs/crawler/v9/advanced-usage/crawling-across-requests). This code will process 5 pages with each execution, without a total limit of pages to crawl.

```php
use Spatie\Crawler\Crawler;

$queue = <your queue implementation>;

// Crawls 5 URLs and ends.
Crawler::create('https://example.com')
    ->crawlQueue($queue)
    ->limitPerExecution(5)
    ->start();

// Crawls the next 5 URLs and ends.
Crawler::create('https://example.com')
    ->crawlQueue($queue)
    ->limitPerExecution(5)
    ->start();
```

## Using time limits

The `timeLimit()` method sets the maximum execution time across all executions. The `timeLimitPerExecution()` method sets the maximum execution time for a single crawl. Both accept a value in seconds.

```php
use Spatie\Crawler\Crawler;

// Stop crawling after 60 seconds total
$reason = Crawler::create('https://example.com')
    ->timeLimit(60)
    ->start();

// $reason will be FinishReason::TimeLimitReached if time ran out

// Stop each execution after 30 seconds, but allow resuming
Crawler::create('https://example.com')
    ->crawlQueue($queue)
    ->timeLimitPerExecution(30)
    ->start();
```

## Combining limits

All limits can be combined to control the crawler:

```php
use Spatie\Crawler\Crawler;

$queue = <your queue implementation>;

// Crawls 5 URLs and ends.
Crawler::create('https://example.com')
    ->crawlQueue($queue)
    ->limit(10)
    ->limitPerExecution(5)
    ->start();

// Crawls the next 5 URLs and ends.
Crawler::create('https://example.com')
    ->crawlQueue($queue)
    ->limit(10)
    ->limitPerExecution(5)
    ->start();

// Doesn't crawl further as the total limit is reached.
Crawler::create('https://example.com')
    ->crawlQueue($queue)
    ->limit(10)
    ->limitPerExecution(5)
    ->start();
```
