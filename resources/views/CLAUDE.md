# Blade/view conventions

- UI style: dotted-line `dl` metric rows
  (see `resources/views/livewire/query-detail.blade.php`, `exception-detail.blade.php`),
  Tailwind CDN JIT, dark mode via `.dark` class.
- Route-list "key" format for `type: 'request'` entries is `"METHOD URI"` (e.g. `"GET /api/foo"`);
  list views split it back apart with `Str::before`/`Str::after`. Requests with no matched
  Laravel route are grouped under the literal key `Requests::UNMATCHED_ROUTE` ("Unmatched Route").
- **New Blade blocks get a start/end comment pair**, e.g.:
  ```blade
  {{-- start card list query --}}
  <div class="list-query-card">...</div>
  {{-- end card list query --}}
  ```
  Applies when adding a new block (a card, a section, a loop); editing an existing
  uncommented block doesn't require retrofitting one.

## Gotcha

**`<pre><code>...</code></pre>` must have zero whitespace/newline between the tags.**
`pre` preserves whitespace literally — any indentation before `<code>` renders as a leading
blank line, pushing SQL/text output sideways. Bit us once in `timeline.blade.php`.
