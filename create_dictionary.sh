#!/usr/bin/env bash

#mkdir -p dane
rm -rf dane
php  src/cli/dictionaries/match_product_categories.php
php  src/cli/dictionaries/check_category_leaves.php 
php  src/cli/dictionaries/fetch_category_parameters.php
php  src/cli/dictionaries/fetch_category_product_parameters.php 
php  src/cli/dictionaries/fetch_shipping_rates.php
php  src/cli/dictionaries/fetch_return_policies.php
php  src/cli/dictionaries/fetch_implied_warranties.php
php src/cli/dictionaries/fetch_responsible_producers.php
php src/cli/dictionaries/fetch_responsible_persons.php
