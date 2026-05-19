<?php

declare(strict_types=1);
namespace Survos\OmekaBundle\Crawler;
use Survos\DataContracts\Util\Arrays;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function array_key_exists;
use function array_map;
use function explode;
use function is_array;
use function is_string;
use function ltrim;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtolower;
use function trim;
use function urlencode;
/**
 * Read-only crawler for any public Omeka-S installation.
 *
 * No API key required. Works against any publicly-accessible Omeka-S site.
 * The Omeka-S REST API permits anonymous access to all public resources.
 * Usage:
 *   $crawler = new OmekaPublicCrawler($httpClient);
 *   foreach ($crawler->crawlItems('https://iaamcfh.omeka.net') as $item) {
 *       // $item is a raw JSON-LD array from the Omeka-S API
 *   }
 */
final class OmekaPublicCrawler
{
    private const DEFAULT_PER_PAGE = 50;
    private const MAX_PER_PAGE = 100;
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }
    /**
     * Resolve any user-supplied URL to a clean /api base URL.
     *
     * Accepts:
     *   https://iaamcfh.omeka.net
     *   https://iaamcfh.omeka.net/
     *   https://iaamcfh.omeka.net/api
     *   https://iaamcfh.omeka.net/api/
     *   https://iaamcfh.omeka.net/api/items  (trims resource path)
     */
    public function resolveApiUrl(string $input): string
        $url = rtrim(trim($input), '/');
        // Strip any /api/<resource> suffix, leaving just /api
        if (preg_match('#^(https?://[^/]+(?:/[^/]+)*?)/api(/.*)?$#i', $url, $m)) {
            return $m[1] . '/api';
        }
        return $url . '/api';
     * Extract the hostname slug from a URL for use in derived filenames.
     * https://iaamcfh.omeka.net  →  iaamcfh.omeka.net
    public function siteSlug(string $siteUrl): string
        $url = rtrim(trim($siteUrl), '/');
        if (preg_match('#^https?://([^/]+)#', $url, $m)) {
            return $m[1];
        return str_replace(['https://', 'http://', '/'], ['', '', '-'], $url);
     * Probe a URL and detect which Omeka version is running, total item count,
     * and the license posture of the collection (sampled from the first page).
     * Returns:
     *   [
     *     'version'       => 's'|'classic'|null,
     *     'apiUrl'        => 'https://…/api'|null,
     *     'omekaVersion'  => '4.1.1'|null,
     *     'totalItems'    => 1578,
     *     'license'       => 'cc'|'pd'|'cc0'|'restricted'|'unknown'|null,
     *     'licenseDetail' => 'CC BY 4.0'|'Public Domain'|…|null,
     *     'error'         => null|'message',
     *   ]
     * License categories:
     *   'cc'         Creative Commons (any variant with attribution or SA — usable with credit)
     *   'cc0'        CC0 / Public Domain Dedication (most permissive)
     *   'pd'         Explicitly stated Public Domain (not a CC0 URI but text says so)
     *   'restricted' All rights reserved / copyright / contact required
     *   'unknown'    Items present but no rights/license metadata found in sample
     * We fetch per_page=25 in the probe request — cached, so free on repeat runs.
     * Omeka S:       response header omeka-s-total-results  + Content-Type: application/ld+json
     * Omeka Classic: response header omeka-total-results    + flat JSON with element_texts
     * @return array{version: 's'|'classic'|null, apiUrl: string|null, omekaVersion: string|null, totalItems: int, license: string|null, licenseDetail: string|null, error: string|null}
    public function detectVersion(string $siteUrl, int $timeoutSeconds = 10): array
        $apiUrl = $this->resolveApiUrl($siteUrl);
        $nullResult = static fn(string $error): array => [
            'version'       => null,
            'apiUrl'        => null,
            'omekaVersion'  => null,
            'totalItems'    => 0,
            'license'       => null,
            'licenseDetail' => null,
            'error'         => $error,
        ];
        try {
            // Fetch 25 items — enough for a license sample, cached on repeat runs
            $response = $this->httpClient->request('GET', sprintf('%s/items?per_page=25', $apiUrl), [
                'timeout' => $timeoutSeconds,
            ]);
            $status  = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            if ($status !== 200) {
                return $nullResult(sprintf('HTTP %d', $status));
            }
            /** @var array<int,array<string,mixed>> $items */
            $items = $response->toArray();
            // Omeka S: header is "omeka-s-total-results"
            if (isset($headers['omeka-s-total-results'])) {
                [$license, $detail] = $this->sampleLicenseFromOmekaS($items);
                return [
                    'version'       => 's',
                    'apiUrl'        => $apiUrl,
                    'omekaVersion'  => $headers['omeka-s-version'][0] ?? null,
                    'totalItems'    => (int) ($headers['omeka-s-total-results'][0] ?? 0),
                    'license'       => $license,
                    'licenseDetail' => $detail,
                    'error'         => null,
                ];
            // Omeka Classic: header is "omeka-total-results" (no "-s-")
            if (isset($headers['omeka-total-results'])) {
                [$license, $detail] = $this->sampleLicenseFromClassic($items);
                    'version'       => 'classic',
                    'omekaVersion'  => null,
                    'totalItems'    => (int) ($headers['omeka-total-results'][0] ?? 0),
            return $nullResult('no Omeka API headers found');
        } catch (TransportExceptionInterface $e) {
            return $nullResult($e->getMessage());
        } catch (\Throwable $e) {
     * Classify the license posture of an Omeka S item sample.
     * Looks at dcterms:license (URI-valued) and dcterms:rights (text-valued).
     * Returns [category, human-readable detail] where category is one of:
     *   'cc0' | 'cc' | 'pd' | 'restricted' | 'unknown'
     * The most permissive category found in the sample wins.
     * @param array<int,array<string,mixed>> $items
     * @return array{0: string, 1: string|null}
    public function sampleLicenseFromOmekaS(array $items): array
        $best     = 'unknown';
        $bestRank = 0;
        $detail   = null;
        foreach ($items as $item) {
            // dcterms:license is typically a URI value (@id or @value)
            foreach ($item['dcterms:license'] ?? [] as $v) {
                $val = $v['@id'] ?? $v['@value'] ?? '';
                [$cat, $label] = $this->classifyLicenseString((string) $val);
                $rank = self::LICENSE_RANK[$cat];
                if ($rank > $bestRank) {
                    $best     = $cat;
                    $bestRank = $rank;
                    $detail   = $label;
                }
            // dcterms:rights is typically free text
            foreach ($item['dcterms:rights'] ?? [] as $v) {
                $val = $v['@value'] ?? $v['@id'] ?? '';
        return [$best, $detail];
     * Classify the license posture of an Omeka Classic item sample.
     * Classic items use element_texts with element name "Rights".
    public function sampleLicenseFromClassic(array $items): array
            foreach ($item['element_texts'] ?? [] as $et) {
                $name = $et['element']['name'] ?? '';
                if ($name !== 'Rights' && $name !== 'License') {
                    continue;
                $val = $et['text'] ?? '';
     * License ranking: higher = more permissive.
     * 'unknown' is 0 (no information), not treated as permissive.
    private const LICENSE_RANK = [
        'unknown'    => 0,
        'restricted' => 1,
        'cc'         => 3,
        'pd'         => 4,
        'cc0'        => 5,
    ];
     * Classify a single rights/license string into a category and a normalised label.
     * @return array{0: 'cc0'|'cc'|'pd'|'restricted'|'unknown', 1: string|null}
    public function classifyLicenseString(string $value): array
        $lower = strtolower(trim($value));
        if ($lower === '') {
            return ['unknown', null];
        // CC0 — most permissive CC variant, essentially public domain dedication
        if (str_contains($lower, 'creativecommons.org/publicdomain/zero')
            || str_contains($lower, 'cc0')
            || str_contains($lower, 'cc zero')
        ) {
            return ['cc0', 'CC0'];
        // Public Domain (text assertion, not CC0 URI)
        if (str_contains($lower, 'public domain')
            || str_contains($lower, 'publicdomain')
            || str_contains($lower, 'no known copyright')
            return ['pd', 'Public Domain'];
        // Any Creative Commons licence (by, by-sa, by-nc, by-nd, etc.)
        if (str_contains($lower, 'creativecommons.org/licenses/')
            || preg_match('#\bcc[ -]by\b#', $lower)
            || str_contains($lower, 'creative commons')
            // Extract version slug from URI, e.g. "by-nc/4.0" → "CC BY-NC 4.0"
            $label = 'Creative Commons';
            if (preg_match('#licenses/([a-z-]+)/([0-9.]+)#', $lower, $m)) {
                $label = 'CC ' . strtoupper($m[1]) . ' ' . $m[2];
            return ['cc', $label];
        // RightsStatements.org — often "in copyright" variants
        if (str_contains($lower, 'rightsstatements.org')) {
            if (str_contains($lower, 'nocopyright') || str_contains($lower, 'noc-')) {
                return ['pd', 'No Copyright (RightsStatements)'];
            return ['restricted', 'RightsStatements: ' . $value];
        // Explicit restriction indicators
        if (str_contains($lower, 'all rights reserved')
            || str_contains($lower, 'copyright')
            || str_contains($lower, '©')
            || str_contains($lower, 'permission required')
            || str_contains($lower, 'contact')
            || str_contains($lower, 'may be protected')
            return ['restricted', $value];
        // Something is there but we can't classify it
        return ['unknown', $value];
     * List Omeka sites registered in this installation.
     * Returns ['results' => array<int,array>, 'total' => int].
    public function getSites(string $siteUrl, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
        return $this->fetchPage($this->resolveApiUrl($siteUrl), 'sites', [], $page, $perPage);
     * List item sets (collections) for this site.
    public function getItemSets(string $siteUrl, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
        return $this->fetchPage($this->resolveApiUrl($siteUrl), 'item_sets', [], $page, $perPage);
     * Lazy generator that yields every public item from the site (or one item set).
     * Each yielded value is a raw JSON-LD array as returned by the Omeka-S API.
     * The generator's return value (via ->getReturn() after exhaustion) is the
     * total number of items yielded.
     * @param string   $siteUrl   Base URL of the Omeka-S site
     * @param int|null $itemSetId Restrict to a single item set; null = all
     * @param int      $perPage   API page size (capped at MAX_PER_PAGE)
     * @return \Generator<int, array<string,mixed>, mixed, int>
    public function crawlItems(
        string $siteUrl,
        ?int $itemSetId = null,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): \Generator {
        $perPage = min($perPage, self::MAX_PER_PAGE);
        $query = [];
        if ($itemSetId !== null) {
            $query['item_set_id'] = $itemSetId;
        $page = 1;
        $yielded = 0;
        do {
            $result = $this->fetchPage($apiUrl, 'items', $query, $page, $perPage);
            $items = $result['results'];
            $total = $result['total'];
            foreach ($items as $item) {
                yield $item;
                $yielded++;
            $page++;
        } while ($yielded < $total && $items !== []);
        return $yielded;
     * Normalize a raw JSON-LD item array into a flat, sparse structure
     * suitable for direct indexing into Meilisearch, Museado, etc.
     * - Extracts the first @value from each RDF property group
     * - Multi-value fields (>1 value) become arrays
     * - Passes the result through Arrays::sparse()
     *   so the JSONL stays sparse
     * @param array<string,mixed> $raw Raw item from crawlItems()
     * @return array<string,mixed>
    public function normalizeItem(array $raw): array
        // Use the item-level thumbnail if it's a real image (not a default placeholder).
        // Default placeholders look like …/application/asset/thumbnails/default.png
        $itemThumb = $raw['thumbnail_display_urls']['medium'] ?? null;
        $isPlaceholder = $itemThumb === null
            || str_contains($itemThumb, '/application/asset/thumbnails/')
            || str_contains($itemThumb, '/asset/thumbnails/');
        // When the item-level thumbnail is a placeholder, try the first media record.
        $thumbnail = $isPlaceholder ? $this->resolveFirstMediaThumbnail($raw) : $itemThumb;
        $normalized = [
            'id'           => $raw['o:id'] ?? null,
            'url'          => $raw['@id'] ?? null,
            'title'        => $raw['o:title'] ?? null,
            'resourceType' => $this->extractType($raw['@type'] ?? []),
            'created'      => $this->extractDateValue($raw['o:created'] ?? null),
            'modified'     => $this->extractDateValue($raw['o:modified'] ?? null),
            'thumbnail'    => $thumbnail,
        // Walk every key that looks like a vocabulary property (contains ":")
        foreach ($raw as $key => $values) {
            if (!is_array($values) || !str_contains($key, ':')) {
                continue;
            // Skip structural Omeka keys we've already handled or that aren't value arrays
            if (!isset($values[0]) || !is_array($values[0]) || !array_key_exists('@value', $values[0])) {
            $flatKey = $this->flattenPropertyKey($key);
            $extracted = $this->extractPropertyValues($values);
            if ($extracted !== null) {
                $normalized[$flatKey] = $extracted;
        /** @var array<string,mixed> $result */
        $result = Arrays::sparse($normalized);
        return $result;
    // -------------------------------------------------------------------------
    // Internal helpers
     * Fetch the first media record for an item and return its medium thumbnail URL.
     * Falls back to null if there are no media, or the first media has no real thumbnail.
     * Only makes an HTTP request when the item has at least one o:media link.
    private function resolveFirstMediaThumbnail(array $raw): ?string
        $mediaLinks = $raw['o:media'] ?? [];
        if (!is_array($mediaLinks) || $mediaLinks === []) {
            return null;
        // Take the first media link: {"@id": "https://…/api/media/N"}
        $firstMediaUrl = $mediaLinks[0]['@id'] ?? null;
        if (!is_string($firstMediaUrl) || $firstMediaUrl === '') {
            $response = $this->httpClient->request('GET', $firstMediaUrl);
            /** @var array<string,mixed> $media */
            $media = $response->toArray();
        } catch (\Throwable) {
        $thumb = $media['thumbnail_display_urls']['medium'] ?? null;
        // Reject placeholder/default thumbnails from the media record too
        if (!is_string($thumb) || $thumb === ''
            || str_contains($thumb, '/application/asset/thumbnails/')
            || str_contains($thumb, '/asset/thumbnails/')
        return $thumb;
     * Fetch one page from a given API resource endpoint.
     * @param array<string,mixed> $extraQuery
     * @return array{results: array<int,array>, total: int}
    private function fetchPage(
        string $apiUrl,
        string $resource,
        array $extraQuery,
        int $page,
        int $perPage,
    ): array {
        $query = $extraQuery + [
            'page'     => $page,
            'per_page' => $perPage,
        $response = $this->httpClient->request('GET', sprintf('%s/%s', $apiUrl, $resource), [
            'query' => $query,
        ]);
        $total = (int) ($response->getHeaders()['omeka-s-total-results'][0] ?? 0);
        /** @var array<int,array<string,mixed>> $results */
        $results = $response->toArray();
        return [
            'results' => $results,
            'total'   => $total,
     * Convert a Dublin-Core-style key like "dcterms:title" or "bibo:interviewee"
     * into a camelCase flat key like "title" or "bibo_interviewee".
     * Well-known dcterms: properties are simplified (strip "dcterms:" prefix).
     * Others get a namespace prefix with underscore: "bibo:foo" → "bibo_foo".
    private function flattenPropertyKey(string $key): string
        static $knownDcTerms = [
            'dcterms:title',
            'dcterms:description',
            'dcterms:date',
            'dcterms:creator',
            'dcterms:publisher',
            'dcterms:type',
            'dcterms:language',
            'dcterms:coverage',
            'dcterms:rights',
            'dcterms:subject',
            'dcterms:format',
            'dcterms:identifier',
            'dcterms:contributor',
            'dcterms:source',
            'dcterms:relation',
        if (str_contains($key, 'dcterms:')) {
            $bare = ltrim(str_replace('dcterms:', '', $key));
            return strtolower($bare);
        // Namespace_property format for everything else
        [$ns, $prop] = explode(':', $key, 2);
        return strtolower($ns) . '_' . strtolower($prop);
     * Extract scalar value(s) from an Omeka RDF property value array.
     * Single value  → string
     * Multiple values → array<string>
     * No @value entries → null
     * @param array<int,mixed> $values
     * @return string|array<int,string>|null
    private function extractPropertyValues(array $values): string|array|null
        $extracted = [];
        foreach ($values as $v) {
            if (!is_array($v)) {
            $val = $v['@value'] ?? $v['@id'] ?? $v['value_resource_id'] ?? null;
            if ($val !== null) {
                $extracted[] = (string) $val;
        if ($extracted === []) {
        return count($extracted) === 1 ? $extracted[0] : $extracted;
     * Extract an ISO 8601 date string from an Omeka date field like:
     *   {"@value": "2024-06-15T17:40:07+00:00", "@type": "...dateTime"}
     * @param array<string,mixed>|null $field
    private function extractDateValue(?array $field): ?string
        if (!is_array($field)) {
        $val = $field['@value'] ?? null;
        return is_string($val) ? $val : null;
     * Pick the most specific RDF type from a @type array.
     * Omeka items always have "o:Item" as one type; the second type (if present)
     * is the resource class (e.g. "oc:OralHistory", "bibo:Document").
     * @param array<int,string>|string $types
    private function extractType(array|string $types): ?string
        if (is_string($types)) {
            return $types;
        foreach ($types as $type) {
            if ($type !== 'o:Item' && $type !== 'o:ItemSet') {
                return $type;
        return $types[0] ?? null;
}
