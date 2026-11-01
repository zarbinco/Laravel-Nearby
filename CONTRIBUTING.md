# Contributing to Laravel Nearby

Thank you for helping improve Laravel Nearby. Open an issue to discuss a substantial API change before preparing a pull request, and keep changes focused on a concrete use case.

## Development

Use PHP 8.3 or later within the PHP 8 series and Composer 2. Install dependencies and run the checks from the repository root:

```bash
composer install
composer validate --strict
composer test
composer test:compatibility
composer examples
```

The GitHub Actions matrix tests PHP 8.3, 8.4, and 8.5. The calculation core must continue to work through Composer autoloading alone, without Laravel bootstrapping, a database, or external services. Laravel 13.0 is the minimum permitted framework version; do not add compatibility code for older versions.

Use strict internal types, explicit validation at public input boundaries, immutable value objects, and small classes with clear responsibilities. Match the existing formatting. Add meaningful regression tests for behavior changes, use independent numerical references, and choose floating-point tolerances that reflect the calculation being tested. Never compare spherical calculations to ellipsoidal reference values as if they were the same model.

## Writing Documentation

Write all public documentation in clear, natural English for developers using the library. Explain when a feature is useful, its inputs and units, its result, and relevant limitations. Include concise, executable PHP examples with the required imports and verify any stated numerical output. Document only working public APIs, keep comments focused on non-obvious decisions, and keep development progress reports and internal planning terminology out of public guides. Maintain one English documentation set rather than adding translated guides.

## Pull Requests

Explain the problem, the resulting behavior, and how you verified it. Update examples and documentation when the public API changes. Do not include dependencies, local environment files, caches, or generated review archives in a pull request.
