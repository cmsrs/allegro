<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

const CSV_COLUMNS = [
    'GTIN' => ['gtin', 'ean'],
    'EXTERNAL_ID' => ['external_id', 'externalid', 'id_zewnetrzne', 'id_w_sklepie', 'sku'],
    'NAME' => ['name', 'nazwa'],
    'STOCK' => ['stock', 'stan', 'stan_magazynowy', 'ilosc', 'quantity'],
    'PRICE' => ['price', 'cena'],
];

const IMAGE_COLUMN_COUNT = 16;

const MAX_CSV_SIZE = 50 * 1024 * 1024;

function fail(string $message): never
{
    fwrite(STDERR, "[ERROR] {$message}" . PHP_EOL);
    exit(1);
}

function normalizeHeader(string $header): string
{
    $header = mb_strtolower(trim($header), 'UTF-8');
    $header = strtr($header, [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l',
        'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
    ]);

    return trim((string) preg_replace('/[^a-z0-9]+/', '_', $header), '_');
}

/** Zbiera adresy z listy zdjęć i elementów IMAGE w opisie produktu. */
function productImageUrls(array $product): array
{
    $urls = [];
    foreach ($product['opis']['sections'] ?? [] as $section) {
        foreach ($section['items'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'IMAGE') {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $url)) {
                $urls[] = $url;
            }
        }
    }

    // Prefer links already embedded in the offer description, then try original photo URLs.
    foreach ($product['zdjecia'] ?? [] as $image) {
        if (!is_array($image)) {
            continue;
        }

        $url = trim((string) ($image['sourceUrl'] ?? ''));
        if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $url)) {
            $urls[] = $url;
        }
    }

    return array_slice(array_values(array_unique($urls)), 0, 4);
}

/** Sprawdza adresy równolegle i zwraca te, które odpowiadają obrazem HTTP 2xx. */
function findWorkingImageUrls(array $urls): array
{
    if ($urls === []) {
        return [];
    }
    if (!function_exists('curl_multi_init')) {
        fail('Do sprawdzania linków do zdjęć wymagane jest rozszerzenie PHP cURL.');
    }

    $working = [];
    foreach (array_chunk(array_values(array_unique($urls)), 96) as $batch) {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($batch as $url) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                // Część serwerów zwraca 404 na HEAD, mimo że obraz działa przez GET.
                CURLOPT_RANGE => '0-0',
                CURLOPT_WRITEFUNCTION => static fn ($handle, string $data): int => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_USERAGENT => 'AllegroProductCsvImageCheck/1.0',
            ]);
            curl_multi_add_handle($multi, $curl);
            $handles[] = [$url, $curl];
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0 && $status === CURLM_OK) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as [$url, $curl]) {
            $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
            if ($httpStatus >= 200 && $httpStatus < 300 && str_starts_with(strtolower($contentType), 'image/')) {
                $working[$url] = true;
            }
            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
        }

        curl_multi_close($multi);
    }

    return $working;
}

/**
 * Eksportuje podstawowe dane produktów z pierwszego arkusza do CSV Allegro.
 */
