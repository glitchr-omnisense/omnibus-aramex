# Changelog

## [Unreleased]

### Fixed
- "Collected from shipper" is in transit (`IN_TRANSIT`), "collected by consignee" delivered: any
  text with `collect` read `AVAILABLE_FOR_PICKUP`. A parcel held, or ready, awaiting or available
  for collection, still is.
- Offices come nearest first as far as Aramex allows: those of the address's postcode, then city,
  then the others by great-circle distance to them, then those without coordinates; Aramex answers
  every office of the country in no order and without distance, and only the city came first.
  `distance` stays null (it would not be from the address). An `Entity` shared by several offices
  is told apart in the id by the office's location (`AMM:Amman Abdali`).
