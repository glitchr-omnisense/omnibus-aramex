# omnibus/aramex

Aramex for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): rates (RateCalculator),
shipments with their labels (Shipping), tracking (Tracking) and offices (Location) - the JSON
web services on ws.aramex.net.

```php
$gateway = (new AramexGatewayFactory($http))->create($options);   // $http: the application's HTTP client - none given, the factory makes its own; the options below
```

No framework needed: the package requires `omnisense/omnibus` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnibus:
    gateways:
        aramex:
            factory: aramex
            options:
                username: '%env(ARAMEX_USERNAME)%'
                password: '%env(ARAMEX_PASSWORD)%'
                account_number: '%env(ARAMEX_ACCOUNT)%'
                account_pin: '%env(ARAMEX_PIN)%'
                account_entity: '%env(ARAMEX_ENTITY)%'     # the account's station, e.g. DXB
                account_country: '%env(ARAMEX_COUNTRY)%'   # e.g. AE
                sandbox: true
                rates: [...]                               # optional: configured prices instead of CalculateRate
```

The service is the product (PPX Priority Parcel Express, EPX Economy Parcel Express, PDX
documents, OND / CDS domestic). Rating asks once per product (option `products`). Shipment
options: `label_format` (PDF, ZPL), `description`, `instructions`, `services` (Aramex's codes,
comma-separated), `currency` (for rates).

Credentials: an Aramex account with web services access (your account manager enables it) gives
the username, password, account number, PIN, entity and country; the sandbox has its own.

Built from Aramex's published web services documentation and tested on recorded answers; not yet
run against the sandbox: that needs the credentials above.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Aramex PJSC. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence covers
this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Aramex's terms ([aramex.com
terms and conditions](https://www.aramex.com/us/en/legal-details/terms-and-conditions)), for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or a
citation, a rate limit to respect), and for any use made of what it returns. The authors publish it
free of charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
