<?php

namespace App\Exceptions;

/**
 * Erro de regra de uma transferência de arquivo, com código e status HTTP estáveis para a API do Agent e a web:
 *  - transfer.conflict (409): estado/direção não permite a operação, chunk duplicado com conteúdo diferente,
 *    política que deixou de permitir a transferência;
 *  - transfer.invalid (422): índice/tamanho de chunk fora do esperado;
 *  - transfer.too_large (422): tamanho acima do max_bytes;
 *  - transfer.chunk_hash (422): X-Chunk-Sha256 não confere.
 * Estende DomainException: quem já trata DomainException como "mensagem para o usuário" continua funcionando.
 */
class TransferException extends \DomainException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 'transfer.conflict', 409);
    }

    public static function invalid(string $message): self
    {
        return new self($message, 'transfer.invalid', 422);
    }

    public static function tooLarge(): self
    {
        return new self('O arquivo é maior que o limite da política de transferência.', 'transfer.too_large', 422);
    }

    public static function chunkHash(): self
    {
        return new self('O SHA-256 do chunk não confere.', 'transfer.chunk_hash', 422);
    }
}
