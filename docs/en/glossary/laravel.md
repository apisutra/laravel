<!-- languages --> <a href="laravel.md">English</a> · <a href="../../ru/glossary/laravel.md">Русский</a> <!-- /languages -->
# Laravel integration <a id="section-1"></a>

| Term | Meaning | Details |
| --- | --- | --- |
| <a id="requestfactoryinterface"></a> RequestFactoryInterface | A contract for explicitly creating and populating an SDK request from an array or an incoming Laravel Request. | [Contract](../reference/integrations/laravel.md) |
| <a id="sdkserviceprovider"></a> SdkServiceProvider | The package's Laravel service provider: registers dependencies, discovery, and client bindings for requests. | [Contract](../reference/integrations/laravel.md) |
| <a id="containerproviderinterface"></a> ContainerProviderInterface | Optional access to the application container, environment, and validation factory. | [Contract](https://github.com/apisutra/php/blob/master/docs/en/reference/client/construction.md) |
| <a id="containerproviderregistry"></a> ContainerProviderRegistry | Stores the shared container provider; supports setting, resetting, and resolving it with a local override. | [Contract](https://github.com/apisutra/php/blob/master/docs/en/reference/client/construction.md) |
| <a id="laravelcontainerprovider"></a> LaravelContainerProvider | Adapts the Laravel container to ContainerProviderInterface. | [Contract](https://github.com/apisutra/php/blob/master/docs/en/reference/client/construction.md) |
| <a id="nullcontainerprovider"></a> NullContainerProvider | An implementation for use without a container: dependencies and application information are unavailable. | [Contract](https://github.com/apisutra/php/blob/master/docs/en/reference/client/construction.md) |
| <a id="clientresponseadapterinterface"></a> ClientResponseAdapterInterface | A contract for converting ClientResponse into a Laravel application HTTP response. | [Contract](../reference/integrations/laravel.md) |
| <a id="clientresponseadapter"></a> ClientResponseAdapter | A built-in adapter from ClientResponse to a Laravel/Symfony response. | [Contract](../reference/integrations/laravel.md) |

[All terms](https://github.com/apisutra/php/blob/master/docs/en/glossary/README.md).
