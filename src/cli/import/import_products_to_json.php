<?php

declare(strict_types=1);

// !!!!!!!!!
// wymaga instalacji !!!
//"phpoffice/phpspreadsheet" !!!

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

/**
 * Excel -> JSON Products Importer
 *
 * Usage:
 * php src/cli/import/import_products_to_json.php ./data/dane.xlsx
 */

const JSON_COLUMNS = [
    'zewnetrzne_odniesienia',
    'zdjecia',
    'zewnetrzne_atrybuty',
    'zewnetrzne_kategorie',
    'zewnetrzne_grupy_wariantowe',
    'opis',
    'czas_wysylki',
];

const BOOLEAN_COLUMNS = [
    'kupowalny',
    'czy_pareto',
    'czy_zmatchowany',
    'zarchiwizowany',
];

const FLOAT_COLUMNS = [
    'cena',
    'cena_sprzedazy',
    'cena_katalogowa',
    'cena_przed_obnizka',
    'procent_obnizki',
];

const INTEGER_COLUMNS = [
    'stan_magazynowy',
    'laczna_liczba_sprzedanych',
];

const IDENTIFIER_COLUMNS = [
    'id',
    'id_w_sklepie',
    'id_sklepu',
    'sku',
    'ean',
    'id_aukcji_allegro',
];

/**
 * Wyświetla błąd i kończy działanie.
 */
function error(string $message): never
{
    fwrite(STDERR, "[ERROR] {$message}" . PHP_EOL);
    exit(1);
}

/**
 * Normalizacja polskich nazw kolumn.
 */
function normalizeHeader(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');

    $value = strtr($value, [
        'ą' => 'a',
        'ć' => 'c',
        'ę' => 'e',
        'ł' => 'l',
        'ń' => 'n',
        'ó' => 'o',
        'ś' => 's',
        'ź' => 'z',
        'ż' => 'z',
    ]);

    $value = preg_replace('/[^a-z0-9]+/', '_', $value);

    return trim($value, '_');
}

/**
 * Normalizacja wartości tekstowej.
 */
function cleanValue(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string) $value);

    if ($value === '' || $value === '<br>') {
        return null;
    }

    return $value;
}

/**
 * Konwersja liczb.
 */
function parseNumber(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    $value = trim((string) $value);
    $value = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $value);

    if (str_contains($value, ',')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }

    if (!is_numeric($value)) {
        return null;
    }

    $number = (float) $value;

    return is_finite($number) ? $number : null;
}

/**
 * Konwersja wartości logicznych.
 */
function parseBoolean(mixed $value): ?bool
{
    if ($value === null || $value === '') {
        return null;
    }

    $value = mb_strtolower(trim((string) $value), 'UTF-8');

    return match ($value) {
        'tak', 'yes', 'true', '1' => true,
        'nie', 'no', 'false', '0' => false,
        default => null,
    };
}

/**
 * Dekodowanie kolumn JSON.
 */
