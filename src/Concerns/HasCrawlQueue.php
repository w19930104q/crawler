<?php

namespace Spatie\Crawler\Concerns;

use Spatie\Crawler\CrawlQueues\CrawlQueue;
use Spatie\Crawler\CrawlUrl;

trait HasCrawlQueue
{
    protected CrawlQueue $crawlQueue;

    public function crawlQueue(CrawlQueue $crawlQueue): self
    {
        $this->crawlQueue = $crawlQueue;

        return $this;
    }

    public function getCrawlQueue(): CrawlQueue
    {
        return $this->crawlQueue;
    }

    public function addToCrawlQueue(CrawlUrl $crawlUrl): self
    {
        $this->addCrawlUrlToQueue($crawlUrl);

        return $this;
    }

    protected function addCrawlUrlToQueue(CrawlUrl $crawlUrl): bool
    {
        if ($this->getCrawlQueue()->has($crawlUrl->url)) {
            return false;
        }

        if ($this->matchesAlwaysCrawl($crawlUrl->url)) {
            $this->crawlQueue->add($crawlUrl);

            return true;
        }

        if ($this->matchesNeverCrawl($crawlUrl->url)) {
            return false;
        }

        if ($this->respectRobots && $this->robotsTxt !== null) {
            if (! $this->robotsTxt->allows($crawlUrl->url, $this->getUserAgent())) {
                return false;
            }
        }

        if (! $this->getCrawlProfile()->shouldCrawl($crawlUrl->url)) {
            return false;
        }

        $this->crawlQueue->add($crawlUrl);

        return true;
    }
}
