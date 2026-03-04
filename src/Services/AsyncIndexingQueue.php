<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use function add_filter;
use function apply_filters;
use function delete_option;
use function get_option;
use function update_option;
use function wp_next_scheduled;
use function wp_schedule_single_event;

/**
 * Manages an asynchronous queue for Meilisearch indexing operations.
 *
 * Operations are stored in a WordPress option and processed via a WP-Cron
 * event after a configurable delay. Duplicate operations for the same item
 * are deduplicated, keeping only the last enqueued action.
 *
 * @package Pollora\MeiliScout\Services
 * @since 1.0.0
 */
class AsyncIndexingQueue
{
    private const QUEUE_OPTION = 'meiliscout/async_queue';

    private const CRON_HOOK = 'meiliscout_process_async_queue';

    private const DEFAULT_DELAY = 300;

    public function __construct(
        private readonly PostSingleIndexer $postIndexer,
        private readonly TaxonomySingleIndexer $taxonomyIndexer,
    ) {}

    /**
     * Adds an indexing operation to the queue.
     *
     * Deduplication is applied per (type, id) pair: if an item with the same
     * key already exists in the queue, the new action replaces the old one.
     *
     * @param string  $type   Item type: 'post', 'term', or 'posts_for_term'
     * @param string  $action Operation to perform: 'index', 'remove', or 'reindex'
     * @param int     $id     Post or term ID
     * @param mixed[] $extra  Additional data (e.g. ['taxonomy' => 'category'])
     */
    public function enqueue(string $type, string $action, int $id, array $extra = []): void
    {
        $queue = $this->loadQueue();

        $key = "{$type}:{$id}";
        $queue[$key] = [
            'type'   => $type,
            'action' => $action,
            'id'     => $id,
            'extra'  => $extra,
        ];

        update_option(self::QUEUE_OPTION, $queue, false);

        $this->scheduleIfNeeded();
    }

    /**
     * Processes all queued indexing operations.
     *
     * The queue is cleared before dispatching to avoid double-processing if
     * this method is called concurrently.
     */
    public function process(): void
    {
        $queue = $this->loadQueue();

        if (empty($queue)) {
            return;
        }

        // Clear the queue immediately before processing
        delete_option(self::QUEUE_OPTION);

        foreach ($queue as $item) {
            try {
                $this->dispatch($item);
            } catch (\Exception $e) {
                error_log(sprintf(
                    'MeiliScout: Async queue failed to process item [%s:%s id=%d]: %s',
                    $item['type'],
                    $item['action'],
                    $item['id'],
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Returns the delay in seconds before the cron event fires.
     *
     * @return int Delay in seconds (default: 300)
     */
    public function getDelay(): int
    {
        /**
         * Filters the delay (in seconds) before the async indexing queue is processed.
         *
         * @since 1.0.0
         * @param int $delay Delay in seconds. Default 300 (5 minutes).
         */
        return (int) apply_filters('meiliscout/async_indexing_delay', self::DEFAULT_DELAY);
    }

    /**
     * Dispatches a single queued item to the appropriate indexer.
     *
     * @param array{type: string, action: string, id: int, extra: mixed[]} $item
     */
    private function dispatch(array $item): void
    {
        ['type' => $type, 'action' => $action, 'id' => $id, 'extra' => $extra] = $item;

        match ($type) {
            'post' => match ($action) {
                'index'  => $this->postIndexer->indexPost($id),
                'remove' => $this->postIndexer->removePost($id),
                default  => null,
            },
            'term' => match ($action) {
                'index'  => $this->taxonomyIndexer->indexTerm($id),
                'remove' => $this->taxonomyIndexer->removeTerm($id),
                default  => null,
            },
            'posts_for_term' => match ($action) {
                'reindex' => $this->postIndexer->reindexPostsForTerm($id, $extra['taxonomy'] ?? ''),
                default   => null,
            },
            default => null,
        };
    }

    /**
     * Loads the current queue from the WordPress options table.
     *
     * @return array<string, array{type: string, action: string, id: int, extra: mixed[]}>
     */
    private function loadQueue(): array
    {
        $queue = get_option(self::QUEUE_OPTION, []);
        return is_array($queue) ? $queue : [];
    }

    /**
     * Schedules the cron event if one is not already pending.
     */
    private function scheduleIfNeeded(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + $this->getDelay(), self::CRON_HOOK);
        }
    }
}
