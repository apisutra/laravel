<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Tests\Stubs\Requests;

use ApiSutra\Attributes\Http\Post;
use ApiSutra\Attributes\Request\File;
use ApiSutra\Core\AbstractRequest;
use ApiSutra\VO\Files\FileInput;

#[Post('/files')]
final class UploadRequest extends AbstractRequest
{
    #[File('document')]
    public ?FileInput $file = null;

    /** @var list<FileInput> */
    #[File('attachments')]
    public array $files = [];
}
