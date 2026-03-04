<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Indexables;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\Indexable;
use WP_Post;
use WP_Term;

use function get_object_taxonomies;
use function get_permalink;
use function get_post_meta;
use function get_posts;
use function get_term;
use function maybe_unserialize;
use function update_meta_cache;
use function update_object_term_cache;
use function wp_cache_get;
use function wp_get_post_terms;

class PostIndexable implements Indexable
{
    private array $metaKeys = [];

    /**
     * Preloaded terms cache indexed by post ID.
     * @var array<int, array<array<string, mixed>>>
     */
    private array $preloadedTerms = [];

    /**
     * Preloaded meta cache indexed by post ID.
     * @var array<int, array<string, mixed>>
     */
    private array $preloadedMeta = [];

    /**
     * Whether batch data has been preloaded.
     */
    private bool $batchPreloaded = false;

    public function getIndexName(): string
    {
        return 'posts';
    }

    public function getPrimaryKey(): string
    {
        return 'ID';
    }

    public function getIndexSettings(): array
    {
        $postTypes = Settings::get('indexed_post_types', []);
        $filterableMetaKeys = Settings::get('indexed_meta_keys', []);

        $filterableAttributes = [
            'post_type',
            'post_status',
        ];

        foreach ($filterableMetaKeys as $metaKey) {
            $filterableAttributes[] = "metas.{$metaKey}";
        }

        // Filterable fields from terms (facets)
        $filterableAttributes[] = 'terms.term_id';
        $filterableAttributes[] = 'terms.slug';
        $filterableAttributes[] = 'terms.name';
        $filterableAttributes[] = 'terms.taxonomy';
        $filterableAttributes[] = 'terms.term_taxonomy_id';

        return [
            'filterableAttributes' => array_unique($filterableAttributes),
            'sortableAttributes' => array_unique([
                'post_title',
                'post_date',
                ...array_map(fn($key) => "metas.{$key}", $filterableMetaKeys),
            ]),
        ];
    }

    public function getItems(?int $offset = null, ?int $limit = null): iterable
    {
        $postTypes = Settings::get('indexed_post_types', []);

        // Fallback intelligent: use configured meta keys if set, otherwise gather from DB
        $configuredMetaKeys = Settings::get('indexed_meta_keys', []);
        $this->metaKeys = !empty($configuredMetaKeys)
            ? $configuredMetaKeys
            : $this->gatherMetaKeys($postTypes);

        $postsPerPage = Settings::get('indexing.posts_per_page', 200);

        // Track total items yielded for offset/limit support
        $totalYielded = 0;
        $maxItems = $limit ?? PHP_INT_MAX;

        foreach ($postTypes as $postType) {
            $page = 1;

            // Calculate starting page if offset is provided
            if ($offset !== null) {
                $page = (int) floor($offset / $postsPerPage) + 1;
            }

            do {
                $posts = get_posts([
                    'post_type' => $postType,
                    'posts_per_page' => $postsPerPage,
                    'paged' => $page,
                    'post_status' => 'any',
                    'orderby' => 'ID',
                    'order' => 'ASC',
                    'suppress_filters' => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                    'no_found_rows' => true,
                    'cache_results' => false,
                ]);

                foreach ($posts as $post) {
                    $currentGlobalOffset = ($page - 1) * $postsPerPage + array_search($post, $posts, true);

                    if ($offset !== null && $currentGlobalOffset < $offset) {
                        continue;
                    }

                    if ($totalYielded >= $maxItems) {
                        return;
                    }

                    yield $post;
                    $totalYielded++;
                }

                $page++;

                if ($totalYielded >= $maxItems) {
                    return;
                }

                // Flush cache periodically to prevent memory leaks (every 10 pages)
                if ($page % 10 === 0) {
                    wp_cache_flush();
                    gc_collect_cycles();
                }

            } while (count($posts) === $postsPerPage);
        }
    }

    private function gatherMetaKeys(array $postTypes): array
    {
        global $wpdb;

        if (empty($postTypes)) {
            return [];
        }

        $escapedTypes = array_map(fn($type) => esc_sql((string) $type), $postTypes);
        $postTypesStr = "'" . implode("','", $escapedTypes) . "'";

        $query = "
            SELECT DISTINCT meta_key
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type IN ({$postTypesStr})
        ";

        return $wpdb->get_col($query);
    }

    /**
     * Preloads batch data for multiple posts to avoid N+1 queries.
     *
     * This method should be called before formatting a batch of posts.
     * It preloads all meta and terms in bulk queries.
     *
     * @param WP_Post[] $posts Array of posts to preload data for
     */
    public function preloadBatchData(array $posts): void
    {
        if (empty($posts)) {
            return;
        }

        $postIds = array_map(fn($post) => $post->ID, $posts);
        $postTypes = array_unique(array_map(fn($post) => $post->post_type, $posts));

        // Ensure meta keys are set from settings or gathered from DB
        if (empty($this->metaKeys)) {
            $configuredMetaKeys = Settings::get('indexed_meta_keys', []);
            $this->metaKeys = !empty($configuredMetaKeys)
                ? $configuredMetaKeys
                : $this->gatherMetaKeys($postTypes);
        }

        // Preload meta cache using WordPress core function
        update_meta_cache('post', $postIds);

        // Preload terms cache using WordPress core function
        update_object_term_cache($postIds, $postTypes);

        // Build preloaded terms array from cache
        $this->preloadedTerms = [];
        foreach ($posts as $post) {
            $this->preloadedTerms[$post->ID] = $this->buildTermsFromCache($post);
        }

        // Build preloaded meta array from cache
        $this->preloadedMeta = [];
        foreach ($posts as $post) {
            $this->preloadedMeta[$post->ID] = $this->buildMetaFromCache($post->ID);
        }

        $this->batchPreloaded = true;
    }

