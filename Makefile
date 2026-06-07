.PHONY: install test stan pint pint-check check

install:
	composer install

test:
	vendor/bin/phpunit

stan:
	vendor/bin/phpstan analyse --memory-limit=512M

pint:
	vendor/bin/pint

pint-check:
	vendor/bin/pint --test

check: pint-check stan test
