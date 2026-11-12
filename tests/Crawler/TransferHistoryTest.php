<?php

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Spatie\Crawler\TransferEvent;
use Spatie\Crawler\TransferHistory;

function trackingStats(string $uri, ?int $status = 200, ?string $error = null): TransferStats
{
    $request = (new Request('GET', $uri))
        ->withHeader(TransferHistory::TASK_ID_HEADER, rawurlencode($uri));

    if ($status === null) {
        return new TransferStats($request, null, 0.02, $error, [
            'total_time' => 0.02,
            'error' => $error,
        ]);
    }

    return new TransferStats($request, new Response($status), 0.02, null, [
        'total_time' => 0.02,
    ]);
}

it('keeps interleaved transfers of concurrent tasks attributable and globally ordered', function () {
    $history = new TransferHistory;

    $history->registerTask('http://x/a');
    $history->registerTask('http://x/b');

    // Physically interleave: a1, b1, a2, b2.
    $history->recordFromStats(trackingStats('http://x/a', 302));
    $history->recordFromStats(trackingStats('http://x/b', 302));
    $history->recordFromStats(trackingStats('http://x/a', 200));
    $history->recordFromStats(trackingStats('http://x/b', null, 'Connection reset'));

    $a = $history->forTask('http://x/a');
    $b = $history->forTask('http://x/b');

    expect($a)->toHaveCount(2);
    expect($b)->toHaveCount(2);

    expect(array_map(fn (TransferEvent $e) => $e->taskId(), $a))->toBe(['http://x/a', 'http://x/a']);
    expect(array_map(fn (TransferEvent $e) => $e->taskId(), $b))->toBe(['http://x/b', 'http://x/b']);

    expect($a[0]->statusCode())->toBe(302);
    expect($a[1]->statusCode())->toBe(200);

    expect($b[0]->statusCode())->toBe(302);
    expect($b[1]->statusCode())->toBeNull();
    expect($b[1]->networkError())->toBe('Connection reset');

    // Global order preserves the interleaving.
    $all = $history->all();
    expect(array_map(fn (TransferEvent $e) => $e->sequence(), $all))->toBe([0, 1, 2, 3]);
    expect(array_map(fn (TransferEvent $e) => $e->taskId(), $all))
        ->toBe(['http://x/a', 'http://x/b', 'http://x/a', 'http://x/b']);
});

it('ignores stats without a registered task and never registers them', function () {
    $history = new TransferHistory;
    $history->registerTask('http://x/page');

    $foreign = new TransferStats(new Request('GET', 'http://x/robots.txt'), new Response(200), 0.01);

    expect($history->recordFromStats($foreign))->toBeNull();
    expect($history->all())->toBe([]);
    expect($history->forTask('http://x/robots.txt'))->toBe([]);

    $history->recordFromStats(trackingStats('http://x/page'));

    expect($history->all())->toHaveCount(1);
});

it('resets all state and survives a second run without leaking old transfers', function () {
    $history = new TransferHistory;
    $history->registerTask('http://x/page');
    $history->recordFromStats(trackingStats('http://x/page'));

    expect($history->all())->toHaveCount(1);

    $history->reset();

    expect($history->all())->toBe([]);
    expect($history->forTask('http://x/page'))->toBe([]);
    expect($history->lastEventForTask('http://x/page'))->toBeNull();
    expect($history->lastStatsForTask('http://x/page'))->toBeNull();

    // Re-registering after reset works and sequence numbering restarts.
    $history->registerTask('http://x/page');
    $event = $history->recordFromStats(trackingStats('http://x/page'));

    expect($event->sequence())->toBe(0);
    expect($history->all())->toHaveCount(1);
});

it('returns independent snapshots that internal appends cannot change', function () {
    $history = new TransferHistory;
    $history->registerTask('http://x/page');
    $history->recordFromStats(trackingStats('http://x/page'));

    $first = $history->forTask('http://x/page');
    $global = $history->all();

    $history->registerTask('http://x/other');
    $history->recordFromStats(trackingStats('http://x/other'));

    expect($first)->toHaveCount(1);
    expect($global)->toHaveCount(1);
    expect($history->all())->toHaveCount(2);
});
