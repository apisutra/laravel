<?php

declare(strict_types=1);

namespace Integration;

use ApiSutra\Attributes\Http\Get;
use ApiSutra\Attributes\Response\Download;
use ApiSutra\Core\AbstractRequest;

#[Get('/file')]
#[Download]
final class DownloadRequest extends AbstractRequest
{
}
