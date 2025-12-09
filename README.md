# UHO-MVC

Simple PHP model-view-controller framwework
using own ORM and Twig as templating system.

## Setting up

To setup run:

`composer install`

## System requirements

This project is using PHP8.2+ and mySQL


## Schema Validation

You can validate your schemas with `schema-validate` script. You can either point to root folder of your app, if you have defined all your schemas in`/application/models/schemas.json` or point to folder with json schema files, like `/application/models/json`.


`chmod +x vendor/lukaszknasiecki/uho-mvc/bin/schema-validate`

`vendor/lukaszknasiecki/uho-mvc/bin/schema-validate folder`

## Contact

lukasz@knasiecki.com