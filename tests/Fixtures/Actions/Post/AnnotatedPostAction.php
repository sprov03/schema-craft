<?php

namespace SchemaCraft\Tests\Fixtures\Actions\Post;

use SchemaCraft\Action;
use SchemaCraft\Attributes\Actions\ActionMeta;
use SchemaCraft\Attributes\Length;
use SchemaCraft\Attributes\MediumText;
use SchemaCraft\Attributes\Rules;
use SchemaCraft\Attributes\SmallInt;
use SchemaCraft\Attributes\Unique;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;

#[ActionMeta(route: 'annotated', method: 'post', label: 'Annotated')]
class AnnotatedPostAction extends Action
{
    protected static string $schema = PostSchema::class;

    #[Length(50)]
    public string $slug;

    #[MediumText]
    public string $summary;

    #[Unique]
    public string $externalId;

    #[SmallInt]
    public int $priority;

    #[Rules('min:3')]
    public string $title;

    public function run(mixed $record, array $mapped): mixed
    {
        return $record;
    }
}
