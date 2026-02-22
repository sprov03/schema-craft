<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\Sdk\ControllerActionScanner;

class ControllerActionScannerTest extends TestCase
{
    private ControllerActionScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = new ControllerActionScanner;
    }

    public function test_returns_empty_array_for_standard_crud_controller(): void
    {
        $content = <<<'PHP'
        <?php

        class PostController extends Controller
        {
            public static function apiRoutes(): void {}
            public function getCollection() {}
            public function get(Post $post) {}
            public function create(CreatePostRequest $request) {}
            public function update(UpdatePostRequest $request, Post $post) {}
            public function delete(Post $post) {}
        }
        PHP;

        $actions = $this->scanner->scan($content);

        $this->assertEmpty($actions);
    }

    public function test_detects_single_custom_action(): void
    {
        $content = <<<'PHP'
        <?php

        class PostController extends Controller
        {
            public static function apiRoutes(): void {}
            public function getCollection() {}
            public function get(Post $post) {}
            public function create(CreatePostRequest $request) {}
            public function update(UpdatePostRequest $request, Post $post) {}
            public function delete(Post $post) {}

            public function cancel(CancelPostRequest $request, Post $post): \Illuminate\Http\JsonResponse
            {
                $post->Service()->cancel(...$request->validated());
                return response()->json(null, 200);
            }
        }
        PHP;

        $actions = $this->scanner->scan($content);

        $this->assertSame(['cancel'], $actions);
    }

    public function test_detects_multiple_custom_actions(): void
    {
        $content = <<<'PHP'
        <?php

        class OrderController extends Controller
        {
            public static function apiRoutes(): void {}
            public function getCollection() {}
            public function get(Order $order) {}
            public function create(CreateOrderRequest $request) {}
            public function update(UpdateOrderRequest $request, Order $order) {}
            public function delete(Order $order) {}

            public function cancel(CancelOrderRequest $request, Order $order) {}
            public function ship(ShipOrderRequest $request, Order $order) {}
            public function refund(RefundOrderRequest $request, Order $order) {}
        }
        PHP;

        $actions = $this->scanner->scan($content);

        $this->assertSame(['cancel', 'ship', 'refund'], $actions);
    }

    public function test_ignores_constructor(): void
    {
        $content = <<<'PHP'
        <?php

        class PostController extends Controller
        {
            public function __construct() {}
            public static function apiRoutes(): void {}
            public function getCollection() {}
            public function get(Post $post) {}
            public function create(CreatePostRequest $request) {}
            public function update(UpdatePostRequest $request, Post $post) {}
            public function delete(Post $post) {}
        }
        PHP;

        $actions = $this->scanner->scan($content);

        $this->assertEmpty($actions);
    }

    public function test_ignores_non_public_methods(): void
    {
        $content = <<<'PHP'
        <?php

        class PostController extends Controller
        {
            public static function apiRoutes(): void {}
            public function getCollection() {}
            public function get(Post $post) {}
            public function create(CreatePostRequest $request) {}
            public function update(UpdatePostRequest $request, Post $post) {}
            public function delete(Post $post) {}

            private function helperMethod() {}
            protected function anotherHelper() {}

            public function publish(PublishPostRequest $request, Post $post) {}
        }
        PHP;

        $actions = $this->scanner->scan($content);

        $this->assertSame(['publish'], $actions);
    }

    public function test_returns_empty_array_for_empty_content(): void
    {
        $actions = $this->scanner->scan('');

        $this->assertEmpty($actions);
    }

    public function test_scan_file_returns_empty_for_non_existent_file(): void
    {
        $actions = $this->scanner->scanFile('/nonexistent/path/Controller.php');

        $this->assertEmpty($actions);
    }
}
