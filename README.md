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

## 4. Operacje na produktach Allegro
Dopasowanie produktów do kategorii

Uruchomienie skryptu:

php ./src/cli/rest/match_product_categories.php

Skrypt służy do dopasowywania produktów do odpowiednich kategorii Allegro.
## 5. Planowany zakres funkcjonalności

    Pobieranie danych z Allegro.

    Przetwarzanie i eksport danych do plików JSON.

    Dopasowywanie produktów do kategorii Allegro.

    Tworzenie produktów w Allegro.

    Automatyzacja procesu importu produktów.