    /**
     * Builds terms array from WordPress cache.
     *
     * @param WP_Post $post The post
     * @return array<array<string, mixed>> Flattened terms array
     */
    private function buildTermsFromCache(WP_Post $post): array
    {
        $terms = [];
        $taxonomies = get_object_taxonomies($post->post_type);

        foreach ($taxonomies as $taxonomy) {
            // wp_get_object_terms uses cache when available
            $cachedTerms = wp_cache_get($post->ID, "{$taxonomy}_relationships");

            if ($cachedTerms === false) {
                // Fallback to regular query if not in cache
                $rawTerms = wp_get_post_terms($post->ID, $taxonomy);
            } else {
                // Get term objects from cached term IDs
                $rawTerms = [];
                foreach ((array) $cachedTerms as $termId) {
                    $term = get_term($termId, $taxonomy);
                    if ($term instanceof WP_Term) {
                        $rawTerms[] = $term;
                    }
                }
            }

            foreach ($rawTerms as $term) {
                if (! $term instanceof WP_Term) {
                    continue;
                }

                $terms[] = [
                    'term_id' => (int) $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'taxonomy' => $term->taxonomy,
                    'term_taxonomy_id' => (int) $term->term_taxonomy_id,
                    'parent' => (int) $term->parent,
                ];
            }
        }

        return $terms;
    }

    /**
     * Builds meta array from WordPress cache.
     *
     * @param int $postId The post ID
     * @return array<string, mixed> Meta data array
     */
    private function buildMetaFromCache(int $postId): array
    {
        $meta = [];
        $cachedMeta = wp_cache_get($postId, 'post_meta');

        if ($cachedMeta === false) {
            // Fallback to regular queries
            foreach ($this->metaKeys as $key) {
                $value = get_post_meta($postId, $key, true);
                if ($value === '' || $value === null) {
                    continue;
                }
                // Skip INF, -INF, and NAN values as they cannot be JSON encoded
                $meta[$key] = (is_numeric($value) && is_finite((float) $value)) ? $value + 0 : $value;
            }
        } else {
            foreach ($this->metaKeys as $key) {
                if (! isset($cachedMeta[$key])) {
                    continue;
                }

                $value = maybe_unserialize($cachedMeta[$key][0] ?? '');
                if ($value === '' || $value === null) {
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
        $this->preloadedTerms = [];
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

    public function formatForIndexing(mixed $item): array
    {
        if (! $item instanceof WP_Post) {
            throw new \InvalidArgumentException('Item must be instance of WP_Post');
        }

        $document = get_object_vars($item);

        $document['url'] = get_permalink($item);
        $document['terms'] = $this->getFlattenedTerms($item);
        $document['metas'] = $this->getMetaData($item);

        return apply_filters('meiliscout/post/document', $document, $item);
    }

    public function formatForSearch(array $hit): mixed
    {
        return new WP_Post((object) $hit);
    }

    private function getFlattenedTerms(WP_Post $post): array
    {
        // Use preloaded data if available (batch mode)
        if ($this->batchPreloaded && isset($this->preloadedTerms[$post->ID])) {
            return $this->preloadedTerms[$post->ID];
        }

        // Fallback to individual queries (single item mode)
        $terms = [];
        $taxonomies = get_object_taxonomies($post->post_type);

        foreach ($taxonomies as $taxonomy) {
            $rawTerms = wp_get_post_terms($post->ID, $taxonomy);
            foreach ($rawTerms as $term) {
                if (! $term instanceof WP_Term) {
                    continue;
                }

                $terms[] = [
                    'term_id' => (int) $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'taxonomy' => $term->taxonomy,
                    'term_taxonomy_id' => (int) $term->term_taxonomy_id,
                    'parent' => (int) $term->parent,
                ];
            }
        }

        return $terms;
    }

    private function getMetaData(WP_Post $post): array
    {
        // Use preloaded data if available (batch mode)
        if ($this->batchPreloaded && isset($this->preloadedMeta[$post->ID])) {
            return $this->preloadedMeta[$post->ID];
        }

        // Fallback to individual queries (single item mode)
        $meta = [];
        foreach ($this->metaKeys as $key) {
            $value = get_post_meta($post->ID, $key, true);

            if ($value === '' || $value === null) {
                continue;
            }

            // Automatic casting of numeric values
            // Skip INF, -INF, and NAN values as they cannot be JSON encoded
            if (is_numeric($value) && is_finite((float) $value)) {
                $meta[$key] = $value + 0;
            } else {
                $meta[$key] = $value;
            }
        }

        return $meta;
    }
}
