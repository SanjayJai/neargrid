<?php

declare(strict_types=1);

namespace App\Domains\Search\Contracts;

use App\Domains\Search\DTOs\FilterOptionsResult;
use App\Domains\Search\DTOs\FilterQuery;
use App\Domains\Search\DTOs\SearchQuery;
use App\Domains\Search\DTOs\SearchResult;
use App\Domains\Search\DTOs\SuggestionQuery;
use App\Domains\Search\DTOs\SuggestionResult;

/**
 * Search engine seam enabling seamless Stage 2 migration from MySQL 8 Native Search
 * to Meilisearch without modifying any public REST API contracts.
 */
interface SearchEngineInterface
{
    public function search(SearchQuery $query): SearchResult;

    public function nearby(SearchQuery $query): SearchResult;

    public function suggestions(SuggestionQuery $query): SuggestionResult;

    public function filters(FilterQuery $query): FilterOptionsResult;

    public function driverName(): string;
}
