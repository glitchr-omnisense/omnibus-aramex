<?php

namespace Omnibus\Aramex\Tests;

use Omnibus\Aramex\AramexGatewayFactory;
use Omnibus\GatewayFactoryInterface;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The conformance suite on Aramex's recorded answers (JSON web services, sandbox). */
final class AramexContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new AramexGatewayFactory($http);
    }

    protected function gatewayOptions(): array
    {
        return ['username' => 'ws@example.org', 'password' => 'pass', 'account_number' => '20016', 'account_pin' => '331421', 'account_entity' => 'DXB', 'account_country' => 'AE', 'sandbox' => true];
    }

    protected function contractShipment(): Shipment
    {
        return new Shipment(new Address('Glitch Art', ['Sheikh Zayed Road'], '00000', 'Dubai', 'AE', phone: '+97141234567'), new Address('Alex Martin', ['King Abdullah II St'], '11118', 'Amman', 'JO', phone: '+96261234567'), [new Parcel(2000, 30, 20, 10, 5000, 'USD')], reference: 'ORDER-1042');
    }

    protected function contractTrackingNumber(): string
    {
        return '42345678901';
    }

    protected function recordedAnswers(): array
    {
        return [
            '/CalculateRate' => static fn (string $method, string $url, array $options) => ['HasErrors' => false, 'TotalAmount' => ['CurrencyCode' => 'USD', 'Value' => str_contains((string) $options['body'], '"PPX"') ? 48.5 : 31.0]],
            '/CreateShipments' => ['HasErrors' => false, 'Shipments' => [['ID' => '42345678901', 'HasErrors' => false, 'ShipmentLabel' => ['LabelURL' => 'https://ws.sbx.aramex.net/content/rpt_cache/42345678901.pdf', 'LabelFileContents' => base64_encode('%PDF-1.4 aramex')]]]],
            '/TrackShipments' => ['HasErrors' => false, 'TrackingResults' => [['Key' => '42345678901', 'Value' => [
                ['UpdateCode' => 'SH005', 'UpdateDescription' => 'Delivered', 'UpdateDateTime' => '/Date(1790933400000)/', 'UpdateLocation' => 'Amman, Jordan'],
                ['UpdateCode' => 'SH014', 'UpdateDescription' => 'Record created.', 'UpdateDateTime' => '/Date(1790836000000)/', 'UpdateLocation' => 'Dubai, United Arab Emirates'],
            ]]]],
            '/FetchOffices' => ['HasErrors' => false, 'Offices' => [
                ['Entity' => 'AQJ', 'OfficeLocation' => 'Aqaba', 'OfficeAddress' => ['Line1' => 'Al Hammamat Al Tunisyya St', 'City' => 'Aqaba', 'CountryCode' => 'JO']],
                ['Entity' => 'AMM', 'OfficeLocation' => 'Amman', 'OfficeAddress' => ['Line1' => 'Mecca St', 'City' => 'Amman', 'CountryCode' => 'JO', 'Latitude' => 31.95, 'Longitude' => 35.91]],
                ['Entity' => 'IRB', 'OfficeLocation' => 'Irbid', 'OfficeAddress' => ['Line1' => 'University St', 'City' => 'Irbid', 'CountryCode' => 'JO']],
            ]],
        ];
    }
}
