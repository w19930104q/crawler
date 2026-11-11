<?php

namespace Spatie\Crawler\Exceptions;

use RuntimeException;

class UnsupportedShortestDiscoveryDepthMode extends RuntimeException
{
    public static function requiresBuiltInQueue(): self
    {
        return new self('Shortest discovery depth mode requires the built-in ArrayCrawlQueue.');
    }

    public static function doesNotSupportJavaScriptRendering(): self
    {
        return new self('Shortest discovery depth mode cannot be combined with JavaScript rendering.');
    }
}
