<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Tests\Fixtures\Actions\Post\CreatePostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\DeletePostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\UpdatePostAction;
use SchemaCraft\Tests\Fixtures\Actions\PostActions;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;

class ActionRegistryTest extends TestCase
{
    public function test_registry_returns_schema_class(): void
    {
        $this->assertSame(PostSchema::class, PostActions::schema());
    }

    public function test_registry_discovers_action_classes(): void
    {
        $classes = PostActions::actionClasses();

        $this->assertArrayHasKey('create', $classes);
        $this->assertArrayHasKey('update', $classes);
        $this->assertArrayHasKey('delete', $classes);
        $this->assertSame(CreatePostAction::class, $classes['create']);
        $this->assertSame(UpdatePostAction::class, $classes['update']);
        $this->assertSame(DeletePostAction::class, $classes['delete']);
    }

    public function test_registry_static_methods_return_action_instances(): void
    {
        $this->assertInstanceOf(CreatePostAction::class, PostActions::create());
        $this->assertInstanceOf(UpdatePostAction::class, PostActions::update());
        $this->assertInstanceOf(DeletePostAction::class, PostActions::delete());
    }

    public function test_registry_static_methods_return_new_instances(): void
    {
        $first = PostActions::create();
        $second = PostActions::create();

        $this->assertNotSame($first, $second);
    }

    public function test_registry_counts_actions(): void
    {
        $classes = PostActions::actionClasses();

        $this->assertCount(3, $classes);
    }
}
