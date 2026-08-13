<?php

namespace LaravelMonitor\Support;

/**
 * Every value the `monitor_entries.type` column can hold — the single
 * source of truth for what each Recorder passes to Monitor::record(),
 * so a typo in a type string can't silently create a type the rest of
 * the dashboard (Storage queries, tab routing, timeline grouping) never
 * looks for. Monitor::record() itself is type-hinted against this enum
 * and only converts to the underlying string (`->value`) right before
 * building the Entry that gets persisted — every Recorder call site
 * passes a case directly, never a raw string. Everything downstream of
 * that (Entry, Storage, Livewire cards, controllers) still deals in
 * plain strings, since that value flows through far too many read-side
 * call sites to type-hint them all against this enum too.
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
