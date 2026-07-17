<?php

namespace App\Services;

class AddressNormalizer
{
    /**
     * Сокращения без точки → полное слово.
     * Порядок важен — составные (пр-т, пр-кт) должны идти раньше простых (пр),
     * иначе пр сработает внутри пр-т раньше чем пр-т как целое.
     */
    private const ABBREVIATIONS = [
        'просп'  => 'проспект',
        'пр-кт'  => 'проспект',
        'пр-т'   => 'проспект',
        'пр-д'   => 'проезд',
        'пр'     => 'проспект',
        'ул'     => 'улица',
        'пер'    => 'переулок',
        'б-р'    => 'бульвар',
        'бул'    => 'бульвар',
        'наб'    => 'набережная',
        'пл'     => 'площадь',
        'ш'      => 'шоссе',
        'мкр-н'  => 'микрорайон',
        'мкр'    => 'микрорайон',
        'туп'    => 'тупик',
    ];

    /**
     * Полные родовые слова, наличие любого из которых означает,
     * что тип улицы в адресе уже указан явно.
     */
    private const STREET_TYPE_WORDS = [
        'улица', 'проспект', 'переулок', 'бульвар', 'набережная',
        'площадь', 'шоссе', 'микрорайон', 'тупик', 'проезд',
    ];

    public function normalize(string $rawAddress): string
    {
        $address = trim($rawAddress);
        $address = mb_strtolower($address, 'UTF-8');
        $address = preg_replace('/\s+/u', ' ', $address);
        $address = preg_replace('/,(?!\s)/u', ', ', $address);

        foreach (self::ABBREVIATIONS as $abbr => $full) {
            // lookahead (?![а-яёa-zA-Z]) запрещает замену если после сокращения идёт буква —
            // это не даёт пр сработать внутри прушинской, пер внутри перенсона и т.д.
            $address = preg_replace(
                '/(?<![а-яёa-zA-Z])' . preg_quote($abbr, '/') . '(?![а-яёa-zA-Z])\.?\s*/ui',
                $full . ' ',
                $address
            );
        }

        if (! preg_match('/\bкрасноярск\b/u', $address)) {
            $address = 'красноярск, ' . $address;
        }

        // ИЗВЕСТНОЕ ОГРАНИЧЕНИЕ (задокументировано в дипломе, глава про причины отказов):
        // если в исходном адресе нет ни одного родового слова вообще
        // (например, CSV содержит "Лиды Прушинской, 2" без "ул."),
        // добавляем "улица" по умолчанию как наиболее частый случай.
        // Это эвристика: если реальный тип — "проспект"/"переулок"/др.,
        // она даст неверный результат. Правильное решение требует справочника
        // типов улиц города (вне рамок текущей реализации).
        if (! $this->hasStreetTypeWord($address)) {
            $address = preg_replace('/^(красноярск,\s*)/u', '$1улица ', $address);
        }

        $address = preg_replace('/\s+,/u', ',', $address);
        $address = preg_replace('/\s+/u', ' ', $address);
        $address = trim($address, " ,\t\n\r\0\x0B");

        return $address;
    }

    private function hasStreetTypeWord(string $address): bool
    {
        foreach (self::STREET_TYPE_WORDS as $word) {
            if (mb_strpos($address, $word) !== false) {
                return true;
            }
        }

        return false;
    }
}
