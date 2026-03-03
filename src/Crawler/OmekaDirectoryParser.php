<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Crawler;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use function array_filter;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function strip_tags;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * Scrapes the official Omeka Classic and Omeka S site directories from omeka.org
 * and returns structured records suitable for probing and writing to JSONL.
 *
 * Neither directory exposes a machine-readable endpoint — both are hand-maintained
 * HTML pages. This parser extracts:
 *   - Site URL (the first external link in each list item)
 *   - Site name (link text)
 *   - Omeka version type: 'classic' or 's'
 *   - Description (surrounding text in the list item)
 *   - Listed modules/plugins (when present)
 *
 * Important: These directories are user-submitted and self-reported. URLs may be
 * stale, redirected, or have changed format over time. Use detectVersion() on
 * OmekaPublicCrawler to verify live status before crawling.
 */
final class OmekaDirectoryParser
{
    private const CLASSIC_DIRECTORY_URL = 'https://omeka.org/classic/directory/';
    private const S_DIRECTORY_URL       = 'https://omeka.org/s/directory/';

    /** @var array<string> Internal omeka.org/google/font domains to skip when extracting site URLs */
    private const SKIP_HOSTS = [
        'omeka.org',
        'forum.omeka.org',
        'google.com',
        'fonts.googleapis.com',
        'ajax.googleapis.com',
        'github.com',
        'digitalscholar.org',
        'creativecommons.org',
        'docs.google.com',
        'transifex.com',
        'neh.gov',
        'imls.gov',
    ];

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * Fetch and parse the Omeka Classic directory.
     *
     * @return list<array{name: string, url: string, type: 'classic', description: string, plugins: list<string>}>
     */
    public function fetchClassic(): array
    {
        $html = $this->fetchHtml(self::CLASSIC_DIRECTORY_URL);

        return $this->parseListItems($html, 'classic');
    }

    /**
     * Fetch and parse the Omeka S directory.
     *
     * @return list<array{name: string, url: string, type: 's', description: string, plugins: list<string>}>
     */
    public function fetchS(): array
    {
        $html = $this->fetchHtml(self::S_DIRECTORY_URL);

        return $this->parseListItems($html, 's');
    }

    /**
     * Fetch and parse both directories combined.
     *
     * @return list<array{name: string, url: string, type: 'classic'|'s', description: string, plugins: list<string>}>
     */
    public function fetchAll(): array
    {
        return [...$this->fetchClassic(), ...$this->fetchS()];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function fetchHtml(string $url): string
    {
        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 30,
            'headers' => ['Accept' => 'text/html'],
        ]);

