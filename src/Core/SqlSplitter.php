<?php

declare(strict_types=1);

namespace Syncdero\Core;

/**
 * Divide un script SQL en sentencias individuales respetando
 * cadenas entre comillas, identificadores entre backticks y comentarios.
 */
final class SqlSplitter
{
    /**
     * @return array<int, string> Lista de sentencias SQL no vacías.
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = ($i + 1 < $length) ? $sql[$i + 1] : '';

            // Comentario de línea: -- ... o # ... (hasta fin de línea)
            if (($char === '-' && $next === '-') || $char === '#') {
                $lineComment = ($char === '#') ? "\n" : "\n";
                $end = strpos($sql, $lineComment, $i);
                $end = ($end === false) ? $length : $end;
                $current .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }

            // Comentario de bloque: /* ... */
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = ($end === false) ? $length : $end + 2;
                // Conservar comentarios de bloque tipo directivas dump (/*!40101 ... */)
                $current .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }

            // Cadenas entre comillas simples o dobles
            if ($char === "'" || $char === '"') {
                $current .= self::readQuoted($sql, $i, $char);
                continue;
            }

            // Identificadores entre backticks
            if ($char === '`') {
                $current .= self::readBackticked($sql, $i);
                continue;
            }

            // Delimitador de sentencia
            if ($char === ';') {
                $statements[] = trim($current);
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        // Descartar sentencias que solo contienen espacios/comentarios/saltos
        return array_values(array_filter(
            $statements,
            static fn (string $stmt): bool => self::hasExecutableContent($stmt)
        ));
    }

    /**
     * Lee una cadena entre comillas (con soporte de escape '' y \" y backslash).
     */
    private static function readQuoted(string $sql, int $start, string $quote): string
    {
        $length = strlen($sql);
        $i = $start + 1;
        $out = $sql[$start];

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === '\\') {
                $out .= $char;
                if ($i + 1 < $length) {
                    $out .= $sql[$i + 1];
                }
                $i += 2;
                continue;
            }

            if ($char === $quote) {
                // Escape por duplicación ('' o "")
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    $out .= $char . $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                $out .= $char;
                return $out;
            }

            $out .= $char;
            $i++;
        }

        return $out;
    }

    /**
     * Lee un identificador entre backticks.
     */
    private static function readBackticked(string $sql, int $start): string
    {
        $length = strlen($sql);
        $i = $start + 1;
        $out = $sql[$start];

        while ($i < $length) {
            $char = $sql[$i];
            $out .= $char;

            if ($char === '`') {
                if ($i + 1 < $length && $sql[$i + 1] === '`') {
                    $out .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                return $out;
            }
            $i++;
        }

        return $out;
    }

    /**
     * Determina si una sentencia tiene contenido ejecutable real
     * (no solo comentarios en línea o bloque, espacios o saltos).
     */
    private static function hasExecutableContent(string $statement): bool
    {
        $stripped = preg_replace('/--[^\n]*|#[^\n]*|\/\*.*?\*\//s', '', $statement);
        return trim((string) $stripped) !== '';
    }
}
