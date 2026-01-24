## This folder contains json schemas connected with uho-mvc framework:

`_uho_orm_fields.json`


Object used to validate model schemas, objects in fields array. You can use it to see what properties can be assigned to the field object based on its type.


Each field can have two properties: `allowed` and `required`, where the first one lists all allowed properties for the fiels, the second lists required properties.


Please, note that common properties for all fields are set in first object with key `_all`


`uho_worker.json`


Standard uho-mvc schema used by *_uho_woker class*


