<?php

namespace LaravelMonitor;

enum ExecutionStage: string
{
    case Bootstrap = 'bootstrap';
    case BeforeMiddleware = 'middleware';
    case Action = 'action';
    case Render = 'render';
    case AfterMiddleware = 'unwinding';
    case Sending = 'sending';
    case Terminating = 'terminating';
    case End = 'end';
}
