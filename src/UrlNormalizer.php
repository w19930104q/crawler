<?php

namespace Spatie\Crawler;

class UrlNormalizer
{
    public static function normalize(string $url): string
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['host'])) {
            return $url;
        }

        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host = strtolower($parsed['host']);

        $port = $parsed['port'] ?? null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $path = $parsed['path'] ?? '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $query = $parsed['query'] ?? null;
        if ($query === '') {
            $query = null;
        }

        $normalized = $scheme.'://'.$host;

        if ($port !== null) {
            $normalized .= ':'.$port;
        }

        $normalized .= $path;

        if ($query !== null) {
            $normalized .= '?'.$query;
        }

        return $normalized;
    }
}
