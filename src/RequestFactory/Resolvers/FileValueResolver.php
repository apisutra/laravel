<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\RequestFactory\Resolvers;

use ApiSutra\Laravel\RequestFactory\PayloadKeys;
use ApiSutra\Laravel\RequestFactory\ResolveContext;
use ApiSutra\Laravel\RequestFactory\ResolverInterface;
use ApiSutra\Enums\DataTransfer\ValueState;
use ApiSutra\Support\PathResult;
use ApiSutra\VO\Files\FileInput;

/**
 * Резолвер значений файлового ввода.
 */
final class FileValueResolver implements ResolverInterface
{
    /**
     * Проверяет, что для свойства задан файловый атрибут.
     */
    public function supports(ResolveContext $context): bool
    {
        return $context->fileAttribute !== null;
    }

    /**
     * Возвращает файл или массив файлов для свойства.
     */
    public function resolve(ResolveContext $context): PathResult
    {
        $fileName = $context->fileAttribute->name ?? $context->propertyName;
        $source = $context->read(PayloadKeys::FILES, $fileName);
        if ($source->isMissing() || $source->isNull()) {
            return $source;
        }

        $value = $this->resolveFileValue($source->value);
        return new PathResult($value === null ? ValueState::Null : ValueState::Present, $value);
    }

    /**
     * Нормализует входной файл в FileInput или массив FileInput.
     */
    private function resolveFileValue(mixed $file): FileInput|array|null
    {
        if ($file instanceof FileInput) {
            return $file;
        }

        if (is_array($file)) {
            $converted = array_map(
                fn(mixed $item): ?FileInput => $this->convertUploadedFile($item),
                $file,
            );

            return array_values(array_filter(
                $converted,
                static fn(?FileInput $item): bool => $item instanceof FileInput,
            ));
        }

        return $this->convertUploadedFile($file);
    }

    /**
     * Преобразует загруженный файл в FileInput.
     */
    private function convertUploadedFile(mixed $file): ?FileInput
    {
        if ($file instanceof FileInput) {
            return $file;
        }

        if (!is_object($file)) {
            return null;
        }

        if (method_exists($file, 'getRealPath') && method_exists($file, 'getClientOriginalName')) {
            $path = $file->getRealPath();
            if ($path === false || $path === null) {
                return null;
            }

            $input = FileInput::fromPath($path);
            $filename = $file->getClientOriginalName();
            if (is_string($filename)) {
                $input = $input->withFilename($filename);
            }
            $mime = method_exists($file, 'getMimeType') ? $file->getMimeType() : null;
            if (is_string($mime)) {
                return $input->withMimeType($mime);
            }

            return $input;
        }

        return null;
    }
}
