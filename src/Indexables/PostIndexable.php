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
use function wp_get_post_terms;

class PostIndexable implements Indexable
{
    private array $metaKeys = [];

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
                'post_date',
                ...array_map(fn($key) => "metas.{$key}", $filterableMetaKeys),
            ]),
        ];
    }

    public function getItems(): iterable
    {
        $postTypes = Settings::get('indexed_post_types', []);
        $this->metaKeys = $this->gatherMetaKeys($postTypes);

        foreach ($postTypes as $postType) {
            $posts = get_posts([
                'post_type' => $postType,
                'posts_per_page' => -1,
                'post_status' => 'any',
            ]);

            foreach ($posts as $post) {
                yield $post;
            }
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
        $meta = [];
        foreach ($this->metaKeys as $key) {
            $value = get_post_meta($post->ID, $key, true);

            if ($value === '' || $value === null) {
                continue;
            }

            // Automatic casting of numeric values
            if (is_numeric($value)) {
                $meta[$key] = $value + 0;
            } else {
                $meta[$key] = $value;
            }
        }

        return $meta;
    }
}