        return $response->getContent();
    }

    /**
     * Parse top-level <li> items from the directory HTML.
     *
     * Each <li> starts with an external link (the site URL + name), followed by
     * optional description text and sub-lists for plugins/modules.
     *
     * @param 'classic'|'s' $type
     * @return list<array{name: string, url: string, type: 'classic'|'s', description: string, plugins: list<string>}>
     */
    private function parseListItems(string $html, string $type): array
    {
        // Isolate the main content <div> to avoid nav links polluting results
        if (preg_match('#<div[^>]+id=["\']content["\'][^>]*>(.*)</div>\s*</div>\s*<footer#si', $html, $m)) {
            $content = $m[1];
        } elseif (preg_match('#<div[^>]+class=["\']wrapper["\'][^>]*>(.*?)</div>\s*<footer#si', $html, $m)) {
            $content = $m[1];
        } else {
            $content = $html;
        }

        // Extract all top-level <li> blocks
        // We only want the outer <li> entries — each directory entry is a direct <li> child of the main <ul>
        $items = [];

        // Split on <li> and process each chunk
        $parts = preg_split('#<li(?:\s[^>]*)?>|</li>#i', $content);
        if ($parts === false || count($parts) < 2) {
            return [];
        }

        foreach ($parts as $i => $chunk) {
            if ($i === 0) {
                continue; // preamble before first <li>
            }

            $record = $this->parseListItem(trim($chunk), $type);
            if ($record !== null) {
                $items[] = $record;
            }
        }

        return $items;
    }

    /**
     * Parse a single <li> chunk into a directory record, or null if it doesn't
     * look like a site entry.
     *
     * @param 'classic'|'s' $type
     * @return array{name: string, url: string, type: 'classic'|'s', description: string, plugins: list<string>}|null
     */
    private function parseListItem(string $chunk, string $type): ?array
    {
        // Must start with an anchor tag pointing to an external http URL
        if (!preg_match('#^\s*<a\s+href="(https?://[^"]+)"[^>]*>([^<]+)</a>#i', $chunk, $m)) {
            return null;
        }

        $url  = trim($m[1]);
        $name = trim(strip_tags($m[2]));

        // Skip omeka.org internal/navigation links
        if ($this->isInternalUrl($url)) {
            return null;
        }

        // Must look like a real site name (not a lone punctuation or nav label)
        if (strlen($name) < 3 || in_array($name, ['Forums', 'Github', 'GitHub', 'Omeka.net'], true)) {
            return null;
        }

        // For Omeka S, strip sub-site path to get the installation root
        // e.g. https://exhibits.lib.utah.edu/s/1918-flu-pandemic-in-utah/page/welcome
        //   -> https://exhibits.lib.utah.edu
        $siteUrl = $type === 's' ? $this->resolveInstallationRoot($url) : $this->resolveClassicRoot($url);

        // Extract description: text after the first </a>, before any nested <ul>
        $afterLink = substr($chunk, strpos($chunk, '</a>') + 4);
        $descRaw   = preg_replace('#<ul.*#si', '', $afterLink) ?? '';
        $desc      = trim(strip_tags($descRaw));
        $desc      = preg_replace('#\s+#', ' ', $desc) ?? $desc;
        // Strip leading punctuation like ". " or ", "
        $desc = ltrim($desc, " \t\n\r\0\x0B.,;:-");

        // Extract plugins/modules list (nested <ul> after "Plugins:" or "Modules:")
        $plugins = $this->extractPluginList($chunk);

        return [
            'name'        => $name,
            'url'         => $siteUrl,
            'listedUrl'   => $url,  // original URL from the directory listing
            'type'        => $type,
            'description' => trim($desc),
            'plugins'     => $plugins,
        ];
    }

    /**
     * For Omeka S sites: strip the /s/<slug>/... sub-site path to find the
     * installation root where /api lives.
     *
     * Examples:
     *   https://exhibits.lib.utah.edu/s/1918-flu-pandemic-in-utah/page/welcome
     *     → https://exhibits.lib.utah.edu
     *   https://www.omeka.ugent.be/interieurdesign/s/plaatsdelict/page/welcome
     *     → https://www.omeka.ugent.be/interieurdesign
     *   https://iaamcfh.omeka.net/
     *     → https://iaamcfh.omeka.net
     */
    private function resolveInstallationRoot(string $url): string
    {
        // Pattern: strip everything from /s/<slug> onward
        if (preg_match('#^(https?://[^/]+(?:/[^/]+)*?)/s/[^/]+#i', $url, $m)) {
            return rtrim($m[1], '/');
        }

        // Pattern: strip /page/ paths
        if (preg_match('#^(https?://[^/]+(?:/[^/]+)*?)/page/#i', $url, $m)) {
            return rtrim($m[1], '/');
        }

        return rtrim($url, '/');
    }

    /**
     * For Omeka Classic sites: the API is typically at the installation root.
     * Strip item/collection/exhibit paths if present.
     *
     * Examples:
     *   https://abbotcollections.andover.edu/            → https://abbotcollections.andover.edu
     *   http://msstate-exhibits.libraryhost.com/exhibits/show/legislators
     *     → http://msstate-exhibits.libraryhost.com
     */
    private function resolveClassicRoot(string $url): string
    {
        static $classicPaths = ['/items/', '/collections/', '/exhibits/', '/files/', '/tags/', '/show/'];

        foreach ($classicPaths as $path) {
            $pos = strpos($url, $path);
            if ($pos !== false) {
                return rtrim(substr($url, 0, $pos), '/');
            }
        }

        return rtrim($url, '/');
    }

    /**
     * Extract plugin/module names from a nested <ul> within a list item.
     *
     * @return list<string>
     */
    private function extractPluginList(string $chunk): array
    {
        // Look for "Plugins:" or "Modules:" followed by text content
        if (!preg_match('#(?:Plugins?|Modules?)\s*:\s*(.*?)(?:</ul>|$)#si', $chunk, $m)) {
            return [];
        }

        $raw = strip_tags($m[1]);
        // Split on comma or semicolon
        $parts = preg_split('#[,;]+#', $raw) ?: [];

        $plugins = [];
        foreach ($parts as $part) {
            $clean = trim(preg_replace('#\s+#', ' ', $part) ?? $part);
            $clean = trim($clean, " \t\n\r\0\x0B.,;:-()[]");
            if ($clean !== '' && strlen($clean) > 1 && strlen($clean) < 80) {
                $plugins[] = $clean;
            }
        }

        return $plugins;
    }

    private function isInternalUrl(string $url): bool
    {
        foreach (self::SKIP_HOSTS as $host) {
            if (str_contains($url, $host)) {
                return true;
            }
        }

        return false;
    }
}
