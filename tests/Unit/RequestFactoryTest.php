<?php

declare(strict_types=1);

use ApiSutra\Casts\CastRegistry;
use ApiSutra\Config\ClientConfig;
use ApiSutra\Laravel\RequestFactory;
use ApiSutra\Laravel\RequestFactory\PayloadKeys;
use ApiSutra\Laravel\Tests\Stubs\Requests\SerializationRequest;
use ApiSutra\Laravel\Tests\Stubs\Requests\DefaultValuesRequest;
use ApiSutra\Laravel\Tests\Stubs\Requests\ReadonlyDiRequest;
use ApiSutra\Laravel\Tests\Stubs\Requests\UploadRequest;
use ApiSutra\VO\Files\FileInput;
use ApiSutra\VO\Pipeline\PipelineContext;
use ApiSutra\Serialization\Serializer;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;

describe('RequestFactory', function () {
    it('создаёт запрос из структурированного массива', function () {
        $factory = new RequestFactory();
        $source = [
            PayloadKeys::ROUTE => ['id' => '123'],
            PayloadKeys::QUERY => ['filters' => ['a', 'b']],
            PayloadKeys::BODY => [
                'payload' => ['data' => 'payload'],
                'plainValue' => 'plain',
            ],
            PayloadKeys::HEADERS => ['X-Custom' => ['header-value']],
            PayloadKeys::FILES => [],
        ];

        $request = $factory->make(SerializationRequest::class, $source);

        expect($request)->toBeInstanceOf(SerializationRequest::class);
        expect($request->id)->toBe('123');
        expect($request->filters)->toBe(['a', 'b']);
        expect($request->payload)->toBe('payload');
        expect($request->header)->toBe('header-value');
        expect($request->plainValue)->toBe('plain');
    });
});

it('сохраняет defaults свойств и конструктора при отсутствии входных полей', function (): void {
    $factory = new RequestFactory();
    foreach ([[], Request::create('/items', 'POST')] as $source) {
        expect($factory->make(DefaultValuesRequest::class, $source))->toEqual(new DefaultValuesRequest());
    }
});

it('не подставляет другой источник вместо отсутствующего значения выбранного атрибутом', function (): void {
    $request = (new RequestFactory())->make(DefaultValuesRequest::class, [
        'body' => ['id' => 'wrong', 'search' => 'wrong', 'label' => 'wrong', 'documents' => null,
            'note' => 'wrong', 'payload' => null, 'limit' => 99],
        'query' => ['sort' => 'wrong', 'direct' => 'wrong'],
    ]);
    expect($request)->toEqual(new DefaultValuesRequest());
});

it('сохраняет явный null во всех источниках, включая вложенное тело и заголовок', function (): void {
    $request = (new RequestFactory())->make(DefaultValuesRequest::class, [
        'route' => ['item' => null], 'query' => ['search' => null],
        'body' => ['payload' => ['note' => null], 'direct' => null, 'name' => null, 'sort' => null],
        'headers' => ['x-label' => null], 'files' => ['docs' => null],
    ]);
    foreach (['id', 'term', 'note', 'direct', 'label', 'documents', 'name', 'sort'] as $property) {
        expect($request->{$property})->toBeNull();
    }
    expect($request->limit)->toBe(20);
});

it('не путает пустую строку, false, ноль и пустой массив с отсутствием', function (): void {
    $request = (new RequestFactory())->make(DefaultValuesRequest::class, [
        'route' => ['item' => '0'], 'query' => ['search' => '', 'limit' => 0],
        'body' => ['payload' => ['note' => ''], 'direct' => '', 'name' => '', 'sort' => '', 'enabled' => false],
        'headers' => ['X-LABEL' => ['']], 'files' => ['docs' => []],
    ]);
    expect($request->id)->toBe('0')->and($request->limit)->toBe(0)
        ->and($request->enabled)->toBeFalse()->and($request->documents)->toBe([]);
    foreach (['term', 'note', 'direct', 'label', 'name', 'sort'] as $property) {
        expect($request->{$property})->toBe('');
    }
});

it('различает отсутствие и null в настоящем HTTP запросе', function (): void {
    $request = (new RequestFactory())->make(
        DefaultValuesRequest::class,
        Request::create('/items', 'POST', ['name' => null]),
    );
    expect($request->name)->toBeNull()->and($request->sort)->toBe('created_at')->and($request->limit)->toBe(20);
});

it('не скрывает null для non-nullable параметра и отсутствие обязательного аргумента', function (): void {
    $factory = new RequestFactory();
    expect(fn () => $factory->make(DefaultValuesRequest::class, ['query' => ['limit' => null]]))
        ->toThrow(TypeError::class);
    expect(fn () => $factory->make(ReadonlyDiRequest::class, []))->toThrow(ArgumentCountError::class);
});

it('сохраняет имена загруженных файлов, содержимое и владение потоками', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'apisutra-upload-');
    file_put_contents($path, 'sample');
    $existing = FileInput::fromContent('existing', 'existing.txt');
    $request = null;
    try {
        $upload = new UploadedFile($path, 'invoice.csv', 'text/csv', null, true);
        $incoming = Request::create('/files', 'POST', files: [
            'document' => $upload, 'attachments' => [$upload],
        ]);
        $request = (new RequestFactory())->make(UploadRequest::class, $incoming);
        foreach ([$request->file, ...$request->files] as $file) {
            expect($file->filename)->toBe('invoice.csv')->and($file->size)->toBe(6)
                ->and($file->mimeType)->toBe($upload->getMimeType())
                ->and((string) $file->stream)->toBe('sample');
            $file->close();
            expect($file->stream->isReadable())->toBeFalse();
        }
        $structured = (new RequestFactory())->make(UploadRequest::class, [
            'files' => ['document' => $existing, 'attachments' => [$existing]],
        ]);
        expect($structured->file)->toBe($existing)->and($structured->files)->toBe([$existing]);
    } finally {
        $request?->file?->close();
        foreach ($request?->files ?? [] as $file) {
            $file->close();
        }
        $existing->close();
        unlink($path);
    }
});

it('не позволяет имени загруженного файла подменить поле внешнего запроса', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'apisutra-upload-');
    file_put_contents($path, 'sample');
    $request = null;
    try {
        $filename = 'a"; name="evil.txt';
        $incoming = Request::create('/files', 'POST', files: [
            'document' => new UploadedFile($path, $filename, 'text/plain', null, true),
        ]);
        $request = (new RequestFactory())->make(UploadRequest::class, $incoming);
        $prepared = (new Serializer(new CastRegistry()))->serialize($request, new PipelineContext(
            request: $request,
            config: new ClientConfig(baseUrl: 'https://fixture.test'),
            traceId: 'upload',
        ));
        $wire = (string) $prepared->stream;
        preg_match('/Content-Disposition: ([^\r\n]+)/', $wire, $match);
        $parameters = HeaderUtils::combine(HeaderUtils::split($match[1], ';='));
        expect($parameters['name'])->toBe('document')
            ->and($parameters['filename'])->toBe('a%22; name=%22evil.txt')
            ->and($request->file->filename)->toBe($filename)
            ->and($wire)->toContain("\r\n\r\nsample\r\n")
            ->and(substr_count($wire, 'Content-Disposition:'))->toBe(1);
    } finally {
        $request?->file?->close();
        unlink($path);
    }
});
