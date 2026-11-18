<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domains\Search\Contracts\SearchEngineInterface;
use App\Domains\Search\DTOs\SuggestionQuery;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RedisAutocompleteIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_redis_autocomplete_caching(): void
    {
        $host = (string) config('database.redis.default.host', '127.0.0.1');
        $port = (int) config('database.redis.default.port', 6379);

        if (@fsockopen($host, $port, $errno, $errstr, 1) === false) {
            $this->markTestSkipped(
                "Live Redis 7 server is not reachable at {$host}:{$port} in this local environment."
            );
        }

        $this->seed(DatabaseSeeder::class);
        config(['search.autocomplete.cache_store' => 'redis']);

        /** @var SearchEngineInterface $engine */
        $engine = app(SearchEngineInterface::class);

        $first = $engine->suggestions(new SuggestionQuery(rawQuery: 'dent', citySlug: 'chennai'));
        $second = $engine->suggestions(new SuggestionQuery(rawQuery: 'dent', citySlug: 'chennai'));

        $this->assertSame('redis', $first->cacheSource);
        $this->assertFalse($first->cacheHit);
        $this->assertTrue($second->cacheHit);
    }
}
