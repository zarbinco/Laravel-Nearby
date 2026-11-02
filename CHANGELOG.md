# Changelog

## 1.0.0 (upcoming)

The first stable release of Laravel Nearby.

### Added

- Immutable `Bounds` viewports with inclusive containment, signed antimeridian equivalence, and `Bounds::fromViewport()` for continuous unwrapped map coordinates.
- Eloquent `withinBounds()` filtering with configurable coordinate columns and composition with distance searches, ordering, and pagination.
- Grouped viewport reports with explicit projections, aggregate counts, `HAVING`, and pagination, with documented Laravel grouped-count semantics.
- Immutable coordinates with explicit integer and float validation, inclusive geographic bounds, and canonical antimeridian longitudes.
- Spherical Haversine distance calculations through `Geo::distance()` using a mean Earth radius of 6,371,008.8 meters.
- Immutable distance values, six units, convenient factories, unrounded conversions, and meaningful validation and overflow exceptions.
- Laravel 13 Eloquent integration through `HasNearbyCoordinates`, using existing numeric columns and configurable coordinate column names.
- Nearest-record queries, inclusive radius and distance-band filters, distance ordering, deterministic ties, and pagination support.
- Read-only `nearby_distance_meters` results and `nearbyDistance()` conversion without persisting calculated values.
- Conservative bounding-box filtering that retains the exact geographic distance condition.
- MySQL 8.4 connection checks and validation of replacement PDOs during automatic reconnection, before geographic SQL preparation.
- Support for PHP 8.3, 8.4, and 8.5, with standalone calculations that require no Laravel bootstrapping or database connection.

### Fixed

- Preserve MySQL replacement-connection validation for grouped pagination count queries, rejecting unsupported replacement servers before preparing SQL.
