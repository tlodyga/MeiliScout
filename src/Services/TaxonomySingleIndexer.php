<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Exception;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\Indexable;
use Pollora\MeiliScout\Indexables\TaxonomyIndexable;
use WP_Error;
use WP_Term;

use function get_term;
use function get_terms;
use function in_array;
use function is_wp_error;

/**
 * Service for managing single taxonomy term indexing operations in Meilisearch.
 * 
 * This service handles real-time indexing of individual WordPress taxonomy terms
 * when they are created, updated, or deleted. It extends the abstract
 * single indexer to provide taxonomy-specific functionality.
 * 
 * @package Pollora\MeiliScout\Services
 * @since 1.0.0
 */
class TaxonomySingleIndexer extends AbstractSingleIndexer
{
    /**
     * Creates the TaxonomyIndexable instance for formatting taxonomy data.
     *
     * @return Indexable The taxonomy indexable instance
     */
    protected function createIndexable(): Indexable
    {
        return new TaxonomyIndexable();
    }

    /**
     * Gets the WordPress option key for storing taxonomy indexing operation logs.
     *
     * @return string The option key for taxonomy operation logs
     */
    protected function getLogOptionKey(): string
    {
        return 'meiliscout/taxonomy_single_indexer_log';
    }

    /**
     * Checks if a taxonomy term should be indexed based on plugin configuration.
     *
     * This method verifies that the taxonomy is configured for indexing.
     *
     * @param int|WP_Term|array<string, mixed> $item The term to check (WP_Term object, term ID, or array)
     * @return bool True if the term should be indexed, false otherwise
     */
    protected function shouldIndex(mixed $item): bool
    {
        $term = $this->normalizeTerm($item);
        
        if (!$term instanceof WP_Term) {
            return false;
        }

        // Check if taxonomy should be indexed
        return $this->shouldIndexTaxonomy($term->taxonomy);
    }

    /**
     * Gets the unique identifier for a taxonomy term.
     *
     * @param mixed $item The term (WP_Term object or term ID)
     * @return int|string The term ID
     */
    protected function getItemId(mixed $item): int|string
    {
        $term = $this->normalizeTerm($item);
        return $term instanceof WP_Term ? $term->term_id : (int) $item;
    }

    /**
     * Gets a human-readable name for a taxonomy term for logging purposes.
     *
     * @param mixed $item The term (WP_Term object or term ID)
     * @return string The term name or a default description
     */
    protected function getItemName(mixed $item): string
    {
        $term = $this->normalizeTerm($item);
        return $term instanceof WP_Term ? $term->name : "Term ID: {$item}";
    }

    /**
     * Indexes or updates a single taxonomy term in Meilisearch.
     *
     * This method provides a taxonomy-specific interface for indexing terms.
     *
     * @param int|WP_Term $term The term ID or WP_Term object to index
     * @return bool True if the term was successfully indexed, false otherwise
     * 
     * @throws Exception If there's an error during the indexing process
     */
    public function indexTerm(int|WP_Term $term): bool
    {
        $termObject = $this->normalizeTerm($term);
        
        if (!$termObject instanceof WP_Term) {
            $this->logOperation('error', "Term with ID {$term} not found");
            return false;
        }

        return $this->indexItem($termObject);
    }

    /**
     * Removes a taxonomy term from the Meilisearch index.
     *
     * This method provides a taxonomy-specific interface for removing terms
     * from the search index.
     *
     * @param int $termId The ID of the term to remove
     * @return bool True if the term was successfully removed, false otherwise
     */
    public function removeTerm(int $termId): bool
    {
        return $this->removeItem($termId);
    }

    /**
     * Normalizes input to a WP_Term object.
     *
     * @param int|WP_Term|array<string, mixed> $term The term ID, WP_Term object, or term array
     * @return WP_Term|null The WP_Term object or null if not found/error
     */
    private function normalizeTerm(int|WP_Term|array $term): ?WP_Term
    {
        if ($term instanceof WP_Term) {
            return $term;
        }

        // Handle array format (sometimes returned by get_terms)
        if (is_array($term)) {
            if (isset($term['term_id'])) {
                $termObject = get_term((int) $term['term_id']);
                if ($termObject instanceof WP_Term) {
                    return $termObject;
                }
            }
            return null;
        }

        $termObject = get_term($term);

        if (is_wp_error($termObject) || ! $termObject instanceof WP_Term) {
            return null;
        }

        return $termObject;
    }

