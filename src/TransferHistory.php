<?php

namespace Spatie\Crawler;

use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;

/**
 * Ordered record of every HTTP transfer performed for the crawl queue tasks.
 *
 * Each Crawler owns its own history, so two crawlers sharing a HandlerStack
 * never pollute each other. Requests made directly on a shared Guzzle client
 * do not carry the internal task header and are therefore never recorded.
 */
class TransferHistory
{
    /**
     * Internal request header carrying the crawl queue task id. It is added by
     * the tracking middleware and read back from on_stats, where the Guzzle 7
     * pool does not expose the iterable key.
     */
    public const TASK_ID_HEADER = 'X-Spatie-Crawler-Task-Id';

    /**
     * Task ids indexed by the encoded header value used on the wire.
     *
     * @var array<string, string>
     */
    private array $taskIds = [];

    /**
     * Recorded transfers grouped by task id, in recording order.
     *
     * @var array<string, list<TransferEvent>>
     */
    private array $events = [];

    /**
     * Raw Guzzle stats of the latest transfer per task id. This backs the
     * TransferStatistics value handed to the crawl observers.
     *
     * @var array<string, TransferStats>
     */
    private array $latestStats = [];

    /**
     * Monotonic counter guaranteeing a total order across tasks even when
     * transfers interleave concurrently.
     */
    private int $sequence = 0;

    /**
     * Register a queue task before its request is sent. The returned header
     * value must be attached to the outgoing request.
     */
    public function registerTask(string $taskId): string
    {
        $headerValue = $this->encodeTaskId($taskId);

        $this->taskIds[$headerValue] = $taskId;
        $this->events[$taskId] ??= [];

        return $headerValue;
    }

    public function recordFromStats(TransferStats $stats): ?TransferEvent
    {
        $taskId = $this->taskIdFromRequest($stats->getRequest());

        if ($taskId === null) {
            // Robots.txt requests and requests issued directly on a shared
            // Guzzle client do not carry the task header.
            return null;
        }

        $event = TransferEvent::fromTransferStats($taskId, $stats, $this->sequence++);

        $this->events[$taskId][] = $event;
        $this->latestStats[$taskId] = $stats;

        return $event;
    }

    /**
     * Ordered transfers of one queue task, oldest first. The returned array is
     * a snapshot: later transfers, retries and a subsequent start() never
     * mutate it. A failed terminal task still exposes every attempt.
     *
     * @return list<TransferEvent>
     */
    public function forTask(string $taskId): array
    {
        return $this->events[$taskId] ?? [];
    }

    /**
     * Last transfer recorded for a queue task, which is what the success and
     * failure observers expose as transfer statistics. Null is returned when
     * the task never performed a transfer (for example it was skipped).
     */
    public function lastEventForTask(string $taskId): ?TransferEvent
    {
        $events = $this->events[$taskId] ?? [];

        return $events === [] ? null : $events[count($events) - 1];
    }

    /**
     * Raw Guzzle TransferStats of the last transfer performed for a task.
     */
    public function lastStatsForTask(string $taskId): ?TransferStats
    {
        return $this->latestStats[$taskId] ?? null;
    }

    /**
     * Snapshot of every recorded transfer across all tasks, ordered by the
     * moment each transfer finished. The returned array is not affected by
     * transfers recorded afterwards or by starting a new crawl.
     *
     * @return list<TransferEvent>
     */
    public function all(): array
    {
        $events = array_merge([], ...array_values($this->events));

        usort($events, fn (TransferEvent $a, TransferEvent $b) => $a->sequence() <=> $b->sequence());

        return $events;
    }

    /**
     * Drop every recorded transfer. Called at the beginning of each start()
     * so a new crawl never exposes tracking from a previous run.
     */
    public function reset(): void
    {
        $this->taskIds = [];
        $this->events = [];
        $this->latestStats = [];
        $this->sequence = 0;
    }

    /**
     * Attach the encoded task id to an outgoing request.
     */
    public static function attachTaskId(RequestInterface $request, string $headerValue): RequestInterface
    {
        return $request->withHeader(self::TASK_ID_HEADER, $headerValue);
    }

    private function taskIdFromRequest(RequestInterface $request): ?string
    {
        $headerValue = $request->getHeaderLine(self::TASK_ID_HEADER);

        if ($headerValue === '') {
            return null;
        }

        return $this->taskIds[$headerValue] ?? null;
    }

    private function encodeTaskId(string $taskId): string
    {
        // Header values must stay ASCII; ids are URLs, so percent encode any
        // byte outside the token range while keeping the value reversible.
        return rawurlencode($taskId);
    }
}
