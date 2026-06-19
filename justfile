# List available recipes
default:
    @just --list

# Install dependencies
install:
    composer install

# Update dependencies
update:
    composer update

# Fix code style
lint:
    vendor/bin/pint

# Check code style without fixing
lint-check:
    vendor/bin/pint --test

# Run static analysis
analyse:
    vendor/bin/phpstan analyse

# Run tests
test:
    vendor/bin/pest

# Run tests with coverage
test-coverage:
    vendor/bin/pest --coverage

# Run all checks
check: lint-check analyse test
