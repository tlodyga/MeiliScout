<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Exception;
use Meilisearch\Client;
use Pollora\MeiliScout\Contracts\Indexable;

use function current_time;
use function error_log;
use function update_option;

/**
 * Abstract base class for single-item indexing operations.
 * 
 * This abstract class provides common functionality for indexing individual items
 * in Meilisearch. It handles client initialization, index management, logging,
 * and provides template methods for specialized indexers to implement.
 * 
 * @package Pollora\MeiliScout\Services
 * @since 1.0.0
 */
abstract class AbstractSingleIndexer
{
    /**
     * Meilisearch client instance.
     *
     * @var Client
     */
    protected ?Client $client;

    /**
     * Indexable instance for formatting data.
     *
     * @var Indexable
     */
    protected Indexable $indexable;

    /**
     * Log of single indexing operations.
     *
     * @var array<string, mixed>
     */
    protected array $operationLog = [];

    /**
     * The name of the log option key for this indexer type.
     *
     * @var string
     */
    protected string $logOptionKey;

    /**
     * Creates a new AbstractSingleIndexer instance.
     *
     * Initializes the Meilisearch client and operation log.
     */
    public function __construct()
    {
        $this->client = ClientFactory::getClient();
        $this->indexable = $this->createIndexable();
        $this->logOptionKey = $this->getLogOptionKey();
        $this->initializeOperationLog();
    }

    /**
     * Creates the appropriate indexable instance for this indexer.
     *
     * This method must be implemented by concrete classes to return
     * the specific indexable instance they work with.
     *
     * @return Indexable The indexable instance
     */
    abstract protected function createIndexable(): Indexable;

    /**
     * Gets the WordPress option key for storing operation logs.
     *
     * This method must be implemented by concrete classes to return
     * a unique option key for their operation logs.
     *
     * @return string The option key for operation logs
     */
    abstract protected function getLogOptionKey(): string;

    /**
     * Checks if an item should be indexed based on plugin configuration.
     *
     * This method must be implemented by concrete classes to determine
     * whether a specific item should be indexed based on plugin settings.
     *
     * @param mixed $item The item to check
     * @return bool True if the item should be indexed, false otherwise
     */
    abstract protected function shouldIndex(mixed $item): bool;

    /**
     * Gets the unique identifier for an item.
     *
     * This method must be implemented by concrete classes to extract
     * the unique identifier from an item for indexing operations.
     *
     * @param mixed $item The item to get the ID from
     * @return int|string The unique identifier
     */
    abstract protected function getItemId(mixed $item): int|string;

    /**
     * Gets a human-readable name for an item for logging purposes.
     *
     * This method must be implemented by concrete classes to provide
     * a descriptive name for logging and debugging.
     *
     * @param mixed $item The item to get the name from
     * @return string The item name
     */
    abstract protected function getItemName(mixed $item): string;

