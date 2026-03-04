<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Config\Config;
use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Services\AsyncIndexingQueue;
use Pollora\MeiliScout\Services\PostSingleIndexer;
use Pollora\MeiliScout\Services\TaxonomySingleIndexer;

use function add_action;
use function apply_filters;
use function get_post;
use function get_term;

/**
 * Service provider for managing automatic single-item indexing operations.
 *
 * This service provider registers WordPress hooks to automatically index
 * posts and taxonomy terms when they are created, updated, or deleted.
 * It ensures the search index stays synchronized with content changes.
 *
 * @package Pollora\MeiliScout\Providers
 * @since 1.0.0
 */
class SingleIndexingServiceProvider extends ServiceProvider
{
    /**
     * Post single indexer instance.
     *
     * @var PostSingleIndexer|null
     */
    private ?PostSingleIndexer $postIndexer = null;

    /**
     * Taxonomy single indexer instance.
     *
     * @var TaxonomySingleIndexer|null
     */
    private ?TaxonomySingleIndexer $taxonomyIndexer = null;

    /**
     * Async indexing queue instance (null when in sync mode).
     *
     * @var AsyncIndexingQueue|null
     */
    private ?AsyncIndexingQueue $asyncQueue = null;

    /**
     * Register the service provider.
     *
     * This method sets up all WordPress hooks for automatic indexing
     * of posts and taxonomy terms.
     *
     * @return void
     */
    public function register(): void
    {
        // Initialize indexers lazily
        $this->postIndexer = new PostSingleIndexer();
        $this->taxonomyIndexer = new TaxonomySingleIndexer();

        if ($this->isAsyncMode()) {
            $this->asyncQueue = new AsyncIndexingQueue($this->postIndexer, $this->taxonomyIndexer);
            // A custom provider may have registered its own processor
            // with the correct indexer. In that case skip registering the default one.
            if (apply_filters('meiliscout/register_async_queue_processor', true)) {
                add_action('meiliscout_process_async_queue', [$this->asyncQueue, 'process']);
            }
        }

        $this->registerPostHooks();
        $this->registerTaxonomyHooks();
    }

    /**
     * Registers WordPress hooks for post indexing operations.
     *
     * This method sets up hooks for post creation, updates, deletions,
     * and status changes to keep the search index synchronized.
     *
     * @return void
     */
    private function registerPostHooks(): void
    {
        // Hook for post saves (create and update)
        add_action('save_post', [$this, 'handlePostSave'], 10, 3);

        // Hook for post deletions
        add_action('delete_post', [$this, 'handlePostDelete'], 10, 1);

        // Hook for post status transitions
        add_action('transition_post_status', [$this, 'handlePostStatusChange'], 10, 3);

        // Hook for post meta updates (for indexed meta fields)
        add_action('updated_post_meta', [$this, 'handlePostMetaUpdate'], 10, 4);
        add_action('added_post_meta', [$this, 'handlePostMetaUpdate'], 10, 4);
        add_action('deleted_post_meta', [$this, 'handlePostMetaUpdate'], 10, 4);
    }

    /**
     * Registers WordPress hooks for taxonomy term indexing operations.
     *
     * This method sets up hooks for term creation, updates, and deletions
     * to keep the taxonomy search index synchronized.
     *
     * @return void
     */
    private function registerTaxonomyHooks(): void
    {
        // Hook for term creation and updates
        add_action('created_term', [$this, 'handleTermSave'], 10, 3);
        add_action('edited_term', [$this, 'handleTermSave'], 10, 3);

        // Hook for term deletions
        add_action('delete_term', [$this, 'handleTermDelete'], 10, 4);

        // Hook for term meta updates
        add_action('updated_term_meta', [$this, 'handleTermMetaUpdate'], 10, 4);
        add_action('added_term_meta', [$this, 'handleTermMetaUpdate'], 10, 4);
        add_action('deleted_term_meta', [$this, 'handleTermMetaUpdate'], 10, 4);
    }

