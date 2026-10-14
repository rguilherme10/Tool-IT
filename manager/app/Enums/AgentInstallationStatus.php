<?php

namespace App\Enums;

enum AgentInstallationStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
