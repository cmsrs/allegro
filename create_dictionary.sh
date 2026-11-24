#!/usr/bin/env bash

#mkdir -p dane
php  src/cli/rest/match_product_categories.php
php  src/cli/rest/fetch_category_parameters.php
php  src/cli/rest/fetch_category_product_parameters.php 
