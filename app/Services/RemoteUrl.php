<?php

namespace App\Services;

use InvalidArgumentException;

class RemoteUrl
{
    public static function validate(string $url): string
    {
        $parts = parse_url($url);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Use an HTTP or HTTPS URL without embedded credentials or a fragment.');
        }

        return $url;
    }

    public static function origin(string $url): string
    {
        self::validate($url);
        $parts = parse_url($url);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
