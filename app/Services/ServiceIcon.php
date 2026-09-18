<?php

namespace App\Services;

/**
 * Resolves a bundled brand icon for a connection from its name or URL host when no favicon was fetched.
 */
class ServiceIcon
{
    private const DIRECTORY = 'img/services';

    private const EXTENSIONS = ['svg', 'png', 'ico'];

    /**
     * Icon slug => keywords matched as whole words in the name, or as labels in the URL host.
     * Keywords containing a dot only match the host.
     *
     * @var array<string, list<string>>
     */
    private const KEYWORDS = [
        'actual-budget' => ['actual budget', 'actualbudget', 'actual'],
        'google-workspace' => ['google workspace', 'workspace', 'gsuite'],
        'google-calendar' => ['google calendar', 'gcal'],
        'google-drive' => ['google drive', 'gdrive'],
        'homeassistant' => ['home assistant', 'homeassistant', 'hass'],
        'hackernews' => ['hacker news', 'hackernews', 'ycombinator'],
        'chrome' => ['browser', 'chrome', 'chromium'],
        'hermes' => ['hermes', 'nous', 'nousresearch'],
        'spark' => ['spark', 'readdle', 'sparkmailapp'],
        'anthropic' => ['anthropic', 'claude'],
        'aws' => ['aws', 'amazon', 'amazonaws'],
        'postgresql' => ['postgresql', 'postgres'],
        'mongodb' => ['mongodb', 'mongo'],
        'kubernetes' => ['kubernetes', 'k8s'],
        'huggingface' => ['huggingface', 'hugging face'],
        'nodejs' => ['nodejs', 'node.js', 'node'],
        'x' => ['twitter', 'x.com', 'twitter.com'],
        'make' => ['make.com'],
        'fly' => ['fly.io', 'flyio'],
        'atlassian' => ['atlassian'],
        'confluence' => ['confluence'],
        'jira' => ['jira'],
        'asana' => ['asana'],
        'github' => ['github'],
        'gitlab' => ['gitlab'],
        'bitbucket' => ['bitbucket'],
        'slack' => ['slack'],
        'sentry' => ['sentry'],
        'notion' => ['notion'],
        'linear' => ['linear'],
        'stripe' => ['stripe'],
        'cloudflare' => ['cloudflare'],
        'trello' => ['trello'],
        'hubspot' => ['hubspot'],
        'metabase' => ['metabase'],
        'smartsheet' => ['smartsheet'],
        'connecteam' => ['connecteam'],
        'gmail' => ['gmail'],
        'google' => ['google'],
        'figma' => ['figma'],
        'supabase' => ['supabase'],
        'vercel' => ['vercel'],
        'todoist' => ['todoist'],
        'zapier' => ['zapier'],
        'airtable' => ['airtable'],
        'discord' => ['discord'],
        'intercom' => ['intercom'],
        'shopify' => ['shopify'],
        'clickup' => ['clickup'],
        'monday' => ['monday'],
        'mysql' => ['mysql'],
        'firebase' => ['firebase'],
        'openai' => ['openai', 'chatgpt'],
        'microsoft' => ['microsoft', 'azure', 'office 365', 'outlook'],
        'docker' => ['docker'],
        'grafana' => ['grafana'],
        'datadog' => ['datadog'],
        'pagerduty' => ['pagerduty'],
        'jenkins' => ['jenkins'],
        'salesforce' => ['salesforce'],
        'zendesk' => ['zendesk'],
        'mailchimp' => ['mailchimp'],
        'paypal' => ['paypal'],
        'square' => ['square', 'squareup'],
        'quickbooks' => ['quickbooks', 'intuit'],
        'xero' => ['xero'],
        'dropbox' => ['dropbox'],
        'box' => ['box'],
        'obsidian' => ['obsidian'],
        'evernote' => ['evernote'],
        'youtube' => ['youtube'],
        'reddit' => ['reddit'],
        'linkedin' => ['linkedin'],
        'facebook' => ['facebook', 'meta'],
        'instagram' => ['instagram'],
        'wordpress' => ['wordpress'],
        'webflow' => ['webflow'],
        'ghost' => ['ghost'],
        'plex' => ['plex'],
        'spotify' => ['spotify'],
        'postman' => ['postman'],
        'snowflake' => ['snowflake'],
        'redis' => ['redis'],
        'elastic' => ['elastic', 'elasticsearch'],
        'digitalocean' => ['digitalocean', 'digital ocean'],
        'netlify' => ['netlify'],
        'render' => ['render'],
        'railway' => ['railway'],
        'coolify' => ['coolify'],
        'n8n' => ['n8n'],
        'miro' => ['miro'],
        'loom' => ['loom'],
        'zoom' => ['zoom'],
        'calendly' => ['calendly'],
        'wikipedia' => ['wikipedia'],
        'brave' => ['brave'],
        'perplexity' => ['perplexity'],
        'playwright' => ['playwright'],
        'puppeteer' => ['puppeteer'],
        'context7' => ['context7'],
        'laravel' => ['laravel'],
        'php' => ['php'],
        'python' => ['python'],
        'tailwind' => ['tailwind'],
        'canva' => ['canva'],
    ];

    /**
     * Public path of the bundled icon that best matches the name, then the URL host, or null.
     */
    public function resolve(?string $name, ?string $url = null): ?string
    {
        $name = strtolower(trim((string) $name));
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        foreach ([$name, $host] as $subject) {
            if ($subject === '') {
                continue;
            }
            foreach ($this->rankedKeywords() as [$slug, $keyword]) {
                if ($this->matches($subject, $keyword, $subject === $host) && ($path = $this->path($slug)) !== null) {
                    return $path;
                }
            }
        }

        return null;
    }

    public function defaultPath(): string
    {
        return '/'.self::DIRECTORY.'/default.svg';
    }

    /**
     * Keywords sorted longest first so "google calendar" wins over "google".
     *
     * @return list<array{0: string, 1: string}>
     */
    private function rankedKeywords(): array
    {
        static $ranked = null;
        if ($ranked === null) {
            $ranked = [];
            foreach (self::KEYWORDS as $slug => $keywords) {
                foreach ($keywords as $keyword) {
                    $ranked[] = [$slug, $keyword];
                }
            }
            usort($ranked, fn (array $a, array $b): int => strlen($b[1]) <=> strlen($a[1]));
        }

        return $ranked;
    }

    private function matches(string $subject, string $keyword, bool $isHost): bool
    {
        if (str_contains($keyword, '.')) {
            return $isHost && ($subject === $keyword || str_ends_with($subject, '.'.$keyword));
        }
        if ($isHost) {
            return in_array($keyword, explode('.', $subject), true) || (bool) preg_match('/(^|[.-])'.preg_quote($keyword, '/').'([.-]|$)/', $subject);
        }

        return (bool) preg_match('/(?<![a-z0-9])'.preg_quote($keyword, '/').'(?![a-z0-9])/', $subject);
    }

    private function path(string $slug): ?string
    {
        foreach (self::EXTENSIONS as $extension) {
            if (is_file(public_path(self::DIRECTORY.'/'.$slug.'.'.$extension))) {
                return '/'.self::DIRECTORY.'/'.$slug.'.'.$extension;
            }
        }

        return null;
    }
}
