<?php

declare(strict_types=1);

namespace ApiSutra\Laravel;

use ApiSutra\Attributes\Http\Delete;
use ApiSutra\Attributes\Http\Get;
use ApiSutra\Attributes\Http\Patch;
use ApiSutra\Attributes\Http\Post;
use ApiSutra\Attributes\Http\Put;
use ApiSutra\Attributes\Request\Body;
use ApiSutra\Attributes\Request\File;
use ApiSutra\Attributes\Request\Header;
use ApiSutra\Attributes\Request\Ignore;
use ApiSutra\Attributes\Request\Path;
use ApiSutra\Attributes\Request\Query;
use ApiSutra\Contracts\Interfaces\Core\RequestInterface;
use ApiSutra\Laravel\Contracts\RequestFactoryInterface;
use ApiSutra\Core\AbstractRequest;
use ApiSutra\Laravel\RequestFactory\PayloadKeys;
use ApiSutra\Laravel\RequestFactory\RequestDescriptor;
use ApiSutra\Laravel\RequestFactory\ResolveContext;
use ApiSutra\Laravel\RequestFactory\ResolverInterface;
use ApiSutra\Laravel\RequestFactory\Resolvers\BodyNestedValueResolver;
use ApiSutra\Laravel\RequestFactory\Resolvers\BodyValueResolver;
use ApiSutra\Laravel\RequestFactory\Resolvers\FileValueResolver;
use ApiSutra\Laravel\RequestFactory\Resolvers\HeaderValueResolver;
use ApiSutra\Laravel\RequestFactory\Resolvers\PathValueResolver;
use ApiSutra\Laravel\RequestFactory\Resolvers\QueryValueResolver;
use Illuminate\Http\Request;
use ReflectionClass;
use ReflectionProperty;

/**
 * Фабрика для построения запросов SDK из входного источника.
 */
final class RequestFactory implements RequestFactoryInterface
{
    private const array METHOD_ATTRIBUTES = [
        Get::class => 'GET',
        Post::class => 'POST',
        Put::class => 'PUT',
        Patch::class => 'PATCH',
        Delete::class => 'DELETE',
    ];

    /**
     * @var array<int, ResolverInterface> Список резолверов значений.
     */
    private readonly array $valueResolvers;

    /**
     * Инициализирует цепочку резолверов значений.
     */
    public function __construct()
    {
        $this->valueResolvers = [
            new FileValueResolver(),
            new HeaderValueResolver(),
            new PathValueResolver(),
            new QueryValueResolver(),
            new BodyNestedValueResolver(),
            new BodyValueResolver(),
        ];
    }

    /**
     * Создаёт DTO запроса и заполняет значения из источника.
     */
    #[\Override]
    public function make(string $requestClass, Request|array $source): RequestInterface
    {
        $reflection = new ReflectionClass($requestClass);
        $descriptor = $this->resolveMethodAndEndpoint($reflection);
        $placeholders = $this->extractPathParams($descriptor->path);
        $payload = $this->normalizeSource($source, $descriptor->method);

        $values = $this->collectValues($reflection, $placeholders, $payload, $descriptor->method);
        $instance = $this->instantiate($reflection, $values);
        $this->fillProperties($instance, $values);

        return $instance;
    }

    /**
     * Нормализует входной источник в единый payload.
     *
     * @return array<string, mixed>
     */
    private function normalizeSource(Request|array $source, string $method): array
    {
        if (is_array($source)) {
            if ($this->isStructuredSource($source)) {
                return $this->normalizeStructuredPayload($source);
            }

            return $this->normalizeUnstructuredPayload($source);
        }

        $routeParams = $this->resolveRouteParams($source);

        return [
            PayloadKeys::ROUTE => $routeParams,
            PayloadKeys::QUERY => $source->query(),
            PayloadKeys::BODY => $this->isQueryMethod($method)
                ? $source->query()
                : $source->input(),
            PayloadKeys::HEADERS => $source->headers->all(),
            PayloadKeys::FILES => $source->allFiles(),
        ];
    }

    /**
     * Извлекает параметры маршрута из запроса.
     *
     * @return array<string, mixed>
     */
    private function resolveRouteParams(Request $request): array
    {
        return $this->routeParameters($request->route());
    }

    /** @return array<string, mixed> */
    private function routeParameters(mixed $route): array
    {
        $params = is_object($route) && method_exists($route, 'parameters')
            ? $route->parameters()
            : null;

        return is_array($route)
            ? $route
            : (is_array($params) ? $params : []);
    }

    /**
     * Проверяет, что вход уже имеет структурированные секции.
     */
    private function isStructuredSource(array $source): bool
    {
        $hasTopLevel = array_any(
            PayloadKeys::STRUCTURED_KEYS,
            static fn (string $key): bool => array_key_exists($key, $source),
        );

        return $hasTopLevel
            || (array_key_exists(PayloadKeys::BODY, $source) && is_array($source[PayloadKeys::BODY]));
    }

