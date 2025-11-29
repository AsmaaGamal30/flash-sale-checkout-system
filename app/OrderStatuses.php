<?php

namespace App;

enum OrderStatuses: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case CANCELLED = 'cancelled';

}