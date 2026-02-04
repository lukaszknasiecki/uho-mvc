# UHO-MVC

Simple PHP model-view-controller framwework
using own ORM and Twig as templating system.

## Setting up

To setup run:

`composer install`

## System requirements

This project is using PHP8.2+ and mySQL

## Schema Validation

You can validate your schemas with `schema-validate` script. By default script with validate all schemas
from `application/models/json` folder. You also define list of models to validate in `/application_config/schemas.json` and use `application_config/schemas.json` folder as first parameter of the command, and optional folder to look
for schemas as a second parameter.

Examples:

`chmod +x vendor/lukaszknasiecki/uho-mvc/bin/schema-validate`
`vendor/lukaszknasiecki/uho-mvc/bin/schema-validate`

or

`vendor/lukaszknasiecki/uho-mvc/bin/schema-validate application_config`
`vendor/lukaszknasiecki/uho-mvc/bin/schema-validate cms/application/models/_schemas.json cms/application/models/json`

## Model Building

Now, you can build/update your initial SQL tables for models with defined schemas. First parameter should be filename of your `.env` file. By default script with validate all schemas from `application/models/json` folder. You also define list of models to validate in `/application_config/schemas.json` by using `application_config` folder as the second parameter of the command, and an optional folder to look for schemas as a thirs parameter.


`chmod +x vendor/lukaszknasiecki/uho-mvc/bin/schema-build`
`vendor/lukaszknasiecki/uho-mvc/bin/schema-build application_config/.env`

or

`vendor/lukaszknasiecki/uho-mvc/bin/schema-build application_config/.env application_config/schemas.json`


## Tests

You can perform framework unit tests with:

### Run all tests
`composer test`

### Or directly
`vendor/bin/phpunit`

## Contact

lukasz@knasiecki.com