function parseJsonColumn(
    mixed $value,
    string $column,
    int $rowNumber,
    array &$errors
): mixed {

    if ($value === null || trim((string) $value) === '') {
        return null;
    }

    try {
        return json_decode(
            (string) $value,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        $errors[] = [
            'row' => $rowNumber,
            'column' => $column,
            'message' => 'Nieprawidłowy JSON: ' . $e->getMessage(),
        ];

        return null;
    }
}

/**
 * Konwersja wartości kolumny na odpowiedni typ.
 */
function convertValue(
    string $column,
    mixed $value,
    int $rowNumber,
    array &$errors
): mixed {

    $value = cleanValue($value);

    if ($value === null) {
        return null;
    }

    // Kolumny zawierające JSON.
    if (in_array($column, JSON_COLUMNS, true)) {
        return parseJsonColumn(
            $value,
            $column,
            $rowNumber,
            $errors
        );
    }

    // Boolean.
    if (in_array($column, BOOLEAN_COLUMNS, true)) {
        $result = parseBoolean($value);

        if ($result === null) {
            $errors[] = [
                'row' => $rowNumber,
                'column' => $column,
                'message' => "Nieprawidłowa wartość logiczna: {$value}",
            ];
        }

        return $result;
    }

    // Identyfikatory zawsze jako string (np. ID, EAN, SKU).
    if (in_array($column, IDENTIFIER_COLUMNS, true)) {
        return $value;
    }

    // Float.
    if (in_array($column, FLOAT_COLUMNS, true)) {
        $result = parseNumber($value);

        if ($result === null) {
            $errors[] = [
                'row' => $rowNumber,
                'column' => $column,
                'message' => "Nieprawidłowa liczba: {$value}",
            ];
        }

        return $result;
    }

    // Integer.
    if (in_array($column, INTEGER_COLUMNS, true)) {
        if (!preg_match('/^\d+$/', $value)) {
            $errors[] = [
                'row' => $rowNumber,
                'column' => $column,
                'message' => "Nieprawidłowa liczba całkowita: {$value}",
            ];

            return null;
        }

        return (int) $value;
    }

    // Pozostałe wartości jako tekst.
    return $value;
}

/**
 * Walidacja produktu.
 */
function validateProduct(
    array $product,
    int $rowNumber,
    array &$errors
): bool {

    $valid = true;

    // ID produktu jest wymagane.
    if (!isset($product['id']) || $product['id'] === '') {
        $errors[] = [
            'row' => $rowNumber,
            'column' => 'id',
            'message' => 'Brak ID produktu.',
        ];

        $valid = false;
    }

    // Nazwa produktu jest wymagana.
    if (empty($product['nazwa'])) {
        $errors[] = [
            'row' => $rowNumber,
            'column' => 'nazwa',
            'message' => 'Brak nazwy produktu.',
        ];

        $valid = false;
    }

    // Cena nie może być ujemna.
    if (
        isset($product['cena'])
        && $product['cena'] < 0
    ) {
        $errors[] = [
            'row' => $rowNumber,
            'column' => 'cena',
            'message' => 'Cena nie może być ujemna.',
        ];

        $valid = false;
    }

    if (
        isset($product['cena_sprzedazy'])
        && $product['cena_sprzedazy'] < 0
    ) {
        $errors[] = [
            'row' => $rowNumber,
            'column' => 'cena_sprzedazy',
            'message' => 'Cena sprzedaży nie może być ujemna.',
        ];

        $valid = false;
    }

    // Stan magazynowy nie może być ujemny.
    if (
        isset($product['stan_magazynowy'])
        && $product['stan_magazynowy'] < 0
    ) {
        $errors[] = [
            'row' => $rowNumber,
            'column' => 'stan_magazynowy',
            'message' => 'Stan magazynowy nie może być ujemny.',
        ];

        $valid = false;
    }

    // EAN powinien mieć od 8 do 14 cyfr.
    if (
        isset($product['ean'])
        && !preg_match('/^\d{8,14}$/', $product['ean'])
    ) {
        $errors[] = [
            'row' => $rowNumber,
            'column' => 'ean',
            'message' => 'Nieprawidłowy EAN.',
        ];

        $valid = false;
    }

    // Opis musi być poprawnie zdekodowanym JSON.
    if (
        isset($product['opis'])
        && !is_array($product['opis'])
    ) {
        $errors[] = [
            'row' => $rowNumber,
            'column' => 'opis',
            'message' => 'Opis musi być poprawną strukturą JSON.',
        ];

        $valid = false;
    }

    return $valid;
}

/**
 * Import produktów.
 */
function importProducts(string $inputFile): void
{
    if (!is_file($inputFile)) {
        error("Plik nie istnieje: {$inputFile}");
    }

    if (!is_readable($inputFile)) {
        error("Brak uprawnień do odczytu: {$inputFile}");
    }

    $extension = strtolower(
        pathinfo($inputFile, PATHINFO_EXTENSION)
    );

    if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
        error('Nieobsługiwany format pliku.');
    }

    try {
        $spreadsheet = IOFactory::load($inputFile);
    } catch (Throwable $e) {
        error('Błąd odczytu Excela: ' . $e->getMessage());
    }

    $sheet = $spreadsheet->getActiveSheet();

    $highestRow = $sheet->getHighestDataRow();
    $highestColumn = $sheet->getHighestDataColumn();

    // Odczyt nagłówków.
    $headers = $sheet->rangeToArray(
        'A1:' . $highestColumn . '1',
        null,
        true,
        false
    )[0];

    $columns = [];

    foreach ($headers as $index => $header) {
        $name = normalizeHeader((string) $header);

        if ($name === '' || $name === 'br') {
            continue;
        }

        if (isset($columns[$name])) {
            error("Powielona nazwa kolumny: {$name}");
        }

        $columns[$name] = $index + 1;
    }

    if (!isset($columns['nazwa'])) {
        error('Brak wymaganej kolumny: Nazwa');
    }

    if (!isset($columns['id'])) {
        error('Brak wymaganej kolumny: ID');
    }

    $products = [];
    $errors = [];

    $total = 0;

    // Odczyt produktów.
    for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {

        $product = [];
        $hasData = false;

        foreach ($columns as $column => $columnIndex) {

            $coordinate = Coordinate::stringFromColumnIndex($columnIndex)
                . $rowNumber;

            $cell = $sheet->getCell($coordinate);

            $rawValue = $cell->getValue();

            // Puste wiersze pomijamy.
            if ($rawValue !== null && $rawValue !== '') {
                $hasData = true;
            }

            // Formuły pobieramy jako wartości obliczone.
            if ($cell->isFormula()) {
                try {
                    $rawValue = $cell->getCalculatedValue();
                } catch (Throwable) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'column' => $column,
                        'message' => 'Nie można obliczyć formuły.',
                    ];

                    continue;
                }
            }

            $product[$column] = convertValue(
                $column,
                $rawValue,
                $rowNumber,
                $errors
            );
        }

        if (!$hasData) {
            continue;
        }

        $total++;

        if (validateProduct($product, $rowNumber, $errors)) {
            $products[] = $product;
        }
    }

    // Kontrola unikalności ID i ID w sklepie.
    $seenIds = [];

    foreach ($products as $index => $product) {
        $id = $product['id'] ?? null;

        if ($id === null) {
            continue;
        }

        $idKey = (string) $id;

        if (array_key_exists($idKey, $seenIds)) {
            $errors[] = [
                'row' => null,
                'column' => 'id',
                'message' => "Powielone ID produktu: {$id}",
            ];

            unset($products[$index]);

            continue;
        }

        $seenIds[$idKey] = true;
    }

    $products = array_values($products);

    // Przy bledach walidacyjnych nie zapisujemy niekompletnego pliku.
    if ($errors !== []) {
        echo PHP_EOL;
        echo "Wykryto błędy walidacji:" . PHP_EOL;

        foreach ($errors as $item) {
            $row = $item['row'] !== null
                ? "Wiersz {$item['row']}"
                : 'Globalny';

            echo "[ERROR] {$row} | {$item['column']}: ";
            echo $item['message'] . PHP_EOL;
        }

        error(
            'Import przerwany. '
            . 'Popraw bledy w Excelu i uruchom ponownie.'
        );
    }

    if ($products === []) {
        error('Nie znaleziono produktow do zaimportowania.');
    }

    // Struktura wynikowego JSON.
    $output = [
        'version' => 1,

        'generated_at' => date(DATE_ATOM),

        'source' => basename($inputFile),

        'summary' => [
            'total' => $total,
            'imported' => count($products),
            'errors' => 0,
        ],

        'products' => $products,
    ];

    try {
        $json = json_encode(
            $output,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        error('Błąd generowania JSON: ' . $e->getMessage());
    }

    $outputFile = dirname($inputFile) . '/dane.json';

    $tempFile = $outputFile . '.tmp';

    if (
        file_put_contents(
            $tempFile,
            $json . PHP_EOL,
            LOCK_EX
        ) === false
    ) {
        error('Nie można zapisać pliku tymczasowego.');
    }

    if (!rename($tempFile, $outputFile)) {
        @unlink($tempFile);

        error('Nie można zapisać pliku wynikowego.');
    }

    echo PHP_EOL;
    echo "========================================" . PHP_EOL;
    echo "        IMPORT PRODUKTÓW EXCEL" . PHP_EOL;
    echo "========================================" . PHP_EOL;

    echo "Źródło:       {$inputFile}" . PHP_EOL;
    echo "Wynik:        {$outputFile}" . PHP_EOL;
    echo "Produktów:    {$total}" . PHP_EOL;
    echo "Zaimportowano:" . count($products) . PHP_EOL;
    echo "Błędów:       0" . PHP_EOL;

    echo "========================================" . PHP_EOL;

    echo "[SUCCESS] Import zakończony poprawnie." . PHP_EOL;
}

/**
 * CLI ENTRY POINT
 */
if (PHP_SAPI !== 'cli') {
    error('Skrypt może być uruchamiany wyłącznie z CLI.');
}

$inputFile = $argv[1] ?? null;

if ($inputFile === null) {
    error(
        'Użycie: php src/cli/import_products_to_json.php ./data/dane.xlsx'
    );
}

importProducts($inputFile);