function importProductsToCsv(string $inputFile, string $outputFile): void
{
    if (!is_file($inputFile) || !is_readable($inputFile)) {
        fail("Nie można odczytać pliku wejściowego: {$inputFile}");
    }

    try {
        $spreadsheet = IOFactory::load($inputFile);
    } catch (Throwable $e) {
        fail('Nie można odczytać pliku Excel: ' . $e->getMessage());
    }

    $sheet = $spreadsheet->getActiveSheet();
    $highestRow = $sheet->getHighestDataRow();
    $highestColumnIndex = $sheet->getHighestDataColumn();
    $highestColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumnIndex);

    $sourceColumns = [];
    for ($column = 1; $column <= $highestColumn; $column++) {
        $header = normalizeHeader((string) $sheet->getCell([$column, 1])->getFormattedValue());
        if ($header !== '') {
            $sourceColumns[$header] = $column;
        }
    }

    $columnMap = [];
    foreach (CSV_COLUMNS as $csvName => $aliases) {
        foreach ($aliases as $alias) {
            if (isset($sourceColumns[$alias])) {
                $columnMap[$csvName] = $sourceColumns[$alias];
                break;
            }
        }
    }

    if (!isset($columnMap['GTIN'])) {
        fail('Brak wymaganej kolumny GTIN (akceptowane są też nagłówki EAN).');
    }

    $categoryColumn = $sourceColumns['kategoria'] ?? $sourceColumns['category'] ?? null;
    if ($categoryColumn === null) {
        fail('Brak wymaganej kolumny Kategoria/CATEGORY.');
    }

    $directory = dirname($outputFile);
    if (!is_dir($directory) || !is_writable($directory)) {
        fail("Katalog docelowy nie istnieje lub nie można w nim zapisywać: {$directory}");
    }

    $jsonFile = $GLOBALS['imageJsonFile'];
    if (!is_file($jsonFile) || !is_readable($jsonFile)) {
        fail("Nie można odczytać źródła zdjęć JSON: {$jsonFile}");
    }
    $jsonData = json_decode((string) file_get_contents($jsonFile), true);
    if (!is_array($jsonData) || !is_array($jsonData['products'] ?? null)) {
        fail("Nieprawidłowa struktura pliku JSON: {$jsonFile}");
    }

    $imagesByGtin = [];
    $allImageUrls = [];
    foreach ($jsonData['products'] as $product) {
        $gtin = trim((string) ($product['ean'] ?? $product['gtin'] ?? ''));
        if ($gtin === '') {
            continue;
        }
        $urls = productImageUrls($product);
        $imagesByGtin[$gtin] = $urls;
        array_push($allImageUrls, ...$urls);
    }

    echo 'Sprawdzam linki do zdjęć (' . count(array_unique($allImageUrls)) . ')...' . PHP_EOL;
    $workingImageUrls = findWorkingImageUrls($allImageUrls);

    $tempFile = $outputFile . '.tmp';
    $handle = fopen($tempFile, 'wb');
    if ($handle === false) {
        fail("Nie można utworzyć pliku tymczasowego: {$tempFile}");
    }

    $writeRow = static function ($handle, array $values): bool {
        return fputcsv($handle, $values, ',', '"', '', "\r\n") !== false;
    };

    $csvHeaders = array_merge(
        array_keys(CSV_COLUMNS),
        array_map(static fn (int $number): string => 'IMAGE' . $number, range(1, IMAGE_COLUMN_COUNT)),
        ['CATEGORY']
    );
    if (!$writeRow($handle, $csvHeaders)) {
        fclose($handle);
        @unlink($tempFile);
        fail('Nie udało się zapisać nagłówka CSV.');
    }

    $writtenRows = 0;
    $skippedRows = [];
    for ($row = 2; $row <= $highestRow; $row++) {
        $values = [];
        $hasData = false;

        foreach (CSV_COLUMNS as $csvName => $_aliases) {
            $value = '';
            if (isset($columnMap[$csvName])) {
                $cell = $sheet->getCell([$columnMap[$csvName], $row]);
                $rawValue = $cell->isFormula()
                    ? $cell->getCalculatedValue()
                    : $cell->getValue();

                if ($rawValue !== null && $rawValue !== '' && is_numeric($rawValue)) {
                    if ($csvName === 'GTIN') {
                        // GTIN jest identyfikatorem: zapisujemy cyfry bez notacji wykładniczej.
                        $value = sprintf('%.0f', (float) $rawValue);
                    } elseif ($csvName === 'STOCK') {
                        $value = (string) (int) $rawValue;
                    } elseif ($csvName === 'PRICE') {
                        $value = rtrim(rtrim(number_format((float) $rawValue, 8, '.', ''), '0'), '.');
                    } else {
                        $value = trim((string) $cell->getFormattedValue());
                    }
                } else {
                    $value = trim((string) $cell->getFormattedValue());
                }
            }

            if ($value !== '') {
                $hasData = true;
            }

            $values[] = $value;
        }

        $gtin = $values[0];
        $productImages = array_values(array_filter(
            $imagesByGtin[$gtin] ?? [],
            static fn (string $url): bool => isset($workingImageUrls[$url])
        ));
        foreach (range(0, IMAGE_COLUMN_COUNT - 1) as $index) {
            $values[] = $productImages[$index] ?? '';
        }

        $category = trim((string) $sheet->getCell([$categoryColumn, $row])->getFormattedValue());
        if ($category === '') {
            fclose($handle);
            @unlink($tempFile);
            fail("Brak kategorii w wierszu {$row}; plik wynikowy nie został zapisany.");
        }
        $values[] = $category;

        if (!$hasData) {
            continue;
        }

        if ($values[0] === '') {
            $skippedRows[] = $row;
            continue;
        }

        if (!$writeRow($handle, $values)) {
            fclose($handle);
            @unlink($tempFile);
            fail("Błąd zapisu wiersza {$row} do CSV.");
        }

        $writtenRows++;
        $position = ftell($handle);
        if ($position !== false && $position > MAX_CSV_SIZE) {
            fclose($handle);
            @unlink($tempFile);
            fail('Wynikowy CSV przekracza maksymalny rozmiar 50 MB.');
        }
    }

    fclose($handle);

    if (filesize($tempFile) > MAX_CSV_SIZE) {
        @unlink($tempFile);
        fail('Wynikowy CSV przekracza maksymalny rozmiar 50 MB.');
    }

    if (!rename($tempFile, $outputFile)) {
        @unlink($tempFile);
        fail("Nie można zapisać pliku wynikowego: {$outputFile}");
    }

    echo "Źródło: {$inputFile}" . PHP_EOL;
    echo "Wynik: {$outputFile}" . PHP_EOL;
    echo "Produktów: {$writtenRows}" . PHP_EOL;
    echo 'Działających linków do zdjęć: ' . count($workingImageUrls) . PHP_EOL;
    if ($skippedRows !== []) {
        echo 'Pominięto wiersze bez GTIN: ' . implode(', ', $skippedRows) . PHP_EOL;
    }
}

if (PHP_SAPI !== 'cli') {
    fail('Skrypt można uruchomić wyłącznie z CLI.');
}

$projectRoot = dirname(__DIR__, 2);
$inputFile = $argv[1] ?? $projectRoot . '/data/dane.xlsx';
$outputFile = $argv[2] ?? $projectRoot . '/data/dane.csv';
$imageJsonFile = $argv[3] ?? $projectRoot . '/data/dane.json';
$GLOBALS['imageJsonFile'] = $imageJsonFile;

importProductsToCsv($inputFile, $outputFile);
