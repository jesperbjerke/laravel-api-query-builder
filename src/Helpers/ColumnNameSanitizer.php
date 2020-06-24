<?php

namespace Bjerke\ApiQueryBuilder\Helpers;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ColumnNameSanitizer
{
    /**
     * Based on maximum column name length.
     */
    public const MAX_COLUMN_NAME_LENGTH = 64;

    /**
     * Column names are alphanumeric strings that can contain
     * underscores (`_`) but can't start with a number.
     */
    private const VALID_COLUMN_NAME_REGEX = '/^(?!\d)[A-Za-z0-9_-]*$/';

    public static function sanitize(string $column): string
    {
        // Allow nested column selections like `users.name`
        $columnParts = explode('.', $column);

        foreach ($columnParts as $columnPart) {
            // Allow json column selections like `data->property`
            $subParts = explode('->', $columnPart);
            foreach ($subParts as $subColumn) {
                self::validateColumn($subColumn);
            }
        }

        return $column;
    }

    public static function sanitizeArray(array $columns): array
    {
        return array_map([self::class, 'sanitize'], $columns);
    }

    private static function validateColumn($column)
    {
        if (strlen($column) > self::MAX_COLUMN_NAME_LENGTH) {
            $maxLength = self::MAX_COLUMN_NAME_LENGTH;
            throw new HttpException(
                400,
                "Given column name `{$column}` exceeds the maximum column name length of {$maxLength} characters."
            );
        }
        if (!preg_match(self::VALID_COLUMN_NAME_REGEX, $column)) {
            throw new HttpException(
                400,
                "Given column name `{$column}` may contain only alphanumerics or underscores, and may not begin with a digit."
            );
        }
    }
}
