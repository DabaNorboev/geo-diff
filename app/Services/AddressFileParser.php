<?php


namespace App\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class AddressFileParser
{
    public const SUPPORTED_EXTENSIONS = ['csv', 'txt', 'json'];

    /**
     * @return string[] список сырых адресов (без пустых строк)
     * @throws InvalidArgumentException если формат не поддержан или файл невалиден
     */
    public function parse(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return match ($extension) {
            'csv' => $this->parseCsv($file),
            'txt' => $this->parseTxt($file),
            'json' => $this->parseJson($file),
            default => throw new InvalidArgumentException(
                "Неподдерживаемый формат файла: .{$extension}. Поддерживаются: " . implode(', ', self::SUPPORTED_EXTENSIONS)
            ),
        };
    }

    /**
     * Формат: таблица с заголовком, обязательна колонка "address".
     */
    private function parseCsv(UploadedFile $file): array
    {
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        $header = array_shift($rows);

        $addressColumnIndex = array_search(
            'address',
            array_map(fn($col) => strtolower(trim($col)), $header ?? [])
        );

        if ($addressColumnIndex === false) {
            throw new InvalidArgumentException(
                'В CSV не найдена колонка "address". Первая строка файла должна быть заголовком.'
            );
        }

        return array_values(array_filter(array_map(
            fn($row) => trim($row[$addressColumnIndex] ?? ''),
            $rows
        )));
    }

    /**
     * Формат: один адрес на строку, без заголовка.
     */
    private function parseTxt(UploadedFile $file): array
    {
        $lines = file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new InvalidArgumentException('Не удалось прочитать TXT-файл.');
        }

        return array_values(array_filter(array_map('trim', $lines)));
    }

    /**
     * Формат: JSON-массив строк ["адрес 1", "адрес 2"]
     * или массив объектов [{"address": "адрес 1"}, {"address": "адрес 2"}].
     * Оба варианта можно свободно смешивать в одном массиве.
     */
    private function parseJson(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Некорректный JSON: ' . json_last_error_msg());
        }

        if (!is_array($data)) {
            throw new InvalidArgumentException('JSON должен содержать массив адресов (строк или объектов с полем "address").');
        }

        $addresses = array_map(function ($item) {
            if (is_string($item)) {
                return trim($item);
            }
            if (is_array($item) && isset($item['address']) && is_string($item['address'])) {
                return trim($item['address']);
            }
            return null;
        }, $data);

        return array_values(array_filter($addresses, fn($a) => !empty($a)));
    }
}
