<?php

declare(strict_types=1);

use ApiSutra\Request\RequestSpecResolver;
use ApiSutra\Support\ContainerProviderRegistry;
use ApiSutra\VO\Validation\Validator;
use Illuminate\Container\Container;

uses()->beforeEach(function (): void {
    Container::setInstance(null);
    ContainerProviderRegistry::reset();
    Validator::resetFactory();
    RequestSpecResolver::clearCache();
})->afterEach(function (): void {
    ContainerProviderRegistry::reset();
    Validator::resetFactory();
    RequestSpecResolver::clearCache();
})->in('Unit');
