<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Tests\Stubs\Requests;

use ApiSutra\Attributes\Http\Post;
use ApiSutra\Attributes\Request\Body;
use ApiSutra\Attributes\Request\File;
use ApiSutra\Attributes\Request\Header;
use ApiSutra\Attributes\Request\Path;
use ApiSutra\Attributes\Request\Query;
use ApiSutra\Core\AbstractRequest;

#[Post('/items/{id}')]
final class DefaultValuesRequest extends AbstractRequest
{
    #[Path('item')]
    public ?string $id = 'route-default';

    #[Query('search')]
    public ?string $term = 'search-default';

    #[Body(nested: 'payload.note')]
    public ?string $note = 'note-default';

    #[Body(nested: '')]
    public ?string $direct = 'direct-default';

    #[Header('X-Label')]
    public ?string $label = 'header-default';

    #[File('docs')]
    public ?array $documents = [];

    public ?string $name = 'plain-default';
    public bool $enabled = true;

    public function __construct(
        #[Query] public readonly int $limit = 20,
        #[Body] public ?string $sort = 'created_at',
    ) {
    }
}
