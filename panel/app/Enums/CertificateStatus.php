<?php

namespace App\Enums;

enum CertificateStatus: string
{
    /** The domain does not point at this server yet; retried hourly. */
    case WaitingDns = 'waiting_dns';
    case Issuing = 'issuing';
    case Active = 'active';
    case Failed = 'failed';
}
