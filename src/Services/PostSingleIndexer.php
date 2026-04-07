<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Exception;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\Indexable;
use Pollora\MeiliScout\Indexables\PostIndexable;
use WP_Post;

use function apply_filters;
use function get_post;
use function get_posts;
use function in_array;

/**
 * Service for managing single post indexing operations in Meilisearch.
 *
 * This service handles real-time indexing of individual WordPress posts
 * when they are created, updated, or deleted. It extends the abstract
 * single indexer to provide post-specific functionality.
 *
 * @package Pollora\MeiliScout\Services
 * @since 1.0.0
 */
class PostSingleIndexer extends AbstractSingleIndexer
{
    /**
     * Creates the PostIndexable instance for formatting post data.
     *
     * @return Indexable The post indexable instance
     */
    protected function createIndexable(): Indexable
    {
        return new PostIndexable();
    }

    /**
     * Gets the WordPress option key for storing post indexing operation logs.
     *
     * @return string The option key for post operation logs
     */
    protected function getLogOptionKey(): string
    {
        return 'meiliscout/post_single_indexer_log';
    }

    /**
     * Checks if a post should be indexed based on plugin configuration.
     *
     * This method verifies that the post type is configured for indexing
     * and that the post status allows for indexing.
     *
     * @param mixed $item The post to check (WP_Post object or post ID)
     * @return bool True if the post should be indexed, false otherwise
     */
    protected function shouldIndex(mixed $item): bool
    {
        $post = $this->normalizePost($item);

        if (!$post instanceof WP_Post) {
            return false;
        }

        // Check if post type should be indexed
        if (!$this->shouldIndexPostType($post->post_type)) {
            return false;
        }

        // Check if post status allows indexing
        return $this->shouldIndexPostStatus($post->post_status);
    }

    /**
     * Gets the unique identifier for a post.
     *
     * @param mixed $item The post (WP_Post object or post ID)
     * @return int|string The post ID
     */
    protected function getItemId(mixed $item): int|string
    {
        $post = $this->normalizePost($item);
        return $post instanceof WP_Post ? $post->ID : (int) $item;
    }

    /**
     * Gets a human-readable name for a post for logging purposes.
     *
     * @param mixed $item The post (WP_Post object or post ID)
     * @return string The post title or a default description
     */
    protected function getItemName(mixed $item): string
    {
        $post = $this->normalizePost($item);
        return $post instanceof WP_Post ? $post->post_title : "Post ID: {$item}";
    }

    /**
     * Indexes or updates a single post in Meilisearch.
     *
     * This method extends the parent indexItem method to handle post-specific
     * logic, including automatic removal of posts that should no longer be indexed.
     *
     * @param int|WP_Post $post The post ID or WP_Post object to index
     * @return bool True if the post was successfully processed, false otherwise
     *
     * @throws Exception If there's an error during the indexing process
     */
    public function indexPost(int|WP_Post $post): bool
    {
        $postObject = $this->normalizePost($post);

        if (!$postObject instanceof WP_Post) {
            $this->logOperation('error', "Post with ID {$post} not found");
            return false;
        }

        // If post should not be indexed (wrong type or status), remove it from index
        if (!$this->shouldIndex($postObject)) {
            if (!$this->shouldIndexPostType($postObject->post_type)) {
                $this->logOperation('info', "Post type '{$postObject->post_type}' is not configured for indexing");
                return true; // Not an error
            }

            if (!$this->shouldIndexPostStatus($postObject->post_status)) {
                // Remove from index if it exists there
                return $this->removePost($postObject->ID);
            }
        }

        return $this->indexItem($postObject);
    }

    /**
     * Removes a post from the Meilisearch index.
     *
     * This method provides a post-specific interface for removing posts
     * from the search index.
     *
     * @param int $postId The ID of the post to remove
     * @return bool True if the post was successfully removed, false otherwise
     */
    public function removePost(int $postId): bool
    {
        return $this->removeItem($postId);
    }

    /**
     * Re-indexes all posts that are associated with a specific taxonomy term.
     *
     * This method is useful when a taxonomy term is updated, as it ensures
     * that all posts using that term have their search index updated with
     * the new term information.
     *
     * @param int $termId The ID of the term whose associated posts should be re-indexed
     * @param string $taxonomy The taxonomy of the term
     * @return int The number of posts that were re-indexed
     */
    public function reindexPostsForTerm(int $termId, string $taxonomy): int
    {
        $reindexedCount = 0;

        try {
            // Get all posts that have this term
            $posts = get_posts([
                'post_type' => $this->getIndexedPostTypes(),
                'posts_per_page' => -1,
                'post_status' => $this->getIndexablePostStatuses(),
                'tax_query' => [
                    [
                        'taxonomy' => $taxonomy,
                        'field'    => 'term_id',
                        'terms'    => $termId,
                    ],
                ],
            ]);

            foreach ($posts as $post) {
                if ($this->indexPost($post)) {
                    $reindexedCount++;
                }
            }

            $this->logOperation('success', "Re-indexed {$reindexedCount} posts for term {$termId} in taxonomy {$taxonomy}");

        } catch (Exception $e) {
            $this->logOperation('error', "Failed to re-index posts for term {$termId}: " . $e->getMessage());
        }

        return $reindexedCount;
    }

