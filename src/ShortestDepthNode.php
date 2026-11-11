<?php

namespace Spatie\Crawler;

/** @internal */
class ShortestDepthNode
{
    public bool $handled = false;

    public ?string $foundOnUrl = null;

    /** @var array<int, ExtractedUrl> */
    public array $extractedUrls = [];

    /** @var array<int, string> */
    public array $redirectTargets = [];
}
