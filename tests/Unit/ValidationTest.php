<?php

declare(strict_types=1);

use ApiSutra\Laravel\ClientConfigFactory;
use ApiSutra\Laravel\LaravelContainerProvider;
use ApiSutra\Laravel\SdkServiceProvider;
use ApiSutra\Laravel\Tests\Stubs\Validation\ScopedValidationDto;
use ApiSutra\VO\Validation\Validator;
use Illuminate\Container\Container;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;

it('сохраняет useFactory поверх Laravel default и явный provider поверх useFactory', function (): void {
    $global = new Factory(new Translator(new ArrayLoader(), 'en'));
    $global->extend('fixture_rule', static fn (): bool => false, 'global');
    $local = new Factory(new Translator(new ArrayLoader(), 'en'));
    $local->extend('fixture_rule', static fn (): bool => true);
    $app = new Container();
    $app->instance('validator', $local);
    (new SdkServiceProvider($app))->register();
    $factory = $app->make(ClientConfigFactory::class);
    $config = $factory->make(['baseUrl' => 'https://fixture.test']);
    $dto = new ScopedValidationDto();
    expect(Validator::checkForClient($dto, $config)->passed())->toBeTrue();
    Validator::useFactory($global);
    expect(Validator::checkForClient($dto, $config)->passed())->toBeFalse();
    $explicit = $factory->make(['baseUrl' => 'https://fixture.test', 'containerProvider' => new LaravelContainerProvider($app)]);
    expect(Validator::checkForClient($dto, $explicit)->passed())->toBeTrue();
});
