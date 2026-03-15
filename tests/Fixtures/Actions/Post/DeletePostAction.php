<?php

namespace SchemaCraft\Tests\Fixtures\Actions\Post;

use SchemaCraft\Action;
use SchemaCraft\Attributes\Actions\ActionMeta;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;

#[ActionMeta(method: 'delete', label: 'Delete Post')]
class DeletePostAction extends Action
{
    protected static string $schema = PostSchema::class;

    public function run(mixed $record, array $mapped): mixed
    {
        return null;
    }
}
