# omnibus/aramex

Aramex for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): rates, shipments with
their labels, tracking and offices, through Aramex's web services in their JSON form. The gateway
calls, under `https://ws.aramex.net/ShippingAPI.V2` (live) or
`https://ws.sbx.aramex.net/ShippingAPI.V2` (the sandbox, `sandbox: true`):

| Operation | Service | Request |
|---|---|---|
| rate | RateCalculator | `POST /RateCalculator/Service_1_0.svc/json/CalculateRate`, once per product |
| ship | Shipping | `POST /Shipping/Service_1_0.svc/json/CreateShipments` |
| track | Tracking | `POST /Tracking/Service_1_0.svc/json/TrackShipments` |
| pickup points | Location | `POST /Location/Service_1_0.svc/json/FetchOffices` |

Every request carries the account's `ClientInfo` (version `v1`, source `24`) and a `Transaction`
whose `Reference1` is `omnibus`, with a 20-second timeout.

## Installation

```sh
composer require omnibus/aramex
```

Requires PHP 8.2, `omnisense/omnibus` (2.x) and `symfony/http-client` (6.4, 7 or 8); given no
HTTP client, the factory makes one with `HttpClient::create()`. In a Symfony application with
`Omnibus\Bridge\Symfony\OmnibusBundle`, the factory is registered as soon as the package is
installed.

## Credentials

