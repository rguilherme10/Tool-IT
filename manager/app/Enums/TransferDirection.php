<?php

namespace App\Enums;

enum TransferDirection: string
{
    /** Operador -> endpoint (tarefa file.send). */
    case Send = 'send';
    /** Endpoint -> operador (tarefa file.fetch). */
    case Fetch = 'fetch';

    public function label(): string
    {
        return match ($this) {
            self::Send => 'Envio',
            self::Fetch => 'Recuperação',
        };
    }

    public function taskType(): string
    {
        return 'file.'.$this->value;
    }

    public function permission(): Permission
    {
        return match ($this) {
            self::Send => Permission::FilesTransferSend,
            self::Fetch => Permission::FilesTransferFetch,
        };
    }
}
