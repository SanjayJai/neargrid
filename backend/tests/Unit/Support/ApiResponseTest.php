<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Api\ApiResponse;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

final class ApiResponseTest extends TestCase
{
    public function test_created_and_no_content_helpers_return_expected_statuses(): void
    {
        $created = ApiResponse::created(['id' => '01JCG4K8N5P2Q7R9S1T3V5W7X9']);
        $this->assertSame(201, $created->getStatusCode());
        $this->assertSame('success', $created->getData(true)['status']);

        $noContent = ApiResponse::noContent();
        $this->assertSame(204, $noContent->getStatusCode());
    }

    public function test_paginated_supports_offset_and_cursor_paginators(): void
    {
        $offset = new LengthAwarePaginator(
            items: [['name' => 'Clinic A']],
            total: 42,
            perPage: 20,
            currentPage: 1,
        );

        $offsetPayload = ApiResponse::paginated($offset)->getData(true);
        $this->assertSame('offset', $offsetPayload['meta']['pagination']['type']);
        $this->assertSame(42, $offsetPayload['meta']['pagination']['total']);
        $this->assertSame(3, $offsetPayload['meta']['pagination']['last_page']);

        $cursor = new CursorPaginator(
            items: [['id' => 1], ['id' => 2]],
            perPage: 2,
        );

        $cursorPayload = ApiResponse::paginated($cursor)->getData(true);
        $this->assertSame('cursor', $cursorPayload['meta']['pagination']['type']);
        $this->assertSame(2, $cursorPayload['meta']['pagination']['per_page']);
    }
}
