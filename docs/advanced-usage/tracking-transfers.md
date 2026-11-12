---
title: Tracking transfers
weight: 7
---

The crawler can keep an ordered history of every HTTP transfer it performs.
Transfer tracking is **disabled by default**. When enabled, each crawl queue
task gets its own history covering every redirect hop and every retry
attempt, including transfers that end in a network error.

Tracking only applies to non-rendering HTTP collection. When a JavaScript
renderer is active, the visible page is produced by the browser, whose
network activity is not observable through Guzzle, so no transfers are
recorded.

## Enabling tracking

Call `trackTransfers()` while configuring the crawler:

```php
use Spatie\Crawler\Crawler;

$crawler = Crawler::create('https://example.com')
    ->ignoreRobots()
    ->trackTransfers();

$crawler->start();
```

Use `doNotTrackTransfers()` to turn it off again, and `isTrackingTransfers()`
to inspect the current state.

## Reading the history of a queue task

Queue tasks are identified by their queue task id, which is the normalized
URL of the crawled page. Pass it to `getTransferHistory()` to receive an
ordered list of `Spatie\Crawler\TransferEvent` objects, oldest first:

```php
$events = $crawler->getTransferHistory('https://example.com/');

foreach ($events as $event) {
    echo $event->sequence();          // 0-based position within the task
    echo $event->requestUri();        // URI fetched for this transfer
    echo $event->effectiveUri();      // URI reached (differs after a redirect)
    echo $event->statusCode();        // HTTP status, or null on a network error
    echo $event->networkError();      // error message, or null on a response
    echo $event->transferTimeInMs();  // duration in milliseconds
    echo $event->wasSuccessful();     // false on a network error
    echo $event->wasRedirected();     // true when request and effective URI differ
}
```

The returned array is a snapshot. Transfers recorded later, retries, and a
subsequent `start()` never modify an array you already hold.

## Redirects and retries

Every physical transfer is recorded separately. A request that follows two
redirects produces three events, and a request that fails twice before
succeeding produces three attempts. Two concurrent tasks that end on the
same effective URL still keep two independent histories, because every event
is attributed to its own queue task id.

`robots.txt` requests never enter a page history: they do not belong to a
queue task. They keep feeding the throttle exactly as before.

## Failed terminal tasks

A task whose last attempt fails remains fully inspectable. Its failure events
expose a null `statusCode()` and the network error message, and the failure
observer still receives the `TransferStatistics` of the last transfer:

```php
$crawler
    ->retry(2)
    ->trackTransfers()
    ->onFailed(function (string $url, RequestException $exception, CrawlProgress $progress,
        ?string $foundOnUrl = null, ?string $linkText = null,
        ?ResourceType $resourceType = null, ?TransferStatistics $transferStats = null
    ) {
        // $transferStats describes the final (failed) transfer
    })
    ->start();
```

## Starting a new crawl

`start()` resets all recorded transfers, so a second run never exposes
history from the previous run. The `TransferStatistics` value handed to the
success and failure observers always describes the last transfer of the
current task.

## Shared handler stacks

Each crawler owns a private history, so two crawlers configured with the same
`HandlerStack` never see each other's transfers. Requests issued directly on
a Guzzle client built from that shared stack carry no crawler task id and are
not recorded either.