    /**
     * Indexes or updates a single item in Meilisearch.
     *
     * This method handles the indexing of individual items using the template
     * method pattern. Concrete classes provide item-specific logic through
     * abstract methods.
     *
     * @param mixed $item The item to index
     * @return bool True if the item was successfully indexed, false otherwise
     * 
     * @throws Exception If there's an error during the indexing process
     */
    public function indexItem(mixed $item): bool
    {
        try {
            // Check if this item should be indexed
            if (!$this->shouldIndex($item)) {
                $itemName = $this->getItemName($item);
                $this->logOperation('info', "Item '{$itemName}' is not configured for indexing");
                return true; // Not an error, just not configured for indexing
            }

            // Ensure the index exists with proper configuration
            $this->ensureIndexExists();

            // Format the item for indexing
            $document = $this->indexable->formatForIndexing($item);

            // Index the document
            $index = $this->client->index($this->indexable->getIndexName());
            $index->addDocuments([$document]);

            $itemName = $this->getItemName($item);
            $itemId = $this->getItemId($item);
            $this->logOperation('success', "Item '{$itemName}' (ID: {$itemId}) indexed successfully");
            
            return true;

        } catch (Exception $e) {
            $this->logOperation('error', "Failed to index item: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Removes an item from the Meilisearch index.
     *
     * This method removes an item from the search index, typically called
     * when the item is deleted or no longer meets indexing criteria.
     *
     * @param int|string $itemId The ID of the item to remove
     * @return bool True if the item was successfully removed, false otherwise
     */
    public function removeItem(int|string $itemId): bool
    {
        try {
            $index = $this->client->index($this->indexable->getIndexName());
            $index->deleteDocument($itemId);

            $this->logOperation('success', "Item (ID: {$itemId}) removed from index");
            return true;

        } catch (Exception $e) {
            $this->logOperation('error', "Failed to remove item {$itemId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Ensures that the Meilisearch index exists with proper configuration.
     *
     * This method creates the index if it doesn't exist and updates its
     * settings to match the current indexable configuration.
     *
     * @return void
     * @throws Exception If there's an error creating or configuring the index
     */
    protected function ensureIndexExists(): void
    {
        try {
            $indexName = $this->indexable->getIndexName();
            $primaryKey = $this->indexable->getPrimaryKey();
            $settings = $this->indexable->getIndexSettings();

            // Check if index exists
            if (!$this->indexExists($indexName)) {
                // Create the index
                $this->client->createIndex($indexName, ['primaryKey' => $primaryKey]);
            }

            // Update settings (this is idempotent)
            $index = $this->client->index($indexName);
            $index->updateSettings($settings);

        } catch (Exception $e) {
            $indexName = $this->indexable->getIndexName();
            $this->logOperation('error', "Failed to ensure index '{$indexName}' exists: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Checks if an index exists in Meilisearch.
     *
     * @param string $indexName The name of the index
     * @return bool True if the index exists, false otherwise
     */
    protected function indexExists(string $indexName): bool
    {
        try {
            $indexes = $this->client->getIndexes();
            foreach ($indexes['results'] as $index) {
                if ($index['uid'] === $indexName) {
                    return true;
                }
            }
            return false;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Initializes the operation log for tracking indexing operations.
     *
     * @return void
     */
    protected function initializeOperationLog(): void
    {
        $this->operationLog = [
            'start_time' => current_time('mysql'),
            'operations' => [],
        ];
    }

    /**
     * Logs a single indexing operation.
     *
     * @param string $type The type of operation (info, success, error)
     * @param string $message The log message
     * @return void
     */
    protected function logOperation(string $type, string $message): void
    {
        $logEntry = [
            'type' => $type,
            'message' => $message,
            'time' => current_time('mysql'),
        ];

        $this->operationLog['operations'][] = $logEntry;

        // Also log errors to WordPress error log for debugging
        if ($type === 'error') {
            $indexerType = static::class;
            error_log("MeiliScout {$indexerType} Error: {$message}");
        }

        // Save to WordPress options for debugging purposes
        $this->saveOperationLog();
    }

    /**
     * Saves the operation log to WordPress options.
     *
     * @return void
     */
    protected function saveOperationLog(): void
    {
        // Keep only the last 50 operations to prevent the log from growing too large
        if (count($this->operationLog['operations']) > 50) {
            $this->operationLog['operations'] = array_slice($this->operationLog['operations'], -50);
        }

        update_option($this->logOptionKey, $this->operationLog);
    }

    /**
     * Gets the current operation log.
     *
     * This is useful for debugging and monitoring single indexing operations.
     *
     * @return array<string, mixed> The operation log
     */
    public function getOperationLog(): array
    {
        return $this->operationLog;
    }

    /**
     * Clears the operation log.
     *
     * @return void
     */
    public function clearOperationLog(): void
    {
        $this->initializeOperationLog();
        $this->saveOperationLog();
    }
}
