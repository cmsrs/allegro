#!/usr/bin/env bash

#mkdir -p dane
rm -rf dane
php  src/cli/rest/match_product_categories.php
php  src/cli/rest/check_category_leaves.php 
php  src/cli/rest/fetch_category_parameters.php
php  src/cli/rest/fetch_category_product_parameters.php 


