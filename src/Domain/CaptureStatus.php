<?php

namespace FaultNest\Domain;

enum CaptureStatus: string
{
    case Captured = 'captured';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
