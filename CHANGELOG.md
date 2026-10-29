# Changelog

## [Unreleased]

### Fixed
- A `CarrierException` no longer carries the password and the account PIN. The message quoted the
  HTTP client's (the whole URL), the previous was the client's own exception, and every trace of the
  chain kept the arguments of the calls under it (the request's options, the `Api` with its
  credentials) when `zend.exception_ignore_args` is off: a log of the whole chain wrote them. The
  message masks them as `***`, the previous is a copy of the client's exception (the same class and
  code), and no exception `Api` throws keeps its trace's arguments (`Omnibus\Http\Redaction`).
- "Collected from shipper" is in transit (`IN_TRANSIT`), "collected by consignee" delivered: any
  text with `collect` read `AVAILABLE_FOR_PICKUP`. A parcel held, or ready, awaiting or available
  for collection, still is.
- Offices come nearest first as far as Aramex allows: those of the address's postcode, then city,
  then the others by great-circle distance to them, then those without coordinates; Aramex answers
  every office of the country in no order and without distance, and only the city came first.
  `distance` stays null (it would not be from the address). An `Entity` shared by several offices
  is told apart in the id by the office's location (`AMM:Amman Abdali`).