    /**
     * Handles post save operations (create and update).
     *
     * This method is called when a post is saved and determines whether
     * it should be indexed, updated, or removed from the index.
     *
     * @param int $postId The ID of the post being saved
     * @param \WP_Post $post The post object being saved
     * @param bool $update Whether this is an update (true) or new post (false)
     * @return void
     */
    public function handlePostSave(int $postId, \WP_Post $post, bool $update): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        // Skip if this is an autosave, revision, or auto-draft
        if ($this->shouldSkipPostOperation($postId, $post)) {
            return;
        }

        try {
            if ($this->isAsyncMode()) {
                $this->asyncQueue->enqueue('post', 'index', $postId);
                return;
            }
            $this->postIndexer->indexPost($post);
        } catch (\Exception $e) {
            // Log error but don't break the save process
            error_log("MeiliScout: Failed to index post {$postId}: " . $e->getMessage());
        }
    }

    /**
     * Handles post deletion operations.
     *
     * This method removes the post from the search index when it's deleted.
     *
     * @param int $postId The ID of the post being deleted
     * @return void
     */
    public function handlePostDelete(int $postId): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        try {
            if ($this->isAsyncMode()) {
                $this->asyncQueue->enqueue('post', 'remove', $postId);
                return;
            }
            $this->postIndexer->removePost($postId);
        } catch (\Exception $e) {
            // Log error but don't break the deletion process
            error_log("MeiliScout: Failed to remove post {$postId} from index: " . $e->getMessage());
        }
    }

    /**
     * Handles post status transitions.
     *
     * This method is called when a post's status changes (e.g., draft to publish)
     * and ensures the post is properly indexed or removed based on its new status.
     *
     * @param string $newStatus The new post status
     * @param string $oldStatus The old post status
     * @param \WP_Post $post The post object
     * @return void
     */
    public function handlePostStatusChange(string $newStatus, string $oldStatus, \WP_Post $post): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        // Skip if this is an autosave, revision, or auto-draft
        if ($this->shouldSkipPostOperation($post->ID, $post)) {
            return;
        }

        try {
            if ($this->isAsyncMode()) {
                $this->asyncQueue->enqueue('post', 'index', $post->ID);
                return;
            }
            // Always try to index - the indexer will handle whether to index or remove
            $this->postIndexer->indexPost($post);
        } catch (\Exception $e) {
            // Log error but don't break the status change process
            error_log("MeiliScout: Failed to handle status change for post {$post->ID}: " . $e->getMessage());
        }
    }

    /**
     * Handles post meta updates.
     *
     * This method re-indexes a post when its metadata is updated,
     * ensuring the search index reflects the latest meta values.
     *
     * @param int|array $metaId The ID of the meta entry
     * @param int $postId The ID of the post
     * @param string $metaKey The meta key being updated
     * @param mixed $metaValue The new meta value
     * @return void
     */
    public function handlePostMetaUpdate(int|array $metaId, int $postId, string $metaKey, mixed $metaValue): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        $post = get_post($postId);

        if (!$post instanceof \WP_Post || $this->shouldSkipPostOperation($postId, $post)) {
            return;
        }

        try {
            if ($this->isAsyncMode()) {
                $this->asyncQueue->enqueue('post', 'index', $postId);
                return;
            }
            // Re-index the post to pick up the new meta data
            $this->postIndexer->indexPost($post);
        } catch (\Exception $e) {
            // Log error but don't break the meta update process
            error_log("MeiliScout: Failed to re-index post {$postId} after meta update: " . $e->getMessage());
        }
    }

    /**
     * Handles taxonomy term save operations (create and update).
     *
     * This method is called when a term is created or updated and ensures
     * it's properly indexed in the search index.
     *
     * @param int $termId The ID of the term being saved
     * @param int $ttId The term taxonomy ID
     * @param string $taxonomy The taxonomy name
     * @return void
     */
    public function handleTermSave(int $termId, int $ttId, string $taxonomy): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        try {
            if ($this->isAsyncMode()) {
                $this->asyncQueue->enqueue('term', 'index', $termId);
                $this->asyncQueue->enqueue('posts_for_term', 'reindex', $termId, ['taxonomy' => $taxonomy]);
                return;
            }

            $result = $this->taxonomyIndexer->indexTerm($termId);

            // If the term was successfully indexed, also re-index associated posts
            if ($result) {
                $this->postIndexer->reindexPostsForTerm($termId, $taxonomy);
            }
        } catch (\Exception $e) {
            // Log error but don't break the save process
            error_log("MeiliScout: Failed to index term {$termId}: " . $e->getMessage());
        }
    }

    /**
     * Handles taxonomy term deletion operations.
     *
     * This method removes the term from the search index and re-indexes
     * any posts that were associated with the deleted term.
     *
     * @param int $termId The ID of the term being deleted
     * @param int $ttId The term taxonomy ID
     * @param string $taxonomy The taxonomy name
     * @param \WP_Term $deletedTerm The term object before deletion
     * @return void
     */
    public function handleTermDelete(int $termId, int $ttId, string $taxonomy, \WP_Term $deletedTerm): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        try {
            if ($this->isAsyncMode()) {
                $this->asyncQueue->enqueue('term', 'remove', $termId);
                $this->asyncQueue->enqueue('posts_for_term', 'reindex', $termId, ['taxonomy' => $taxonomy]);
                return;
            }

            // Remove the term from the index
            $this->taxonomyIndexer->removeTerm($termId);

            // Re-index posts that had this term to update their term data
            $this->postIndexer->reindexPostsForTerm($termId, $taxonomy);
        } catch (\Exception $e) {
            // Log error but don't break the deletion process
            error_log("MeiliScout: Failed to handle term deletion {$termId}: " . $e->getMessage());
        }
    }

    /**
     * Handles term meta updates.
     *
     * This method re-indexes a term when its metadata is updated,
     * ensuring the search index reflects the latest meta values.
     *
     * @param int|array $metaId The ID of the meta entry
     * @param int $termId The ID of the term
     * @param string $metaKey The meta key being updated
     * @param mixed $metaValue The new meta value
     * @return void
     */
    public function handleTermMetaUpdate(int|array $metaId, int $termId, string $metaKey, mixed $metaValue): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        $term = get_term($termId);

        if (!$term instanceof \WP_Term || is_wp_error($term)) {
            return;
        }

        try {
            if ($this->isAsyncMode()) {
                $this->asyncQueue->enqueue('term', 'index', $termId);
                return;
            }
            // Re-index the term to pick up the new meta data
            $this->taxonomyIndexer->indexTerm($term);
        } catch (\Exception $e) {
            // Log error but don't break the meta update process
            error_log("MeiliScout: Failed to re-index term {$termId} after meta update: " . $e->getMessage());
        }
    }

    /**
     * Determines if a post operation should be skipped.
     *
     * This method checks for various conditions where indexing should be skipped,
     * such as autosaves, revisions, auto-drafts, etc.
     *
     * @param int $postId The post ID
     * @param \WP_Post $post The post object
     * @return bool True if the operation should be skipped, false otherwise
     */
    private function shouldSkipPostOperation(int $postId, \WP_Post $post): bool
    {
        // Skip autosaves
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return true;
        }

        // Skip revisions
        if (wp_is_post_revision($postId)) {
            return true;
        }

        // Skip auto-drafts
        if ($post->post_status === 'auto-draft') {
            return true;
        }

        // Skip during AJAX requests (to avoid indexing during quick saves)
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return true;
        }

        return false;
    }

    /**
     * Determines if all indexing should be skipped globally.
     *
     * Allows external code to disable indexing in specific contexts
     * (e.g., during imports) via the 'meiliscout/skip_indexing' filter.
     *
     * @return bool True if indexing should be skipped, false otherwise
     */
    private function shouldSkipIndexing(): bool
    {
        return (bool) apply_filters('meiliscout/skip_indexing', false);
    }

    /**
     * Determines whether asynchronous indexing mode is enabled.
     *
     * Reads the MEILISCOUT_ASYNC_INDEXING environment variable via Config.
     *
     * @return bool True if async mode is active, false for synchronous (default)
     */
    private function isAsyncMode(): bool
    {
        return filter_var(Config::get('meiliscout_async_indexing', false), FILTER_VALIDATE_BOOLEAN);
    }
}
