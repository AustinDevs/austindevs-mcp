<?php

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

class FaviconFetcher
{
    public function fetch(string $url): ?string
    {
        $origin = RemoteUrl::origin($url);
        $candidates = [];
        try {
            $page = Http::withoutRedirecting()->connectTimeout(3)->timeout(5)->get($origin.'/');
            if ($page->successful() && strlen($page->body()) <= 1048576) {
                $document = new \DOMDocument;
                @$document->loadHTML($page->body(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                foreach ($document->getElementsByTagName('link') as $link) {
                    if (preg_match('/(?:^|\s)(?:icon|apple-touch-icon)(?:\s|$)/i', $link->getAttribute('rel'))) {
                        $candidates[] = (string) UriResolver::resolve(new Uri($origin.'/'), new Uri($link->getAttribute('href')));
                    }
                }
            }
        } catch (Throwable) {
            // Icon discovery is optional; the conventional location is tried below.
        }
        $candidates[] = $origin.'/favicon.ico';
        foreach (array_slice(array_unique($candidates), 0, 5) as $candidate) {
            try {
                $response = Http::withoutRedirecting()->connectTimeout(3)->timeout(5)->get(RemoteUrl::validate($candidate));
                if ($response->successful() && strlen($response->body()) <= 262144) {
                    $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($response->body());
                    if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'], true)) {
                        return 'data:'.$mime.';base64,'.base64_encode($response->body());
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
