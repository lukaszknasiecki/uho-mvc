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
from `application/models/json` folder. You also define list of models to validate in `/application_config/schemas.json` and use `application_config/schemas.json` folder as first parameter of the command.


`chmod +x vendor/lukaszknasiecki/uho-mvc/bin/schema-validate`
`vendor/lukaszknasiecki/uho-mvc/bin/schema-validate`

or

`vendor/lukaszknasiecki/uho-mvc/bin/schema-validate application_config`

## Model Building

Now, you can build/update your initial SQL tables for models with defined schemas. First parameter should be filename of your `.env` file. By default script with validate all schemas from `application/models/json` folder. You also define list of models to validate in `/application_config/schemas.json` by using `application_config` folder as the second parameter of the command.


`chmod +x vendor/lukaszknasiecki/uho-mvc/bin/schema-build`
`vendor/lukaszknasiecki/uho-mvc/bin/schema-build application_config/.env`

or

`vendor/lukaszknasiecki/uho-mvc/bin/schema-build application_config/.env application_config/schemas.json`


## 

## Contact

lukasz@knasiecki.com