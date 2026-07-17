<?php


namespace App\Services;

class AddressSimplifier
{
    private const PATTERNS = [
        '/\s+строение\s*№?\s*\d+[а-яё]?/ui',
        '/\s+стр\.?\s*№?\s*\d+[а-яё]?/ui',
        '/\s+корпус\s*№?\s*\d+[а-яё]?/ui',
        '/\s+корп\.?\s*№?\s*\d+[а-яё]?/ui',
        '/\s+литера?\s+[а-яё]\b/ui',
        '/\s+здание\s*№?\s*\d+[а-яё]?/ui',
    ];

    public function simplify(string $normalizedAddress): ?string
    {
        $result = $normalizedAddress;

        foreach (self::PATTERNS as $pattern) {
            $result = preg_replace($pattern, '', $result);
        }

        $result = preg_replace('/(\d)-([а-яё])\b/ui', '$1$2', $result);

        $result = trim(preg_replace('/\s+/u', ' ', $result), " ,\t\n\r\0\x0B");

        return $result !== $normalizedAddress ? $result : null;
    }
}
