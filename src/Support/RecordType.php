<?php

namespace LaravelMonitor\Support;

/**
 * Every value `monitor_entries.type` can hold. Monitor::record() takes a case;
 * everything downstream of it (Entry, Storage, Livewire cards) uses the plain string.
 */
enum RecordType: string
{
    case Auth = 'auth';
    case Cache = 'cache';
    case Command = 'command';
    case Exception = 'exception';
    case Job = 'job';
    case LazyLoading = 'lazy_loading';
    case Log = 'log';
    case Mail = 'mail';
    case Notification = 'notification';
    case OutgoingRequest = 'outgoing_request';
    case Query = 'query';
    case Request = 'request';
    case ScheduledTask = 'scheduled_task';
}
