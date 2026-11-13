{{-- Glass theme: surface tokens, the aurora backdrop and the monitor-glass* surface classes.
     Classes sit in the components layer so Tailwind hover/state utilities still override them. --}}
<style type="text/tailwindcss">
    :root {
        --monitor-glass-bg: rgb(255 255 255 / .22);
        --monitor-glass-strong-bg: rgb(255 255 255 / .72);
        --monitor-glass-control-bg: rgb(255 255 255 / .28);
        --monitor-glass-pill-bg: rgb(255 255 255 / .55);
        --monitor-glass-border: rgb(23 23 23 / .08);
        --monitor-glass-highlight: rgb(255 255 255 / .9);
        --monitor-glass-shadow: 0 10px 30px -12px rgb(30 41 59 / .28), 0 0 0 1px rgb(255 255 255 / .25);
        --monitor-glass-liquid-bg: rgb(255 255 255 / .14);
        --monitor-glass-rim: linear-gradient(145deg, rgb(255 255 255 / .95), rgb(255 255 255 / .2) 38%, rgb(255 255 255 / .1) 62%, rgb(255 255 255 / .8));
        --monitor-glass-glow: inset 1.5px 1.5px 1px -.5px rgb(255 255 255 / .95), inset -1px -1px 1px -.5px rgb(255 255 255 / .65), inset 0 -14px 24px -18px rgb(15 23 42 / .14), inset 0 0 6px rgb(255 255 255 / .25);
        --monitor-glass-glare: linear-gradient(165deg, rgb(255 255 255 / .5), rgb(255 255 255 / 0) 30%, rgb(255 255 255 / 0) 78%, rgb(255 255 255 / .16));
        --monitor-glass-liquid-glare: linear-gradient(180deg, rgb(255 255 255 / .3), rgb(255 255 255 / 0) 26px, rgb(255 255 255 / 0) calc(100% - 18px), rgb(255 255 255 / .12));
        --monitor-fill: rgb(15 23 42 / .045);
        --monitor-aurora-1: #38bdf8;
        --monitor-aurora-2: #a78bfa;
        --monitor-aurora-3: #34d399;
        --monitor-aurora-4: #fb7185;
        --monitor-aurora-opacity: .8;
    }
    {{-- color-scheme keeps native select popups and scrollbars dark behind the translucent controls. --}}
    .dark {
        color-scheme: dark;
        --monitor-glass-bg: rgb(255 255 255 / .04);
        --monitor-glass-strong-bg: rgb(23 23 23 / .78);
        --monitor-glass-control-bg: rgb(255 255 255 / .04);
        --monitor-glass-pill-bg: rgb(255 255 255 / .12);
        --monitor-glass-border: rgb(255 255 255 / .09);
        --monitor-glass-highlight: rgb(255 255 255 / .07);
        --monitor-glass-shadow: 0 18px 40px -18px rgb(0 0 0 / .7);
        --monitor-glass-liquid-bg: rgb(10 14 24 / .3);
        --monitor-glass-rim: linear-gradient(145deg, rgb(255 255 255 / .3), rgb(255 255 255 / .05) 38%, rgb(255 255 255 / .03) 62%, rgb(255 255 255 / .16));
        --monitor-glass-glow: inset 1.5px 1.5px 1px -.5px rgb(255 255 255 / .32), inset -1px -1px 1px -.5px rgb(255 255 255 / .14), inset 0 -14px 24px -18px rgb(0 0 0 / .55), inset 0 0 8px rgb(255 255 255 / .04);
        --monitor-glass-glare: linear-gradient(165deg, rgb(255 255 255 / .12), rgb(255 255 255 / 0) 30%, rgb(255 255 255 / 0) 78%, rgb(255 255 255 / .04));
        --monitor-glass-liquid-glare: linear-gradient(180deg, rgb(255 255 255 / .1), rgb(255 255 255 / 0) 26px, rgb(255 255 255 / 0) calc(100% - 18px), rgb(255 255 255 / .03));
        --monitor-fill: rgb(255 255 255 / .045);
        --monitor-aurora-1: #1e40af;
        --monitor-aurora-2: #6d28d9;
        --monitor-aurora-3: #0f766e;
        --monitor-aurora-4: #9f1239;
        --monitor-aurora-opacity: .5;
    }

    @layer components {
        {{-- Blurred blobs, kept static (no drift animation): the dashboard stays open for hours. --}}
        .monitor-aurora {
            position: fixed;
            inset: 0;
            z-index: -10;
            overflow: hidden;
            pointer-events: none;
        }
        .monitor-aurora i {
            position: absolute;
            width: 46vmax;
            height: 46vmax;
            border-radius: 50%;
            filter: blur(70px);
            opacity: var(--monitor-aurora-opacity);
        }
        .monitor-aurora i:nth-child(1) { background: var(--monitor-aurora-1); top: -14vmax; left: -10vmax; }
        .monitor-aurora i:nth-child(2) { background: var(--monitor-aurora-2); top: 8vmax; right: -16vmax; }
        .monitor-aurora i:nth-child(3) { background: var(--monitor-aurora-3); bottom: -18vmax; left: 18vmax; }
        .monitor-aurora i:nth-child(4) { background: var(--monitor-aurora-4); top: 40vmax; left: -12vmax; width: 30vmax; height: 30vmax; }

        {{-- The border-box gradient is the specular rim; it needs a transparent border. --}}
        .monitor-glass {
            position: relative;
            border-color: transparent;
            background: var(--monitor-glass-glare) padding-box, linear-gradient(var(--monitor-glass-bg), var(--monitor-glass-bg)) padding-box, var(--monitor-glass-rim) border-box;
            box-shadow: var(--monitor-glass-glow), var(--monitor-glass-shadow);
        }

        {{-- Blur lives on a pseudo-element: backdrop-filter on the card itself would become the containing
             block of the fixed tooltips and overlays inside it. z-index -1 keeps it under the card's tint,
             unless the card is its own stacking context. --}}
        .monitor-glass::before {
            content: '';
            position: absolute;
            inset: 0;
            z-index: -1;
            border-radius: inherit;
            pointer-events: none;
            -webkit-backdrop-filter: blur(16px) saturate(150%);
            backdrop-filter: blur(16px) saturate(150%);
        }

        {{-- One glass layer only: stacked glass turns opaque, so a nested surface becomes a tinted well. --}}
        .monitor-glass .monitor-glass {
            border-color: var(--monitor-glass-border);
            background: var(--monitor-fill);
            box-shadow: none;
        }
        .monitor-glass .monitor-glass::before {
            content: none;
        }

        {{-- An isolated card is its own stacking context, so the blur would paint over its tint. --}}
        .monitor-glass.isolate::before {
            content: none;
        }

        {{-- Floating chrome (sidebar, page header). Its glare is px-based so a wide, short header doesn't wash out white. --}}
        .monitor-glass-liquid {
            border-color: transparent;
            background: var(--monitor-glass-liquid-glare) padding-box, linear-gradient(var(--monitor-glass-liquid-bg), var(--monitor-glass-liquid-bg)) padding-box, var(--monitor-glass-rim) border-box;
            box-shadow: var(--monitor-glass-glow), var(--monitor-glass-shadow);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            backdrop-filter: blur(20px) saturate(180%);
        }

        {{-- Edge refraction (Chromium only): --monitor-lens is the per-element SVG filter built below. --}}
        .monitor-glass-liquid.monitor-lens {
            backdrop-filter: var(--monitor-lens) blur(5px) saturate(180%);
        }

        {{-- Popovers, modals and sticky bars that float over scrolling content. --}}
        .monitor-glass-strong {
            background-color: var(--monitor-glass-strong-bg);
            border-color: var(--monitor-glass-border);
            box-shadow: var(--monitor-glass-glow), var(--monitor-glass-shadow);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            backdrop-filter: blur(24px) saturate(180%);
        }

        {{-- Glare goes in background-image so hover:bg-* utilities still recolour the tint underneath. --}}
        .monitor-glass-control {
            background-color: var(--monitor-glass-control-bg);
            background-image: var(--monitor-glass-glare);
            border-color: var(--monitor-glass-border);
            box-shadow: var(--monitor-glass-glow);
        }

        {{-- Selected item of a segmented control or tab strip: a raised droplet. --}}
        .monitor-glass-pill {
            background-color: var(--monitor-glass-pill-bg);
            background-image: var(--monitor-glass-glare);
            border-color: var(--monitor-glass-border);
            box-shadow: 0 6px 16px -6px rgb(15 23 42 / .3), var(--monitor-glass-glow);
        }

        {{-- Springy press feedback on glass controls. --}}
        .monitor-glass-control, .monitor-glass-pill {
            transition: transform .25s cubic-bezier(.3, .7, .4, 1.5), background-color .2s, box-shadow .2s;
        }
        :is(a, button).monitor-glass-control:active, a.monitor-glass-pill:active {
            transform: scale(.96);
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .monitor-glass-control, .monitor-glass-pill { transition: none; }
        :is(a, button).monitor-glass-control:active, a.monitor-glass-pill:active { transform: none; }
    }

    @media (prefers-reduced-transparency: reduce) {
        .monitor-aurora { display: none; }
        .monitor-glass::before { content: none; }
        :root {
            --monitor-glass-bg: rgb(255 255 255);
            --monitor-glass-strong-bg: rgb(255 255 255);
            --monitor-glass-control-bg: rgb(255 255 255);
            --monitor-glass-liquid-bg: rgb(255 255 255);
        }
        .dark {
            --monitor-glass-bg: rgb(23 23 23);
            --monitor-glass-strong-bg: rgb(23 23 23);
            --monitor-glass-control-bg: rgb(23 23 23);
            --monitor-glass-liquid-bg: rgb(23 23 23);
        }
    }
</style>
{{-- start liquid glass lens --}}
{{-- Builds one displacement map per floating glass surface, bending the backdrop along its rounded edge. --}}
<script>
    (() => {
        if (! window.chrome || matchMedia('(prefers-reduced-transparency: reduce)').matches) {
            return;
        }

        var ns = 'http://www.w3.org/2000/svg';
        var defs = null;
        var count = 0;

        // Signed distance to a rounded rect centred in w x h (negative inside).
        function distance(x, y, w, h, r) {
            var qx = Math.abs(x - w / 2) - (w / 2 - r);
            var qy = Math.abs(y - h / 2) - (h / 2 - r);

            return Math.hypot(Math.max(qx, 0), Math.max(qy, 0)) + Math.min(Math.max(qx, qy), 0) - r;
        }

        // R/G encode the x/y shift: neutral inside, pushed along the edge normal within `band` px of it.
        function displacementMap(w, h, r, band) {
            var canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            var context = canvas.getContext('2d');
            var image = context.createImageData(w, h);

            for (var y = 0; y < h; y++) {
                for (var x = 0; x < w; x++) {
                    var i = (y * w + x) * 4;
                    var depth = -distance(x + .5, y + .5, w, h, r);
                    var red = 128;
                    var green = 128;

                    if (depth > 0 && depth < band) {
                        var gx = distance(x + 1.5, y + .5, w, h, r) - distance(x - .5, y + .5, w, h, r);
                        var gy = distance(x + .5, y + 1.5, w, h, r) - distance(x + .5, y - .5, w, h, r);
                        var length = Math.hypot(gx, gy) || 1;
                        var strength = Math.pow(1 - depth / band, 2) * 127;
                        red += gx / length * strength;
                        green += gy / length * strength;
                    }

                    image.data[i] = red;
                    image.data[i + 1] = green;
                    image.data[i + 2] = 128;
                    image.data[i + 3] = 255;
                }
            }

            context.putImageData(image, 0, 0);

            return canvas.toDataURL();
        }

        function build(el) {
            var w = Math.round(el.offsetWidth);
            var h = Math.round(el.offsetHeight);

            if (! w || ! h) {
                return;
            }

            if (! defs) {
                defs = document.createElementNS(ns, 'svg');
                defs.setAttribute('width', '0');
                defs.setAttribute('height', '0');
                defs.setAttribute('aria-hidden', 'true');
                defs.style.position = 'absolute';
                document.body.appendChild(defs);
            }

            el.lensFilter = el.lensFilter || defs.appendChild(document.createElementNS(ns, 'filter'));
            el.lensFilter.id = el.lensFilter.id || 'monitor-lens-' + count++;

            var radius = parseFloat(getComputedStyle(el).borderTopLeftRadius) || 0;
            var attributes = { x: 0, y: 0, width: w, height: h, filterUnits: 'userSpaceOnUse', primitiveUnits: 'userSpaceOnUse', 'color-interpolation-filters': 'sRGB' };

            Object.keys(attributes).forEach(function (name) {
                el.lensFilter.setAttribute(name, attributes[name]);
            });

            // Each colour channel bends by a slightly different amount: the chromatic fringe of a water droplet.
            var channels = [['r', 74, '1 0 0 0 0  0 0 0 0 0  0 0 0 0 0  0 0 0 1 0'], ['g', 64, '0 0 0 0 0  0 1 0 0 0  0 0 0 0 0  0 0 0 1 0'], ['b', 54, '0 0 0 0 0  0 0 0 0 0  0 0 1 0 0  0 0 0 1 0']];

            el.lensFilter.innerHTML = '<feImage href="' + displacementMap(w, h, radius, Math.min(36, h / 2)) + '" x="0" y="0" width="' + w + '" height="' + h + '" preserveAspectRatio="none" result="map"/>'
                + channels.map(function (c) {
                    return '<feDisplacementMap in="SourceGraphic" in2="map" scale="' + c[1] + '" xChannelSelector="R" yChannelSelector="G" result="d' + c[0] + '"/>'
                        + '<feColorMatrix in="d' + c[0] + '" type="matrix" values="' + c[2] + '" result="' + c[0] + '"/>';
                }).join('')
                + '<feBlend in="r" in2="g" mode="screen" result="rg"/><feBlend in="rg" in2="b" mode="screen"/>';
            el.style.setProperty('--monitor-lens', 'url(#' + el.lensFilter.id + ')');
            el.classList.add('monitor-lens');
        }

        document.addEventListener('DOMContentLoaded', function () {
            var timers = new Map();
            var observer = new ResizeObserver(function (entries) {
                entries.forEach(function (entry) {
                    clearTimeout(timers.get(entry.target));
                    timers.set(entry.target, setTimeout(function () { build(entry.target); }, 150));
                });
            });

            document.querySelectorAll('.monitor-glass-liquid').forEach(function (el) {
                observer.observe(el);
            });
        });
    })();
</script>
{{-- end liquid glass lens --}}
