<?php

namespace App\Enums;

enum EndpointStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Disabled = 'disabled';
}
