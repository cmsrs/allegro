# Allegro – integracja i import danych

Projekt służy do integracji z API Allegro. Jego celem jest pobieranie danych, przetwarzanie ich oraz tworzenie produktów w Allegro.

## 1. Konfiguracja Allegro OAuth

Po stronie Allegro należy utworzyć aplikację OAuth 2.0 z wykorzystaniem mechanizmu:

**Device Authorization Grant (Device Flow)**

Jest to autoryzacja urządzenia za pomocą `device_code`.

Ten sposób autoryzacji:
- nie wymaga uruchamiania lokalnego serwera HTTP,
- nie wymaga konfigurowania `redirect_uri`,
- umożliwia autoryzację bezpośrednio z poziomu skryptu PHP.

## 2. Autoryzacja i pobranie tokenów

Po utworzeniu aplikacji Allegro należy uzupełnić dane dostępowe w pliku konfiguracyjnym `.env`.

Następnie uruchomić skrypt:

```bash
php ./src/cli/authorize_device.php
```

Skrypt przeprowadzi proces autoryzacji:
1. Połączy się z Allegro.
2. Pobierze `device_code` oraz `user_code`.
3. Wyświetli adres strony i kod autoryzacyjny.
4. Umożliwi zaakceptowanie dostępu do aplikacji.
5. Będzie oczekiwał na potwierdzenie autoryzacji.
6. Pobierze `access_token` oraz `refresh_token`.
7. Zapisze tokeny w pliku wskazanym w konfiguracji.

## 3. Testowanie środowiska

Poprawność działania środowiska można sprawdzić za pomocą testów jednostkowych i integracyjnych.

### Testy integracyjne

```bash
./test_interation.sh
```

### Testy jednostkowe

```bash
./test_unit.sh
```

Testy pozwalają sprawdzić poprawność działania poszczególnych elementów aplikacji oraz komunikacji z Allegro.

## 4. Operacje na produktach Allegro - Automatyzacja procesu importu produktów na podstawie pliku: ./produkty/dane.json

### utworzenie słowników w ./dane : 

```bash
./create_dictionary.sh 
```

przykładowy raport utworzenia słownikow:

```bash
/home/rob/allegro/allegro/src/cli/dictionaries/match_product_categories.php
API: GET /sale/matching-categories
Kategorie do dopasowania: 2
Pobieram token Allegro...
Zapisano dopasowania dla 2 kategorii do /home/rob/allegro/allegro/dane/kategorie_allegro.json

/home/rob/allegro/allegro/src/cli/dictionaries/check_category_leaves.php
API: GET /sale/categories/{categoryId}
Wszystkie sprawdzone kategorie są liśćmi.
Sprawdzono: 2 | liście: 2 | nie-liście: 0

/home/rob/allegro/allegro/src/cli/dictionaries/fetch_category_parameters.php
API: GET /sale/categories/{categoryId}/parameters
Pobrane parametry kategorii/ofert:
ID 319286 | Wody perfumowane | parametrów: 9
ID 253354 | Odświeżacze powietrza | parametrów: 21
Zapisano JSON: /home/rob/allegro/allegro/dane/parametry_kategorii_allegro.json

/home/rob/allegro/allegro/src/cli/dictionaries/fetch_category_product_parameters.php
API: GET /sale/categories/{id}/product-parameters
Pobrane parametry produktowe:
ID 319286 | Wody perfumowane | parametrów: 7
ID 253354 | Odświeżacze powietrza | parametrów: 19
Zapisano JSON: /home/rob/allegro/allegro/dane/parametry_produktowe_kategorii_allegro.json
```


### wywołanie, dodanie produktów do allegro:

```bash
./create_offer.sh
```
