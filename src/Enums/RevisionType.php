<?php

namespace TestMonitor\Revisable\Enums;

enum RevisionType: string
{
    case Default = 'default';
    case Initial = 'initial';
    case Rollback = 'rollback';
}
