<?php

namespace SchemaCraft\Tests\Fixtures\Actions;

use SchemaCraft\ActionRegistry;
use SchemaCraft\Tests\Fixtures\Actions\Post\CreatePostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\DeletePostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\UpdatePostAction;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;

class PostActions extends ActionRegistry
{
    protected static string $schema = PostSchema::class;

    public static function create(): CreatePostAction
    {
        return new CreatePostAction;
    }

    public static function update(): UpdatePostAction
    {
        return new UpdatePostAction;
    }

    public static function delete(): DeletePostAction
    {
        return new DeletePostAction;
    }
}
