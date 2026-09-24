<!-- languages --> <a href="../../en/glossary/laravel.md">English</a> · <a href="laravel.md">Русский</a> <!-- /languages -->
# Laravel интеграция <a id="section-1"></a>

| Термин | Значение | Подробнее |
| --- | --- | --- |
| <a id="requestfactoryinterface"></a> RequestFactoryInterface | Контракт явного создания и заполнения SDK-запроса из массива или входящего Laravel Request. | [Контракт](../reference/integrations/laravel.md) |
| <a id="sdkserviceprovider"></a> SdkServiceProvider | Laravel service provider пакета: регистрирует зависимости, discovery и привязку клиентов к запросам. | [Контракт](../reference/integrations/laravel.md) |
| <a id="containerproviderinterface"></a> ContainerProviderInterface | Опциональный доступ к контейнеру приложения, окружению и фабрике валидации. | [Контракт](https://github.com/apisutra/php/blob/master/docs/ru/reference/client/construction.md) |
| <a id="containerproviderregistry"></a> ContainerProviderRegistry | Хранит общий provider контейнера; позволяет задать, сбросить и разрешить его с локальным override. | [Контракт](https://github.com/apisutra/php/blob/master/docs/ru/reference/client/construction.md) |
| <a id="laravelcontainerprovider"></a> LaravelContainerProvider | Адаптирует контейнер Laravel к ContainerProviderInterface. | [Контракт](https://github.com/apisutra/php/blob/master/docs/ru/reference/client/construction.md) |
| <a id="nullcontainerprovider"></a> NullContainerProvider | Реализация для работы без контейнера: зависимости и сведения приложения недоступны. | [Контракт](https://github.com/apisutra/php/blob/master/docs/ru/reference/client/construction.md) |
| <a id="clientresponseadapterinterface"></a> ClientResponseAdapterInterface | Контракт преобразования ClientResponse в HTTP-ответ приложения Laravel. | [Контракт](../reference/integrations/laravel.md) |
| <a id="clientresponseadapter"></a> ClientResponseAdapter | Готовый адаптер ClientResponse в ответ Laravel/Symfony. | [Контракт](../reference/integrations/laravel.md) |

[Все термины](https://github.com/apisutra/php/blob/master/docs/ru/glossary/README.md).
