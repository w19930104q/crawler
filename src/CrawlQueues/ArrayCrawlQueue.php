<?php

namespace Spatie\Crawler\CrawlQueues;

use Spatie\Crawler\CrawlUrl;
use Spatie\Crawler\Exceptions\UrlNotFoundByIndex;
use Spatie\Crawler\UrlNormalizer;

class ArrayCrawlQueue implements CrawlQueue
{
    /** @var CrawlUrl[] */
    protected array $urls = [];

    /** @var CrawlUrl[] */
    protected array $pendingUrls = [];

    public function add(CrawlUrl $crawlUrl): CrawlQueue
    {
        $normalizedUrl = $this->normalizeUrl($crawlUrl->url);

        if (! isset($this->urls[$normalizedUrl])) {
            $crawlUrl->id = $normalizedUrl;

            $this->urls[$normalizedUrl] = $crawlUrl;
            $this->pendingUrls[$normalizedUrl] = $crawlUrl;
        }

        return $this;
    }

    public function hasPendingUrls(): bool
    {
        return (bool) $this->pendingUrls;
    }

    public function getUrlById(mixed $id): CrawlUrl
    {
        if (! isset($this->urls[$id])) {
            throw new UrlNotFoundByIndex("Crawl url {$id} not found in collection.");
        }

        return $this->urls[$id];
    }

    public function hasAlreadyBeenProcessed(CrawlUrl $crawlUrl): bool
    {
        $normalizedUrl = $this->normalizeUrl($crawlUrl->url);

        if (isset($this->pendingUrls[$normalizedUrl])) {
            return false;
        }

        if (isset($this->urls[$normalizedUrl])) {
            return true;
        }

        return false;
    }

    public function markAsProcessed(CrawlUrl $crawlUrl): void
    {
        $normalizedUrl = $this->normalizeUrl($crawlUrl->url);

        unset($this->pendingUrls[$normalizedUrl]);
    }

    public function getProcessedUrlCount(): int
    {
        return count($this->urls) - count($this->pendingUrls);
    }

    public function has(string $url): bool
    {
        return isset($this->urls[$this->normalizeUrl($url)]);
    }

    public function getPendingUrl(): ?CrawlUrl
    {
        foreach ($this->pendingUrls as $pendingUrl) {
            return $pendingUrl;
        }

        return null;
    }

    public function getUrlCount(): int
    {
        return count($this->urls);
    }

    public function getPendingUrlCount(): int
    {
        return count($this->pendingUrls);
    }

    protected function normalizeUrl(string $url): string
    {
        return UrlNormalizer::normalize($url);
    }
}
