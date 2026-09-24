# Проверка Laravel-интеграции

Из корня пакета:

```bash
python3 tests/Support/install.py --core ../php
composer test:integration
```

Это отдельное Laravel 13 приложение с собственным lock. Адаптер и учебный SDK подключаются через
path repository без require-dev самого пакета. Ядро по умолчанию берётся из Packagist;
`--core` включает локальную подмену только в игнорируемом `composer.local.json` с
собственным `composer.local.lock`, сохраняя основные manifest и lock. Зависимости устанавливаются
в `.integration/vendor` вне дерева tests, чтобы Pest не обходил циклическую
ссылку path-пакета при поиске datasets. Эта папка исключена из Git.

Проверяются discovery, HTTP, явная RequestFactory, два клиента, Artisan, два задания
в одном процессе, config:cache и повторный bootstrap. HTTP заменён MockTransport.
По умолчанию БД, Redis и внешний API не используются. Это проверка sync dispatch и жизненного цикла
приложения, не подтверждение Octane или распределённого queue worker.

Успех завершается строкой `Laravel 13: ... — OK.`. Незавершённый проход возвращает
ненулевой код даже если обработчик исключений Laravel завершил CLI с кодом 0.
Mock-ответ учебного SDK должен соответствовать его актуальной декларации DTO.

Учебный SDK устанавливается отдельным path-пакетом `example/records-sdk`; основной
тест не добавляет ему PSR-4 вручную и не регистрирует его provider. Для локального
ядра стенд задаёт версию `0.1.0`, адаптера — `0.1.0`. До публикации ядра
или при совместной разработке используйте
`python3 tests/Support/install.py --core /absolute/path/to/php`.

Без `--core` скрипт не использует прошлую локальную подмену. Исходники для учебного
SDK и проверок загружаются отдельно по ревизии установленного ядра. У обоих окружений
первый install без lock создаёт его; locked-прогоны CI требуют основные lock после
публикации совместимого ядра.

`composer test:integration` запускает основной сценарий, `verify-sdk.php` (порядок providers,
request-first, overrides, контексты приложений) и `check-sdk-config.py` (publish и
config cache в отдельных процессах). Python-стенд восстанавливает исходные файлы config.

`composer check-package` также устанавливает этот SDK из каждого архива в независимые
Laravel-приложения без dev-зависимостей: с адаптером и без него. Во втором случае
проверяются discovery, config cache, явная сборка и инструкция установки при запросе DI.
При изменении учебного SDK или используемого им API адаптера проверяйте оба текущих
дерева до слияния. Для отдельной проверки рабочего дерева:

```bash
python3 tests/Support/check-sdk-package.py --root . --core .test/core
```

`APISUTRA_LARAVEL_VENDOR` позволяет архивному стенду использовать собственный vendor;
обычный запуск использует `.integration/vendor`. `APISUTRA_PHP` выбирает PHP
для дочерних процессов Python-стенда.

По умолчанию Redis не нужен. `APISUTRA_TEST_REDIS=1` включает проверку подключения
phpredis к отдельному тестовому Redis; host/port задают `APISUTRA_REDIS_HOST` и
`APISUTRA_REDIS_PORT`. Матрица зависимостей и проверка архивов описаны в
[руководстве разработчика](../../../docs/ru/development/testing.md).
