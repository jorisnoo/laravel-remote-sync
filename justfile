# Run Laravel Pint code formatter
lint:
    vendor/bin/pint

# Run PHPStan static analysis
analyse:
    vendor/bin/phpstan analyse

# Run Pest tests
test:
    vendor/bin/pest

# Run tests with coverage
test-coverage:
    vendor/bin/pest --coverage

# Run all checks (lint, analyse, test)
check: lint analyse test
