{{-- Shared toast/alert stack, mounted once in layout.blade.php so any page
     can trigger one — top-right corner, newest on top, capped at 3 visible
     (oldest gets pushed out), each auto-dismisses after 10s unless closed
     manually first.

     Two ways to trigger one:
     - From a Livewire component: $this->dispatch('toast', level: 'success',
       message: '...') — Livewire broadcasts that as a window CustomEvent,
       caught by the x-on:toast.window listener below.
     - From a plain POST+redirect controller (no Livewire involved, e.g.
       SettingsController): session()->flash('monitor.toast', ['level' =>
       ..., 'message' => ...]) before redirecting — read directly in
       x-init below, on the page that redirect lands on.

     $level is one of: primary, secondary, success, danger, warning, info —
     each maps to its own solid (not translucent — a tinted-but-opaque
     background, never a low-alpha one) colour pairing below, so a new call
     site just picks the level that fits and inherits the right look for
     free.

     Each toast animates its own height (grid-template-rows 0fr → 1fr, not
     just opacity) so entering/leaving is a genuine layout change — the
     row growing in visibly pushes older toasts down underneath it, and
     shrinking back to 0 on removal reads as that toast collapsing away
     rather than just vanishing in place. A toast is only ever removed from
     the `toasts` array *after* its own leave animation finishes (see
     remove()), instead of instantly, so x-for never has to cut a
     mid-animation element short. --}}
@php $flashed = session('monitor.toast'); @endphp
<div x-data="{
        toasts: [],
        seq: 0,
        push(level, message) {
            const id = ++this.seq;
            this.toasts.push({ id, level, message, show: false });
            const overflowing = this.toasts.filter((t) => t.id !== id);
            if (overflowing.length >= 3) this.remove(overflowing[0].id);
            setTimeout(() => this.remove(id), 10000);
        },
        remove(id) {
            const toast = this.toasts.find((t) => t.id === id);
            if (! toast) return;
            toast.show = false;
            setTimeout(() => { this.toasts = this.toasts.filter((t) => t.id !== id); }, 300);
        },
     }"
     x-on:toast.window="push($event.detail.level, $event.detail.message)"
     @if ($flashed) x-init="push(@js($flashed['level']), @js($flashed['message']))" @endif
     class="pointer-events-none fixed right-4 top-4 z-50 flex w-80 max-w-[calc(100vw-2rem)] flex-col-reverse">
    <template x-for="toast in toasts" :key="toast.id">
        <div x-init="$nextTick(() => toast.show = true)"
             class="grid overflow-hidden transition-[grid-template-rows] duration-300 ease-in-out"
             :style="toast.show ? 'grid-template-rows: 1fr' : 'grid-template-rows: 0fr'">
            <div class="min-h-0">
                <div class="pointer-events-auto mt-2 flex items-start gap-2 rounded-lg border px-3 py-2.5 text-sm shadow-lg transition-all duration-300 ease-in-out"
                     :class="{
                        'translate-y-0 scale-100 opacity-100': toast.show,
                        '-translate-y-1 scale-95 opacity-0': ! toast.show,
                        'border-blue-300 bg-blue-100 text-blue-800 dark:border-blue-600 dark:bg-blue-800 dark:text-blue-100': toast.level === 'primary',
                        'border-neutral-300 bg-neutral-200 text-neutral-800 dark:border-neutral-500 dark:bg-neutral-700 dark:text-neutral-100': toast.level === 'secondary',
                        'border-green-300 bg-green-100 text-green-800 dark:border-green-600 dark:bg-green-800 dark:text-green-100': toast.level === 'success',
                        'border-red-500 bg-red-100 text-red-800 dark:border-red-500 dark:bg-red-800 dark:text-red-100': toast.level === 'danger',
                        'border-amber-300 bg-amber-100 text-amber-800 dark:border-amber-600 dark:bg-amber-800 dark:text-amber-100': toast.level === 'warning',
                        'border-cyan-400 bg-cyan-100 text-cyan-800 dark:border-cyan-400 dark:bg-cyan-800 dark:text-cyan-100': toast.level === 'info',
                     }">
                    <span class="min-w-0 flex-1 break-words" x-text="toast.message"></span>
                    <button type="button" @click="remove(toast.id)" class="shrink-0 opacity-60 hover:opacity-100">
                        <x-monitor::icon :path="\LaravelMonitor\Support\Icons::CLOSE" :stroke="2" class="h-3.5 w-3.5"/>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
