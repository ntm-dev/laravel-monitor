# Blade/view conventions

- UI style: dotted-line `dl` metric rows
  (see `resources/views/livewire/query-detail.blade.php`, `exception-detail.blade.php`),
  Tailwind CDN JIT, dark mode via `.dark` class.
- Route-list "key" format for `type: 'request'` entries is `"METHOD URI"` (e.g. `"GET /api/foo"`);
  `Livewire\Requests::presentRoute()` splits it into `method`/`path` for the view. Requests with no matched Laravel route are each stored under
  `"{METHOD} " . Requests::UNMATCHED_ROUTE` (e.g. `"GET Unmatched Route"`), but
  `DatabaseStorage::routeStats()` merges every method into a single row keyed by the bare
  `Requests::UNMATCHED_ROUTE` sentinel (method column shows the methods joined by `/`, or "ANY"
  above `Livewire\Requests::MAX_UNMATCHED_METHODS_SHOWN`). `DatabaseStorage::query()`/`resolveKeyHash()`
  know how to expand that sentinel back into every method variant, so the merged row's detail-page
  link still works.
- **New Blade blocks get a start/end comment pair**, e.g.:
  ```blade
  {{-- start card list query --}}
  <div class="list-query-card">...</div>
  {{-- end card list query --}}
  ```
  Applies when adding a new block (a card, a section, a loop); editing an existing
  uncommented block doesn't require retrofitting one.

- HTTP method badge color (route list, a route's request list, anywhere else a method shows up)
  comes from `Support\Format::httpMethodClass()` — a single shared mapping, not a `match()`
  copy-pasted per Blade file. It also colors the merged Unmatched Route row's "ANY"/joined-method
  label (see the route-list key format note above) a distinct violet, since that isn't a single verb.

## Gotcha

**`<pre><code>...</code></pre>` must have zero whitespace/newline between the tags.**
`pre` preserves whitespace literally — any indentation before `<code>` renders as a leading
blank line, pushing SQL/text output sideways. Bit us once in `timeline.blade.php`.

**Three or more consecutive inline-php Blade blocks in the same spot silently corrupt.**
Two php blocks back-to-back (`@` + `php(...)` twice, or one of those plus a `@` + `php`/`@endphp`
pair) compile fine, but a *third* one right after breaks Blade's raw-PHP extraction: the 1st and
2nd blocks' code gets swallowed as uncompiled literal text inside the 3rd block's `<?php ... ?>`,
so their variables never actually get assigned at runtime — no compile error, just a runtime
"Undefined variable" (or worse, no error at all if the swallowed code was inert) the first time
something downstream reads them. Diagnose by clearing the compiled view cache and grepping the
regenerated `storage/framework/views/*.php` for the variable — if its assignment line still shows
literal `@` + `php(...)` text instead of having become `<?php ... ?>`, this is why. **Fix: merge
every inline-php block for one loop iteration/row into a single `@` + `php ... @endphp` block**
instead of stacking several. Bit us once in `request-detail.blade.php` (a 3rd block added next to
two pre-existing ones broke `$detailUrl`, 500ing the whole per-route request list).

**Never spell a Blade directive name (`@` immediately followed by a directive word, e.g. `@php`)
inside a `{{-- ... --}}` comment.** Blade's directive compiler runs over the raw source before —
or without full awareness of — comment stripping, so a directive-shaped token inside a comment
gets treated as a real directive; the `{{--` that follows then stops being recognized as a
comment-open and gets compiled as a literal `{{ }}` echo of the rest of the comment text instead,
producing garbled output (seen once as `<?php echo e(-- ...` swallowing everything up to the next
`{{`/`}}` pair, corrupting an unrelated line further down). Describe the directive in comments
without the leading `@` (e.g. "php block", "foreach directive"), or avoid mentioning it at all.
Same failure mode for a literal `--}}` written out inside a `{{-- --}}` comment's own body (e.g.
a comment explaining Blade's comment syntax itself) — Blade just does a substring search for the
next `--}}` to find the end, so that inner occurrence closes the comment early and dumps the rest
of the intended comment text onto the page as visible content.

**A `//`/`/* */` JS comment inside an HTML attribute value (`x-data="{ ... }"`, any other
Alpine `x-`/`:`-bound attribute) is one stray `"` or `@word` away from corrupting the page.**
The attribute is still HTML-parsed even though its content is JS: a literal double-quote ends
the attribute early (dumping the rest of the JS as visible page text), and a bare `@word` risks
the directive-in-comment failure above since Blade's directive scan doesn't know it's inside JS.
Bit us in `header.blade.php`'s custom-range `x-data` — twice, across two separate incidents.
**Prefer `{{-- ... --}}` over `//` for explanatory comments living inside such an attribute** —
Blade comments are deleted wholesale at compile time, so nothing in their body (quotes, `@word`,
just not a literal `--}}`, see above) can reach the browser at all. A comment inside a `<script>`
tag is NOT at risk this way — script content isn't attribute-value-parsed — so this only applies
to comments written inside an HTML attribute's own quoted string.
