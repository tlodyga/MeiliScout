<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Indexables;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\Indexable;
use WP_Term;

use function get_term_link;
use function get_term_meta;
use function get_terms;
use function maybe_unserialize;
use function update_termmeta_cache;
use function wp_cache_get;

class TaxonomyIndexable implements Indexable
{
    private array $metaKeys = [];

    /**
     * Preloaded meta cache indexed by term ID.
     * @var array<int, array<string, mixed>>
     */
    private array $preloadedMeta = [];

    /**
     * Whether batch data has been preloaded.
     */
    private bool $batchPreloaded = false;

    public function getIndexName(): string
    {
        return 'taxonomies';
    }

    public function getPrimaryKey(): string
    {
        return 'term_id';
    }

    public function getIndexSettings(): array
    {
        return [
            'filterableAttributes' => ['taxonomy'],
            'sortableAttributes' => ['name'],
            'searchableAttributes' => ['name', 'description'],
        ];
    }

    public function getItems(?int $offset = null, ?int $limit = null): iterable
    {
        $taxonomies = Settings::get('indexed_taxonomies', []);

        // Fallback intelligent: use configured meta keys if set, otherwise gather from DB
        $configuredMetaKeys = Settings::get('indexed_meta_keys', []);
        $this->metaKeys = !empty($configuredMetaKeys)
            ? $configuredMetaKeys
            : $this->gatherMetaKeysFromTaxonomies($taxonomies);

        $totalYielded = 0;
        $maxItems = $limit ?? PHP_INT_MAX;

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'offset' => $offset ?? 0,
                'number' => $limit ?? 0, // 0 means no limit in get_terms
            ]);

            foreach ($terms as $term) {
                // Stop if we've reached the limit
                if ($totalYielded >= $maxItems) {
                    return;
                }

                yield $term;
                $totalYielded++;
            }
        }
    }

    /**
     * Gathers meta keys from taxonomies (renamed to avoid conflict).
     */
    private function gatherMetaKeysFromTaxonomies(array $taxonomies): array
    {
        global $wpdb;

        if (empty($taxonomies)) {
            return [];
        }

        $escapedTaxonomies = array_map(fn($taxonomy) => esc_sql((string) $taxonomy), $taxonomies);
        $taxonomiesStr = "'" . implode("','", $escapedTaxonomies) . "'";
        $query = "
            SELECT DISTINCT meta_key
            FROM {$wpdb->termmeta} tm
            JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
            WHERE tt.taxonomy IN ({$taxonomiesStr})
        ";

        return $wpdb->get_col($query);
    }

    public function formatForIndexing(mixed $item): array
    {
        if (! $item instanceof WP_Term) {
            throw new \InvalidArgumentException('Item must be instance of WP_Term');
        }

        // Retrieve and add the rest of the properties
        $document = get_object_vars($item);

        // Add additional fields
        $document['url'] = get_term_link($item);
        $document['metas'] = $this->getMetaData($item);

        return $document;
    }

    public function formatForSearch(array $hit): mixed
    {
        return new WP_Term((object) $hit);
    }

    private function getMetaData(WP_Term $term): array
    {
        // Use preloaded data if available (batch mode)
        if ($this->batchPreloaded && isset($this->preloadedMeta[$term->term_id])) {
            return $this->preloadedMeta[$term->term_id];
        }

        // Fallback to individual queries (single item mode)
        $meta = [];
        foreach ($this->metaKeys as $key) {
            $value = get_term_meta($term->term_id, $key, true);
            if ($value !== '') {
                // Transform keys that start with underscore for Meilisearch
                $indexKey = $this->normalizeMetaKey($key);
                // Automatic casting of numeric values
                // Skip INF, -INF, and NAN values as they cannot be JSON encoded
                if (is_numeric($value) && is_finite((float) $value)) {
                    $meta[$key] = $value + 0; // implicit cast to int or float
                } else {
                    $meta[$key] = $value;
                }
            }
        }

        return $meta;
    }

    /**
     * Normalizes a meta key for Meilisearch indexing.
     * Transforms keys starting with underscore to avoid conflicts.
     *
     * @param string $key The original meta key
     * @return string The normalized key for Meilisearch
     */
    private function normalizeMetaKey(string $key): string
    {
        // If the key starts with underscore, transform it
        if (str_starts_with($key, '_')) {
            return 'underscore_' . substr($key, 1);
        }

        return $key;
    }

    /**
     * Preloads batch data for multiple terms to avoid N+1 queries.
     *
     * This method should be called before formatting a batch of terms.
     * It preloads all meta in bulk queries.
     *
     * @param WP_Term[] $terms Array of terms to preload data for
     */
    public function preloadBatchData(array $terms): void
    {
        if (empty($terms)) {
            return;
        }

        $termIds = array_map(fn($term) => $term->term_id, $terms);
        $taxonomies = array_unique(array_map(fn($term) => $term->taxonomy, $terms));

        // Ensure meta keys are set from settings or gathered from DB
        if (empty($this->metaKeys)) {
            $configuredMetaKeys = Settings::get('indexed_meta_keys', []);
            $this->metaKeys = !empty($configuredMetaKeys)
                ? $configuredMetaKeys
                : $this->gatherMetaKeysFromTaxonomies($taxonomies);
        }

        // Preload meta cache using WordPress core function
        update_termmeta_cache($termIds);

        // Build preloaded meta array from cache
        $this->preloadedMeta = [];
        foreach ($terms as $term) {
            $this->preloadedMeta[$term->term_id] = $this->buildMetaFromCache($term->term_id);
        }

        $this->batchPreloaded = true;
    }

    /**
     * Builds meta array from WordPress cache.
     *
     * @param int $termId The term ID
     * @return array<string, mixed> Meta data array
     */
    private function buildMetaFromCache(int $termId): array
    {
        $meta = [];
        $cachedMeta = wp_cache_get($termId, 'term_meta');

        if ($cachedMeta === false) {
            // Fallback to regular queries
            foreach ($this->metaKeys as $key) {
                $value = get_term_meta($termId, $key, true);
                if ($value === '') {
                    continue;
                }
                $indexKey = $this->normalizeMetaKey($key);
                // Skip INF, -INF, and NAN values as they cannot be JSON encoded
                $meta[$key] = (is_numeric($value) && is_finite((float) $value)) ? $value + 0 : $value;
            }
        } else {
            foreach ($this->metaKeys as $key) {
                if (! isset($cachedMeta[$key])) {
                    continue;
                }

                $value = maybe_unserialize($cachedMeta[$key][0] ?? '');
                if ($value === '') {
                    continue;
                }

                // Skip INF, -INF, and NAN values as they cannot be JSON encoded
                $meta[$key] = (is_numeric($value) && is_finite((float) $value)) ? $value + 0 : $value;
            }
        }

        return $meta;
    }

    /**
     * Clears the preloaded batch data.
     *
     * Call this after processing a batch to free memory.
     */
    public function clearBatchData(): void
    {
        $this->preloadedMeta = [];
        $this->batchPreloaded = false;
    }

    /**
     * Sets the meta keys to use for indexing.
     *
     * @param array $metaKeys Array of meta key names
     */
    public function setMetaKeys(array $metaKeys): void
    {
        $this->metaKeys = $metaKeys;
    }

    /**
     * Gets the current meta keys.
     *
     * @return array Array of meta key names
     */
    public function getMetaKeys(): array
    {
        return $this->metaKeys;
    }
}
