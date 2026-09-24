<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\RequestFactory;

final class PayloadKeys
{
    public const string ROUTE = 'route';
    public const string QUERY = 'query';
    public const string BODY = 'body';
    public const string HEADERS = 'headers';
    public const string FILES = 'files';

    /**
     * @var array<int, string> Ключи, указывающие на структурированный ввод.
     */
    public const array STRUCTURED_KEYS = [
        self::ROUTE,
        self::QUERY,
        self::HEADERS,
        self::FILES,
    ];

    /**
     * @var array<int, string> Полный список ключей payload.
     */
    public const array ALL = [
        self::ROUTE,
        self::QUERY,
        self::BODY,
        self::HEADERS,
        self::FILES,
    ];
}