An Aramex account with web services access (the account manager enables it) gives six values,
all sent in `ClientInfo`: the web services **username** (an email) and **password**, the
**account number**, its **PIN**, its **entity** (the account's station: `AMM`, `DXB`, `BAH`...)
and its **country** (`JO`, `AE`, `BH`...). The sandbox has its own set.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `username` | string | - | yes | the web services login |
| `password` | string | - | yes | its password |
| `account_number` | string | - | yes | the account; also the shipper's `AccountNumber` |
| `account_pin` | string | - | yes | the account's PIN |
| `account_entity` | string | - | yes | the account's station |
| `account_country` | string | - | yes | the account's country (sent in capitals) |
| `sandbox` | bool | `false` | no | `true`: `ws.sbx.aramex.net` |
| `rates` | list | - | no | prices from configuration instead of `CalculateRate` ([the rates option](https://github.com/glitchr-omnisense/omnibus/blob/2.x/docs/configuration.md#the-rates-option)): with it, `CalculateRate` is not called |

Shipment options (`Shipment::$options`):

| Option | Read by | Default | |
|---|---|---|---|
| `products` | rate | `PPX`, `EPX` across borders; `OND`, `CDS` at home | the products to price |
| `currency` | rate | `USD` | the preferred currency of the prices |
| `description` | rate, ship | `Merchandise` | the description of goods |
| `services` | rate, ship | `''` | Aramex's service codes, comma-separated |
| `instructions` | ship | `''` | comments for the courier, 60 characters |
| `label_format` | ship | `PDF` | `PDF` or `ZPL` |

## Capabilities

| Request | Supported |
|---|---|
| `Rating` | yes (`CalculateRate`), or configured rates with `rates` |
| `Shipping` | yes |
| `Tracking` | yes |
| `Pickup` | yes (Aramex offices) |
| `GetSlip` | no |
| `Cancel` | no |
| `Paperless` | no |

## Behaviour

**Products.** The service is Aramex's product type; labels come from
`Omnibus\Aramex\Mapping::PRODUCTS`:

| Product | Label | |
|---|---|---|
| `PPX` | Priority Parcel Express | the default across borders |
| `PDX` | Priority Document Express | |
| `EPX` | Economy Parcel Express | |
| `GPX` | Ground Parcel Express | |
| `OND` | Overnight Domestic | the default at home |
| `CDS` | Deferred Domestic | |

Another product is labelled `Aramex <code>`. A shipment is international when the sender's and the
recipient's countries differ: its product group is `EXP`, else `DOM`.

**Shipment details.** Rating and shipping send the same details: the first parcel's dimensions in
centimetres (when all three are given), the total weight in kilograms rounded to two decimals (at
least 0.1) as actual and chargeable weight, the number of parcels, the product group and type,
payment type `P` (prepaid by the shipper), the `description`, the sender's country as origin of
the goods, the `services`, and across borders the customs value (the sum of the parcels' `value`
in the first parcel's currency). A party is its two address lines (50 characters), city, postcode
and country, and its contact: name, company (the name when there is none), the phone as phone and
mobile (`0` when there is none), the email.

**Rate.** One `CalculateRate` per product (`products`, else the default above, whatever
`Shipment::$service` says) with the `currency`. Each answer gives a rate: its `TotalAmount` in its
currency; `days` is `null`. A product Aramex refuses is left out; when every product is refused,
the last refusal is raised. Rates come cheapest first; `toPickupPoint` is `false`.

**Ship.** One `CreateShipments` with one shipment: the reference as `Reference1` (of the shipment
and of both parties), the shipper with the account number, the shipping time
`Shipment::$shippingDate` (else now) and a due date three days later, both in Aramex's
`/Date(milliseconds)/` form, the `instructions` as comments, the product `Shipment::$service`
(else `PPX` or `OND`). The label is asked for as report `9729` returned as a URL (PDF), or report
`9201` returned inline (`RPT`) for `ZPL`. A shipment with `HasErrors` raises its first
notification. The tracking number is the shipment's `ID`; the label's `content` is
`LabelFileContents` base64-decoded when given, its `url` `LabelURL`; the format `application/zpl`
or `application/pdf`. `trackingUrl` is `https://www.aramex.com/track/results?ShipmentNumber=<number>`.
A response without an ID is a `CarrierException` (`Aramex booked no shipment.`). `pickupPoint` is
not sent.

**Track.** `TrackShipments` with every update (`GetLastTrackingUpdateOnly` false); the locale is
not sent. Each update: its time read from `/Date(milliseconds)/` (else as written), its
description, location and code (kept as `code`); sorted oldest first. The status is the latest
update's, read in this order:

| Code | Or the description contains | `TrackingStatus` |
|---|---|---|
| `SH005`, `SH006` | `delivered` | `DELIVERED` |
| `SH003` | `out for delivery` | `OUT_FOR_DELIVERY` |
| | `held`, `collect` | `AVAILABLE_FOR_PICKUP` |
| `SH068`, `SH069` | `return` | `RETURNED` |
| `SH007` to `SH012` | `attempt`, `refused`, `incorrect` | `EXCEPTION` |
| `SH014` | `record created`, `shipment created` | `PENDING` |
| any other code | | `IN_TRANSIT` |
| no code | | `UNKNOWN` |

`collect` also matches "collected", so an update saying the parcel was collected from the shipper
reads as `AVAILABLE_FOR_PICKUP` unless its code says otherwise.

> [!WARNING]
> Known defect: "collected from shipper" reads `AVAILABLE_FOR_PICKUP`, where the parcel is in transit.

**Pickup points.** `FetchOffices` returns every Aramex office of the address's country; the
offices in the address's city (compared without case) come first, the others keep Aramex's
order, and the list is cut to `limit`. Aramex gives no distance and no hours: `distance` is
`null` and `openingHours` empty. The id is the office's `Entity` (else its location name), the
name its `OfficeLocation`, the address its two lines, postcode, city and country, with its
coordinates. The parcel is not used.

> [!WARNING]
> Known defect: offices come with the searched city first and no distance, where the contract wants nearest first.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibus\Aramex\AramexGatewayFactory;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Symfony\Component\HttpClient\HttpClient;

$aramex = (new AramexGatewayFactory(HttpClient::create()))->create([
    'username' => getenv('ARAMEX_USERNAME'),
    'password' => getenv('ARAMEX_PASSWORD'),
    'account_number' => getenv('ARAMEX_ACCOUNT'),
    'account_pin' => getenv('ARAMEX_PIN'),
    'account_entity' => getenv('ARAMEX_ENTITY'),
    'account_country' => getenv('ARAMEX_COUNTRY'),
    'sandbox' => true,
]);

$shipment = new Shipment(
    new Address('Glitch Art', ['Sheikh Zayed Road 1'], '00000', 'Dubai', 'AE', phone: '+97141234567'),
    new Address('Ada Lovelace', ['Rainbow Street 10'], '11118', 'Amman', 'JO', email: 'ada@example.org', phone: '+96261234567'),
    [new Parcel(2000, 30, 20, 10, value: 5000, currency: 'USD')],
    service: 'PPX',
    reference: 'CMD-1042',
    options: ['description' => 'Prints', 'currency' => 'USD'],
);

foreach ($aramex->rate($shipment) as $rate) {
    printf("%s %-24s %6.2f %s\n", $rate->service, $rate->label, $rate->amount / 100, $rate->currency);
}

$label = $aramex->ship($shipment);
if (null !== $label->content) {
    file_put_contents($label->trackingNumber.'.pdf', $label->content);
}
echo $label->trackingNumber, ' ', $label->url, "\n";

$tracking = $aramex->track($label->trackingNumber);
echo $tracking->status->value, "\n";

foreach ($aramex->pickupPoints($shipment->recipient, 3) as $point) {
    echo $point->id, ' ', $point->name, ', ', $point->address->city, "\n";
}
```

In Symfony:

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
                account_entity: '%env(ARAMEX_ENTITY)%'
                account_country: '%env(ARAMEX_COUNTRY)%'
                sandbox: '%env(bool:ARAMEX_SANDBOX)%'
```

## Errors

Every failure is an `Omnibus\Exception\CarrierException` whose `carrier` is `aramex`:

- an HTTP status of 400 or more, or `HasErrors` in the answer: the first notification's
  `Message` (else `HTTP <status>`), its `Code` in `carrierCode` (`ERR52`...);
- a shipment with `HasErrors`: its first notification (else `Aramex refused the shipment.`);
- a body that is not JSON: `Aramex answered HTTP <status> with a body that is not JSON.`; a
  network error: `Aramex request failed: ...`;
- `Aramex booked no shipment.` when no ID comes back.

A missing option among the six credentials is an `InvalidConfigException` when the gateway is
built; `GetSlip`, `Cancel` and `Paperless` raise `RequestNotSupportedException` before any call.

## Verified, and not

Tested offline: `Tests/AramexGatewayTest.php` (each product priced in its own call, cheapest
first in `USD`, the account in `ClientInfo`, the product group, the weight in kilograms; a
shipment created with its label, report `9729`, the product and the customs value; tracking with
its `/Date()/` times and statuses; offices with the searched city first and their coordinates;
notifications raised with their code) and `Tests/AramexContractTest.php`, the family's
conformance suite on recorded answers. The example above was run in the core's harness on those
recorded answers.

Not verified: the README says the package is built from Aramex's published web services
documentation and was not yet run against the sandbox; that needs the credentials above.

## Responsibility

An independent, unofficial client, not affiliated with Aramex PJSC. Whoever runs it complies with
Aramex's terms ([aramex.com terms and
conditions](https://www.aramex.com/us/en/legal-details/terms-and-conditions)): see the README's
Responsibility section.
