<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\Sdk\RouteDefinitionScanner;

class RouteDefinitionScannerTest extends TestCase
{
    private RouteDefinitionScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = new RouteDefinitionScanner;
    }

    public function test_parses_all_five_standard_routes(): void
    {
        $content = <<<'PHP'
        <?php

        class PostController
        {
            public static function apiRoutes(): void
            {
                Route::get('posts', [PostController::class, 'getCollection']);
                Route::get('posts/{post}', [PostController::class, 'get']);
                Route::post('posts', [PostController::class, 'create']);
                Route::put('posts/{post}', [PostController::class, 'update']);
                Route::delete('posts/{post}', [PostController::class, 'delete']);
            }
        }
        PHP;

        $endpoints = $this->scanner->scan($content);

        $this->assertCount(5, $endpoints);

        $methods = array_column($endpoints, 'method');
        $this->assertSame(['GET', 'GET', 'POST', 'PUT', 'DELETE'], $methods);

        $actions = array_column($endpoints, 'action');
        $this->assertSame(['getCollection', 'get', 'create', 'update', 'delete'], $actions);

        // All standard actions should be typed as 'standard'
        $types = array_column($endpoints, 'type');
        $this->assertSame(['standard', 'standard', 'standard', 'standard', 'standard'], $types);
    }

    public function test_parses_custom_action_routes_with_correct_method(): void
    {
        $content = <<<'PHP'
        <?php

        class PostController
        {
            public static function apiRoutes(): void
            {
                Route::get('posts', [PostController::class, 'getCollection']);
                Route::put('posts/{post}/archive', [PostController::class, 'archive']);
                Route::delete('posts/{post}/soft-delete', [PostController::class, 'softDelete']);
                Route::post('posts/{post}/duplicate', [PostController::class, 'duplicate']);
                Route::get('posts/{post}/status', [PostController::class, 'status']);
            }
        }
        PHP;

        $endpoints = $this->scanner->scan($content);

        $this->assertCount(5, $endpoints);

        // First is standard, rest are custom
        $this->assertEquals('standard', $endpoints[0]['type']);
        $this->assertEquals('custom', $endpoints[1]['type']);
        $this->assertEquals('custom', $endpoints[2]['type']);
        $this->assertEquals('custom', $endpoints[3]['type']);
        $this->assertEquals('custom', $endpoints[4]['type']);

        // Custom actions should have their actual HTTP methods
        $this->assertEquals('PUT', $endpoints[1]['method']);
        $this->assertEquals('archive', $endpoints[1]['action']);

        $this->assertEquals('DELETE', $endpoints[2]['method']);
        $this->assertEquals('softDelete', $endpoints[2]['action']);

        $this->assertEquals('POST', $endpoints[3]['method']);
        $this->assertEquals('duplicate', $endpoints[3]['action']);

        $this->assertEquals('GET', $endpoints[4]['method']);
        $this->assertEquals('status', $endpoints[4]['action']);
    }

    public function test_returns_empty_array_for_empty_api_routes(): void
    {
        $content = <<<'PHP'
        <?php

        class PostController
        {
            public static function apiRoutes(): void
            {
            }
        }
        PHP;

        $endpoints = $this->scanner->scan($content);

        $this->assertEmpty($endpoints);
    }

    public function test_returns_empty_array_for_controller_without_api_routes(): void
    {
        $content = <<<'PHP'
        <?php

        class PostController
        {
            public function getCollection() {}
            public function get() {}
        }
        PHP;

        $endpoints = $this->scanner->scan($content);

        $this->assertEmpty($endpoints);
    }

    public function test_returns_empty_array_for_empty_content(): void
    {
        $endpoints = $this->scanner->scan('');

        $this->assertEmpty($endpoints);
    }

    public function test_paths_include_leading_slash(): void
    {
        $content = <<<'PHP'
        <?php

        class PostController
        {
            public static function apiRoutes(): void
            {
                Route::get('posts', [PostController::class, 'getCollection']);
                Route::get('posts/{post}', [PostController::class, 'get']);
            }
        }
        PHP;

        $endpoints = $this->scanner->scan($content);

        $this->assertEquals('/posts', $endpoints[0]['path']);
        $this->assertEquals('/posts/{post}', $endpoints[1]['path']);
    }

    public function test_scan_file_returns_empty_for_non_existent_file(): void
    {
        $endpoints = $this->scanner->scanFile('/nonexistent/path/Controller.php');

        $this->assertEmpty($endpoints);
    }

    public function test_extracts_phpdoc_descriptions_from_methods(): void
    {
        $content = <<<'PHP'
        <?php

        class PostController
        {
            public static function apiRoutes(): void
            {
                Route::get('posts', [PostController::class, 'getCollection']);
                Route::put('posts/{post_id}/archive', [PostController::class, 'archive']);
            }

            /**
             * Get a paginated list of posts.
             */
            public function getCollection() {}

            /**
             * Archive the post and remove from public listings.
             */
            public function archive(int $post_id) {}
        }
        PHP;

        $endpoints = $this->scanner->scan($content);

        $this->assertCount(2, $endpoints);
        $this->assertEquals('Get a paginated list of posts.', $endpoints[0]['description']);
        $this->assertEquals('Archive the post and remove from public listings.', $endpoints[1]['description']);
    }

    public function test_description_is_null_when_no_phpdoc(): void
    {
        $content = <<<'PHP'
        <?php

        class PostController
        {
            public static function apiRoutes(): void
            {
                Route::get('posts', [PostController::class, 'getCollection']);
            }

            public function getCollection() {}
        }
        PHP;

        $endpoints = $this->scanner->scan($content);

        $this->assertCount(1, $endpoints);
        $this->assertNull($endpoints[0]['description']);
    }

    public function test_extracts_only_first_line_of_multiline_phpdoc(): void
    {
        $content = <<<'PHP'
        <?php

        class PostController
        {
            public static function apiRoutes(): void
            {
                Route::put('posts/{post_id}/publish', [PostController::class, 'publish']);
            }

            /**
             * Publish the post to the public site.
             *
             * This will also send notifications to subscribers.
             *
             * @param int $post_id
             */
            public function publish(int $post_id) {}
        }
        PHP;

        $endpoints = $this->scanner->scan($content);

        $this->assertCount(1, $endpoints);
        $this->assertEquals('Publish the post to the public site.', $endpoints[0]['description']);
    }

    public function test_handles_mixed_standard_and_custom_routes(): void
    {
        $content = <<<'PHP'
        <?php

        class OrderController
        {
            public static function apiRoutes(): void
            {
                Route::get('orders', [OrderController::class, 'getCollection']);
                Route::post('orders', [OrderController::class, 'create']);
                Route::put('orders/{order}/ship', [OrderController::class, 'ship']);
                Route::put('orders/{order}/cancel', [OrderController::class, 'cancel']);
            }
        }
        PHP;

        $endpoints = $this->scanner->scan($content);

        $this->assertCount(4, $endpoints);

        $standardEndpoints = array_filter($endpoints, fn ($ep) => $ep['type'] === 'standard');
        $customEndpoints = array_filter($endpoints, fn ($ep) => $ep['type'] === 'custom');

        $this->assertCount(2, $standardEndpoints);
        $this->assertCount(2, $customEndpoints);
    }
}
