phystrix-async-demo
===================
Combining phystrix with asynchronous programming for fault tolerant software.

## About

This repository shows the proof of concept related to the following blog posts:

- http://labs.qandidate.com/blog/2014/07/14/fault-tolerant-programming-in-php/

## Proof of concept

In this proof of concept there are three "services":

- Catalogue service, which holds information about the products.
- Inventory service, which holds information about how many items of a products are available.
- Public shop api service, which queries the two other services and combines their responses.

The public api service queries the two other services asynchronously. If the
inventory service takes to long to respond the public api service will fall
back to telling the frontend that there are -1 items.

## Running the demo

Install the dependencies with composer:

```
$ composer install
```

Start the services:

```
$ bin/run_all.sh
```

In a new terminal, start querying the main service:

```
$ bin/get_catalogue_inventory_status.sh
```

Finally, edit `inventory-status/index.php` and enable the `sleep(1);` statement
to introduce latency in the service to see phystrix taking over, and to fix the
latency in the service to see phystrix recovering.