    /**
     * Normalizes input to a WP_Post object.
     *
     * @param int|WP_Post $post The post ID or WP_Post object
     * @return WP_Post|null The WP_Post object or null if not found
     */
    private function normalizePost(int|WP_Post $post): ?WP_Post
    {
        if ($post instanceof WP_Post) {
            return $post;
        }

        $postObject = get_post($post);
        return $postObject instanceof WP_Post ? $postObject : null;
    }

    /**
     * Checks if a post type should be indexed based on plugin settings.
     *
     * @param string $postType The post type to check
     * @return bool True if the post type should be indexed, false otherwise
     */
    private function shouldIndexPostType(string $postType): bool
    {
        $indexedPostTypes = Settings::get('indexed_post_types', []);
        return in_array($postType, $indexedPostTypes, true);
    }

    /**
     * Checks if a post status allows indexing.
     *
     * Only certain post statuses are indexed (typically 'publish').
     * This can be extended through WordPress filters.
     *
     * @param string $postStatus The post status to check
     * @return bool True if posts with this status should be indexed, false otherwise
     */
    private function shouldIndexPostStatus(string $postStatus): bool
    {
        $indexableStatuses = $this->getIndexablePostStatuses();
        return in_array($postStatus, $indexableStatuses, true);
    }

    /**
     * Gets the list of post statuses that should be indexed.
     *
     * @return string[] Array of indexable post statuses
     */
    private function getIndexablePostStatuses(): array
    {
        /**
         * Filters the post statuses that should be indexed.
         *
         * @since 1.0.0
         * @param string[] $statuses Array of post statuses that should be indexed
         */
        return apply_filters('meiliscout/indexable_post_statuses', ['publish']);
    }

    /**
     * Gets the list of post types that are configured for indexing.
     *
     * @return string[] Array of indexed post types
     */
    private function getIndexedPostTypes(): array
    {
        return Settings::get('indexed_post_types', []);
    }

    /**
     * Indexes multiple posts in batch with optimized performance.
     *
     * This method uses bulk preloading and sends all documents to Meilisearch
     * in a single API call for maximum efficiency.
     *
     * @param WP_Post[]|int[] $posts Array of post objects or post IDs
     * @return array{indexed: int, skipped: int, errors: int} Statistics about the batch operation
     */
    public function indexPosts(array $posts, ?Indexable $indexable): array
    {
        if ($indexable) {
            $this->indexable = $indexable;
        }

        $statistics = [
            'indexed' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        if (empty($posts)) {
            return $statistics;
        }

        // Normalize all posts first
        $normalizedPosts = [];
        $postsToIndex = [];

        foreach ($posts as $post) {
            $postObject = $this->normalizePost($post);
            if (! $postObject instanceof WP_Post) {
                $statistics['errors']++;
                continue;
            }

            $normalizedPosts[] = $postObject;

            // Check if should be indexed
            if ($this->shouldIndex($postObject)) {
                $postsToIndex[] = $postObject;
            } else {
                $statistics['skipped']++;
            }
        }

        if (empty($postsToIndex)) {
            return $statistics;
        }

        try {
            // Ensure index exists once for the entire batch
            $this->ensureIndexExistsOnce();

            // Get the indexable
            /** @var \Pollora\MeiliScout\Indexables\PostIndexable $indexable */
            $indexable = $this->indexable;

            // Format all documents
            $documents = [];
            foreach ($postsToIndex as $post) {
                try {
                    $documents[] = $indexable->formatForIndexing($post);
                } catch (Exception $e) {
                    $statistics['errors']++;
                    $this->logOperation('error', "Failed to format post {$post->ID}: " . $e->getMessage());
                }
            }

            // Send all documents in a single API call
            if (! empty($documents)) {
                $index = $this->client->index($indexable->getIndexName());
                $index->addDocuments($documents);
                $statistics['indexed'] = count($documents);
            }

            // Aggressive memory cleanup after batch
            unset($documents, $postsToIndex);
            gc_collect_cycles();

        } catch (Exception $e) {
            $statistics['errors'] += count($postsToIndex) - $statistics['indexed'];
            $this->logOperation('error', "Batch indexing failed: " . $e->getMessage());
        }

        return $statistics;
    }

    /**
     * Ensures the index exists, but only checks once per session.
     */
    private bool $indexChecked = false;

    private function ensureIndexExistsOnce(): void
    {
        if ($this->indexChecked) {
            return;
        }

        $this->ensureIndexExists();
        $this->indexChecked = true;
    }
}
