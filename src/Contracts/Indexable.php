<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Contracts;

interface Indexable
{
    /**
     * Returns the Meilisearch index name.
     */
    public function getIndexName(): string;

    /**
     * Returns the primary key name for data indexed by Meilisearch.
     */
    public function getPrimaryKey(): string;

    /**
     * Returns the index configuration.
     */
    public function getIndexSettings(): array;

    /**
     * Retrieves the items to index.
     *
     * @param int|null $offset Optional starting offset
     * @param int|null $limit Optional maximum number of items
     */
    public function getItems(?int $offset = null, ?int $limit = null): iterable;

    /**
     * Formats an item for indexing.
     */
    public function formatForIndexing(mixed $item): array;

    /**
     * Formats an item for search.
     */
    public function formatForSearch(array $hit): mixed;
}
