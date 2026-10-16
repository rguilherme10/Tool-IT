<?php

namespace App\Support\Transfers;

/** Apresentação das transferências no painel (só formatação; nenhuma regra de negócio). */
final class TransferDisplay
{
    /** Tamanho legível em base 1024 ("1,5 KiB", "100 MiB"). */
    public static function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KiB', 'MiB', 'GiB', 'TiB'];
        $value = $bytes / 1024;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        // Uma casa decimal, sem ",0" sobrando.
        return preg_replace('/,0$/', '', number_format($value, 1, ',', '.')).' '.$units[$i];
    }

    /** Início do SHA-256 para a tabela; o valor completo vai no atributo title. */
    public static function shortHash(?string $sha256): string
    {
        return $sha256 === null || $sha256 === '' ? '' : substr($sha256, 0, 12).'…';
    }
}