    /**
     * Собирает payload из структурированного ввода.
     *
     * @param array<string, mixed> $source Вход с уже структурированными секциями.
     * @return array<string, mixed>
     */
    private function normalizeStructuredPayload(array $source): array
    {
        $payload = $this->emptyPayload();
        foreach (PayloadKeys::ALL as $key) {
            $payload[$key] = is_array($source[$key] ?? null) ? $source[$key] : [];
        }

        return $payload;
    }

    /**
     * Собирает payload из неструктурированного ввода.
     * Значения одновременно попадают в query и body.
     *
     * @param array<string, mixed> $source Неструктурированный ввод.
     * @return array<string, mixed>
     */
    private function normalizeUnstructuredPayload(array $source): array
    {
        $payload = $this->emptyPayload();
        $payload[PayloadKeys::QUERY] = $source;
        $payload[PayloadKeys::BODY] = $source;

        return $payload;
    }

    /**
     * Возвращает пустой шаблон payload.
     *
     * @return array<string, mixed>
     */
    private function emptyPayload(): array
    {
        return array_fill_keys(PayloadKeys::ALL, []);
    }

    /**
     * Собирает значения свойств запроса по атрибутам.
     *
     * @param array<int, string> $placeholders
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function collectValues(
        ReflectionClass $reflection,
        array $placeholders,
        array $payload,
        string $method,
    ): array {
        $values = [];
        $isQueryMethod = $this->isQueryMethod($method);

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if ($this->getAttribute($property, Ignore::class) !== null) {
                continue;
            }

            $name = $property->getName();
            $pathAttr = $this->getAttribute($property, Path::class);
            $queryAttr = $this->getAttribute($property, Query::class);
            $bodyAttr = $this->getAttribute($property, Body::class);
            $headerAttr = $this->getAttribute($property, Header::class);
            $fileAttr = $this->getAttribute($property, File::class);

            $context = new ResolveContext(
                property: $property,
                payload: $payload,
                placeholders: $placeholders,
                isQueryMethod: $isQueryMethod,
                propertyName: $name,
                pathAttribute: $pathAttr,
                queryAttribute: $queryAttr,
                bodyAttribute: $bodyAttr,
                headerAttribute: $headerAttr,
                fileAttribute: $fileAttr,
            );

            foreach ($this->valueResolvers as $resolver) {
                if ($resolver->supports($context)) {
                    $result = $resolver->resolve($context);
                    if (!$result->isMissing()) {
                        $values[$name] = $result->value;
                    }
                    break;
                }
            }
        }

        return $values;
    }

    /**
     * Создаёт экземпляр запроса с учётом конструктора.
     */
    private function instantiate(ReflectionClass $reflection, array $values): RequestInterface
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $values)) {
                $args[$name] = $values[$name];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $args[$name] = $parameter->getDefaultValue();
            }
        }

        return $reflection->newInstanceArgs($args);
    }

    /**
     * Заполняет неабсолютно readonly свойства у AbstractRequest.
     */
    private function fillProperties(RequestInterface $request, array $values): void
    {
        if (!$request instanceof AbstractRequest) {
            return;
        }

        $reflection = new ReflectionClass($request);
        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();
            if ($property->isStatic() || $property->isReadOnly() || !array_key_exists($name, $values)) {
                continue;
            }

            $property->setValue($request, $values[$name]);
        }
    }

    /**
     * Определяет HTTP-метод и путь эндпоинта по атрибутам.
     */
    private function resolveMethodAndEndpoint(ReflectionClass $reflection): RequestDescriptor
    {
        foreach (self::METHOD_ATTRIBUTES as $attributeClass => $method) {
            $instance = $this->getClassAttribute($reflection, $attributeClass);
            if ($instance !== null) {
                return new RequestDescriptor(
                    method: $method,
                    path: $instance->path ?? '',
                );
            }
        }

        return new RequestDescriptor('GET', '');
    }

    /**
     * Извлекает имена плейсхолдеров из пути.
     *
     * @return array<int, string>
     */
    private function extractPathParams(string $endpoint): array
    {
        preg_match_all('/\{([^}]+)\}/', $endpoint, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Проверяет, что метод читает параметры из query.
     */
    private function isQueryMethod(string $method): bool
    {
        $method = strtoupper($method);
        return $method === 'GET' || $method === 'DELETE';
    }

    /**
     * Возвращает атрибут свойства, если он есть.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    private function getAttribute(ReflectionProperty $property, string $class): ?object
    {
        return ($property->getAttributes($class)[0] ?? null)?->newInstance();
    }

    /**
     * Возвращает атрибут класса, если он есть.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    private function getClassAttribute(ReflectionClass $reflection, string $class): ?object
    {
        return ($reflection->getAttributes($class)[0] ?? null)?->newInstance();
    }
}
