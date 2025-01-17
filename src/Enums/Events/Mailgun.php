<?php

namespace Vormkracht10\Mails\Enums\Events;

enum Mailgun: string
{
    case CLICKED = 'clicked';
    case COMPLAINED = 'complained';
    case DELIVERED = 'delivered';
    case HARD_BOUNCED = 'permanent_fail';
    case OPENED = 'opened';
    case SOFT_BOUNCED = 'temporary_fail';
    case UNSUBSCRIBED = 'unsubscribed';
}