    /**
     * Checks if a taxonomy should be indexed based on plugin settings.
     *
     * @param string $taxonomy The taxonomy to check
     * @return bool True if the taxonomy should be indexed, false otherwise
     */
    private function shouldIndexTaxonomy(string $taxonomy): bool
    {
        $indexedTaxonomies = Settings::get('indexed_taxonomies', []);
        return in_array($taxonomy, $indexedTaxonomies, true);
    }

    /**
     * Gets the list of taxonomies that are configured for indexing.
     *
     * @return string[] Array of indexed taxonomies
     */
    private function getIndexedTaxonomies(): array
    {
        return Settings::get('indexed_taxonomies', []);
    }

    /**
     * Indexes multiple taxonomy terms in batch with optimized performance.
     *
     * This method uses bulk preloading and sends all documents to Meilisearch
     * in a single API call for maximum efficiency.
     *
     * @param WP_Term[]|int[] $terms Array of term objects or term IDs
     * @return array{indexed: int, skipped: int, errors: int} Statistics about the batch operation
     */
    public function indexTerms(array $terms, ?Indexable $indexable): array
    {
        $statistics = [
            'indexed' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        if ($indexable) {
            $this->indexable = $indexable;
        }

        if (empty($terms)) {
            return $statistics;
        }

        // Normalize all terms first
        $termsToIndex = [];

        foreach ($terms as $term) {
            $termObject = $this->normalizeTerm($term);
            if (! $termObject instanceof WP_Term) {
                $statistics['errors']++;
                continue;
            }

            // Check if should be indexed
            if ($this->shouldIndex($termObject)) {
                $termsToIndex[] = $termObject;
            } else {
                $statistics['skipped']++;
            }
        }

        if (empty($termsToIndex)) {
            return $statistics;
        }

        try {
            // Ensure index exists once for the entire batch
            $this->ensureIndexExistsOnce();

            // Get the indexable
            /** @var \Pollora\MeiliScout\Indexables\TaxonomyIndexable $indexable */
            $indexable = $this->indexable;

            // Format all documents
            $documents = [];
            foreach ($termsToIndex as $term) {
                try {
                    $documents[] = $indexable->formatForIndexing($term);
                } catch (Exception $e) {
                    $statistics['errors']++;
                    $this->logOperation('error', "Failed to format term {$term->term_id}: " . $e->getMessage());
                }
            }

            // Send all documents in a single API call
            if (! empty($documents)) {
                $index = $this->client->index($indexable->getIndexName());
                $index->addDocuments($documents);
                $statistics['indexed'] = count($documents);
            }

            // Aggressive memory cleanup after batch
            unset($documents, $termsToIndex);
            gc_collect_cycles();

        } catch (Exception $e) {
            $statistics['errors'] += count($termsToIndex) - $statistics['indexed'];
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

    /**
     * Indexes all terms of a specific taxonomy.
     *
     * This method is useful for re-indexing all terms when taxonomy
     * configuration changes or for initial indexing.
     *
     * @param string $taxonomy The taxonomy to index
     * @return array{indexed: int, skipped: int, errors: int} Statistics about the operation
     */
    public function indexTaxonomy(string $taxonomy): array
    {
        if (!$this->shouldIndexTaxonomy($taxonomy)) {
            $this->logOperation('info', "Taxonomy '{$taxonomy}' is not configured for indexing");
            return ['indexed' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => 0, // Get all terms
        ]);

        if (is_wp_error($terms)) {
            $this->logOperation('error', "Failed to get terms for taxonomy '{$taxonomy}': " . $terms->get_error_message());
            return ['indexed' => 0, 'skipped' => 0, 'errors' => 1];
        }

        return $this->indexTerms($terms);
    }

    /**
     * Re-indexes all terms of the configured taxonomies.
     *
     * This method iterates through all configured taxonomies and re-indexes
     * their terms, useful for maintenance operations.
     *
     * @return array{indexed: int, skipped: int, errors: int} Overall statistics
     */
    public function reindexAllTerms(): array
    {
        $overallStatistics = [
            'indexed' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $taxonomies = $this->getIndexedTaxonomies();

        foreach ($taxonomies as $taxonomy) {
            $stats = $this->indexTaxonomy($taxonomy);
            $overallStatistics['indexed'] += $stats['indexed'];
            $overallStatistics['skipped'] += $stats['skipped'];
            $overallStatistics['errors'] += $stats['errors'];
        }

        $total = $overallStatistics['indexed'] + $overallStatistics['skipped'] + $overallStatistics['errors'];
        $this->logOperation('success', "Re-indexed all terms: {$total} terms processed ({$overallStatistics['indexed']} indexed, {$overallStatistics['skipped']} skipped, {$overallStatistics['errors']} errors)");

        return $overallStatistics;
    }
}