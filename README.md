# allegro

## import danych do pliku json


Ustaw po stronie allegro OAuth 2.0 Device Authorization Grant (Device Flow), czyli autoryzację urządzenia za pomocą device_code .

nastepnie uzytkacj tokeny
php ./src/cli/authorize_device.php

sprawdz czy poprawnie dziala srodoowisko - za pomoca testow jednostkowym i integracyjnych:
./test_interation.sh 
./test_unit.sh 

Utworzenie produktu w allegro

php  src/cli/rest/match_product_categories.php 


