<?php

namespace Omnibus\Aramex;

use Omnibus\Model\Address;
use Omnibus\Model\Shipment;
use Omnibus\Model\TrackingStatus;

/** Aramex's shapes for ours. Products: PDX Priority Document Express, PPX Priority Parcel Express, EPX Economy Parcel Express, OND domestic, CDS domestic. */
final class Mapping
{
    public const PRODUCTS = ['PPX' => 'Priority Parcel Express', 'PDX' => 'Priority Document Express', 'EPX' => 'Economy Parcel Express', 'GPX' => 'Ground Parcel Express', 'OND' => 'Overnight Domestic', 'CDS' => 'Deferred Domestic'];

    public static function party(Address $a, ?string $reference = null): array
    {
        return array_filter([
            'Reference1' => $reference,
            'PartyAddress' => array_filter(['Line1' => mb_substr($a->line(0), 0, 50), 'Line2' => mb_substr($a->line(1), 0, 50) ?: null, 'City' => $a->city, 'PostCode' => $a->postcode, 'CountryCode' => strtoupper($a->country)]),
            'Contact' => array_filter(['PersonName' => mb_substr($a->name, 0, 50), 'CompanyName' => mb_substr($a->company ?? $a->name, 0, 50), 'PhoneNumber1' => $a->phone ?? '0', 'CellPhone' => $a->phone ?? '0', 'EmailAddress' => $a->email]),
        ]);
    }

    public static function details(Shipment $s, string $product): array
    {
        $international = strtoupper($s->sender->country) !== strtoupper($s->recipient->country);
        $parcel = $s->parcels[0];

        return array_filter([
            'Dimensions' => $parcel->length && $parcel->width && $parcel->height ? ['Length' => $parcel->length, 'Width' => $parcel->width, 'Height' => $parcel->height, 'Unit' => 'CM'] : null,
            'ActualWeight' => ['Value' => round(max(0.1, $s->weight() / 1000), 2), 'Unit' => 'KG'],
            'ChargeableWeight' => ['Value' => round(max(0.1, $s->weight() / 1000), 2), 'Unit' => 'KG'],
            'NumberOfPieces' => \count($s->parcels),
            'ProductGroup' => $international ? 'EXP' : 'DOM',
            'ProductType' => $product,
            'PaymentType' => 'P',
            'PaymentOptions' => '',
            'DescriptionOfGoods' => (string) $s->option('description', 'Merchandise'),
            'GoodsOriginCountry' => strtoupper($s->sender->country),
            'CustomsValueAmount' => $international ? ['Value' => array_sum(array_map(static fn ($p) => $p->value ?? 0, $s->parcels)) / 100, 'CurrencyCode' => $parcel->currency] : null,
            'Services' => (string) $s->option('services', ''),
        ], static fn ($v) => null !== $v);
    }

    public static function status(?string $code, ?string $description = null): TrackingStatus
    {
        $d = strtolower((string) $description);

        return match (true) {
            \in_array($code, ['SH005', 'SH006'], true) || str_contains($d, 'delivered') || str_contains($d, 'collected by consignee') => TrackingStatus::DELIVERED,
            'SH003' === $code || str_contains($d, 'out for delivery') => TrackingStatus::OUT_FOR_DELIVERY,
            str_contains($d, 'collected from shipper') || str_contains($d, 'picked up') => TrackingStatus::IN_TRANSIT, // Aramex has the parcel
            str_contains($d, 'held') || (bool) preg_match('/(ready|awaiting|available) for (collection|pickup)|for collection at/', $d) => TrackingStatus::AVAILABLE_FOR_PICKUP,
            \in_array($code, ['SH068', 'SH069'], true) || str_contains($d, 'return') => TrackingStatus::RETURNED,
            \in_array($code, ['SH007', 'SH008', 'SH009', 'SH010', 'SH011', 'SH012'], true) || str_contains($d, 'attempt') || str_contains($d, 'refused') || str_contains($d, 'incorrect') => TrackingStatus::EXCEPTION,
            'SH014' === $code || str_contains($d, 'record created') || str_contains($d, 'shipment created') => TrackingStatus::PENDING,
            null !== $code && '' !== $code => TrackingStatus::IN_TRANSIT,
            default => TrackingStatus::UNKNOWN,
        };
    }
}
