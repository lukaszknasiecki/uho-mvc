# UHO-MVC

Simple PHP model-view-controller framwework
using own ORM and Twig as templating system.

## Setting up

To setup run:

`composer install`

## System requirements

This project is using PHP8.2+ and mySQL


## Schema Validation

You can validate your schemas with `schema-validate` script. You can either point to root folder of your app, if you have defined all your schemas in`/application/models/schemas.json` or point to folder with json schema files, like `application/models/json`.


`chmod +x vendor/lukaszknasiecki/uho-mvc/bin/schema-validate`

`vendor/lukaszknasiecki/uho-mvc/bin/schema-validate application/models/json`

## Model Building

Now, you can build/update your initial SQL tables for models with defined schemas. First parameter should be filename of your `.env` file, second one is your schemas folder.


`chmod +x vendor/lukaszknasiecki/uho-mvc/bin/schema-build`

`vendor/lukaszknasiecki/uho-mvc/bin/schema-build application_config/.env application/models/json`

## 

## Contact

lukasz@knasiecki.com