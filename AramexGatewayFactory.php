<?php

namespace Omnibus\Aramex;

use Omnibus\Aramex\Action\PickupAction;
use Omnibus\Aramex\Action\RatingAction;
use Omnibus\Aramex\Action\ShippingAction;
use Omnibus\Aramex\Action\TrackingAction;
use Omnibus\Config;
use Omnibus\GatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

/**
 *   options:
 *     username: '%env(ARAMEX_USERNAME)%'          # the web services account (an email)
 *     password: '%env(ARAMEX_PASSWORD)%'
 *     account_number: '%env(ARAMEX_ACCOUNT)%'
 *     account_pin: '%env(ARAMEX_PIN)%'
 *     account_entity: '%env(ARAMEX_ENTITY)%'      # e.g. AMM, DXB, BAH: the account's station
 *     account_country: '%env(ARAMEX_COUNTRY)%'    # e.g. JO, AE, BH
 *     sandbox: true
 *     rates: [...]                                # optional: configured prices instead of CalculateRate
 */
final class AramexGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibus.factory_name' => 'aramex',
            'omnibus.factory_title' => 'Aramex',
            'omnibus.policy' => __DIR__.'/policy.json',
            'omnibus.required_options' => ['username', 'password', 'account_number', 'account_pin', 'account_entity', 'account_country'],
            'sandbox' => false,
            'omnibus.api' => function (Config $c) {
                $http = $this->http ?? HttpClient::create();

                return new Api($http, (string) $c['username'], (string) $c['password'], (string) $c['account_number'], (string) $c['account_pin'], (string) $c['account_entity'], (string) $c['account_country'], (bool) $c['sandbox'], backoff: $c->backoff());
            },
            'omnibus.action.rating' => static fn (Config $c) => $c->get('rates') ? null : new RatingAction(),
            'omnibus.action.shipping' => new ShippingAction(),
            'omnibus.action.tracking' => new TrackingAction(),
            'omnibus.action.pickup' => new PickupAction(),
        ]);
    }
}
