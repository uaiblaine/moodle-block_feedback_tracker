# Vendored frontend rendering bundle

`bft-vendor-10.29.8-3.1.1.min.js` is a single concatenation of three upstream
UMD distributions, wrapped in an outer IIFE that shadows RequireJS's `define`
so the UMDs take their global-script branch instead of registering as
anonymous AMD modules.

The file name carries the Preact and htm versions, and it is written out in one
place only: `FILENAME` in
[`classes/local/output/vendor_bundle.php`](../../classes/local/output/vendor_bundle.php).
The block and every page that mounts a Preact root (the teacher dashboard, the
pending report, the score simulator and the admin-only
[`pages/spike_react.php`](../../pages/spike_react.php)) load it through
`vendor_bundle::load()`, which adds it to the page head
(`$PAGE->requires->js(..., $inhead = true)`). The shim
[`amd/src/lib/preact.js`](../../amd/src/lib/preact.js) is the only AMD module
that reads the bundle's globals; every component imports from that shim.

The bundle aliases the upstream globals to `window.bftPreact`,
`window.bftPreactHooks`, and `window.bftHtm`, then deletes the original
`window.preact` / `window.preactHooks` / `window.htm` so other plugins
vendoring different versions don't collide.

## Components

| Library | Version | License | Upstream |
|---|---|---|---|
| Preact | 10.29.8 | MIT | https://github.com/preactjs/preact |
| Preact hooks | 10.29.8 | MIT | https://github.com/preactjs/preact (same release) |
| htm | 3.1.1 | Apache-2.0 | https://github.com/developit/htm |

## Integrity

These hashes are recorded here and, as XML comments, in
[`thirdpartylibs.xml`](../../thirdpartylibs.xml).
`tests/local/output/vendor_bundle_test.php` fails when the bundle on disk no
longer matches the bundle hash below, or when `thirdpartylibs.xml` names a
different file or version.

| File | SHA-384 |
|---|---|
| `preact@10.29.8` `dist/preact.min.js` | `sha384-umzx2g5uHatVsBNHavak2lu2T+zmk3Y9Rsi0o/N7Gi+1QTcpVScRMQaazAnC3TqX` |
| `preact@10.29.8` `hooks/dist/hooks.umd.js` | `sha384-5BuvBL8F0gboRla2VFizWuUXbA44J2dFYJZZGhPD7/8zlTbKMJAPtgIerv7vIpPw` |
| `htm@3.1.1` `dist/htm.js` | `sha384-iPOMe3E8jVgp/PepuDy7lJvw7L/QP97X5POfi+X1EVyDVQi8Kuv/MooNVB5OFXVN` |
| `bft-vendor-10.29.8-3.1.1.min.js` (the bundle) | `sha384-3YsbCSP54Ouy0vZww7yj9uTpF2K2PTpnu4D6OfR63Xk53KNRFLdRufSZzdSMXb1P` |

The npm registry's own checksums for the two release tarballs, which `npm pack`
verifies while downloading:

| Tarball | `dist.integrity` |
|---|---|
| `preact-10.29.8.tgz` | `sha512-ej2aVZ+vZ8WO7tvlQWRM9N63A0KzF9q4mWJfDUHgYaIofWY9hu74QdnQrjoPMmZi2/nZ5gN0bJCQF49xQqx09Q==` |
| `htm-3.1.1.tgz` | `sha512-983Vyg8NwUE7JkZ6NmOqpCZ+sh1bKv2iYTlUkzlWmA5JD2acKoxd4KVxbMmxX/85mtfdnDmTFoNKcg5DGAvxNQ==` |

## Provenance — how the bundle was assembled

The three files come from the npm release tarballs, so npm checks them against
the registry's integrity hashes; the jsDelivr copies of the same paths are
byte-identical and serve as a second source to compare with. The bundle keeps
each file's first line, dropping the trailing `//# sourceMappingURL=` comment
because the maps are not shipped, and puts it between a fixed prologue (licence
header, the IIFE opening, the already-loaded guard and `var define;`) and a
fixed epilogue (the `bft*` aliasing and the deletion of the upstream globals),
both taken unchanged from the bundle being replaced.

Run from `blocks/feedback_tracker/js/vendor`, with `old` set to the bundle
being replaced and `preact`/`htm` to the new versions (npm here runs in a
container, for example
`docker run --rm -v "$PWD":/w -w /w node:22-bookworm npm pack ...`):

```sh
old=bft-vendor-10.29.2-3.1.1.min.js
preact=10.29.8
htm=3.1.1
work=$(mktemp -d)

# Fetch, printing the registry's integrity values to compare with the table above.
npm view "preact@$preact" dist.integrity
npm view "htm@$htm" dist.integrity
(cd "$work" && npm pack "preact@$preact" "htm@$htm")
tar -xzf "$work/preact-$preact.tgz" -C "$work" && mv "$work/package" "$work/preact"
tar -xzf "$work/htm-$htm.tgz" -C "$work" && mv "$work/package" "$work/htm"

# Second source: each file must be byte-identical to its jsDelivr copy.
curl -sSLf "https://cdn.jsdelivr.net/npm/preact@$preact/dist/preact.min.js" | cmp - "$work/preact/dist/preact.min.js"
curl -sSLf "https://cdn.jsdelivr.net/npm/preact@$preact/hooks/dist/hooks.umd.js" | cmp - "$work/preact/hooks/dist/hooks.umd.js"
curl -sSLf "https://cdn.jsdelivr.net/npm/htm@$htm/dist/htm.js" | cmp - "$work/htm/dist/htm.js"

# Component hashes for the Integrity table.
for f in preact/dist/preact.min.js preact/hooks/dist/hooks.umd.js htm/dist/htm.js; do
    echo "$f sha384-$(openssl dgst -sha384 -binary "$work/$f" | base64)"
done

{
    sed -n '1,/^var define;$/p' "$old"
    printf '/* === Preact %s (MIT) — https://github.com/preactjs/preact === */\n' "$preact"
    sed -n 1p "$work/preact/dist/preact.min.js"
    printf '\n/* === Preact hooks %s (MIT) — https://github.com/preactjs/preact === */\n' "$preact"
    sed -n 1p "$work/preact/hooks/dist/hooks.umd.js"
    printf '\n/* === htm %s (Apache-2.0) — https://github.com/developit/htm === */\n' "$htm"
    sed -n 1p "$work/htm/dist/htm.js"
    printf '\n'
    sed -n "/^if (typeof window !== 'undefined') {\$/,\$p" "$old"
} > "bft-vendor-$preact-$htm.min.js"

echo "bundle sha384-$(openssl dgst -sha384 -binary "bft-vendor-$preact-$htm.min.js" | base64)"
rm "$old"
```

Then set `vendor_bundle::FILENAME` to the new name, and update the versions and
hashes here and in `thirdpartylibs.xml`. The same script run with the old
versions against the old bundle reproduces it byte for byte, which is how it was
checked before the 10.29.8 update. `tests/behat/vendor_bundle_smoke.feature`
opens every page that loads the bundle and fails when any of them renders no
Preact tree.

## Why concatenated, not three separate scripts

The Preact hooks UMD checks `typeof define === 'function' && define.amd` and,
if true, registers as an **anonymous** AMD module — which trips a "Mismatched
anonymous define()" error the next time Moodle's RequireJS resolves any other
module. Loading three separate `<script>` tags would expose each UMD to a
live `define` global. Wrapping all three inside one IIFE with a local
`var define;` is the smallest, least invasive fix; trying to do the same
across three separate scripts would require either patching each upstream
file (more divergence from upstream) or non-standard inline script tricks.

## Forward migration

When the plugin's required Moodle version bumps to 5.2+ (which ships React
natively via the `react`/`react-dom` import-map specifiers), the bundle is
deleted entirely and [`amd/src/lib/preact.js`](../../amd/src/lib/preact.js)
re-exports from `react` instead of `window.bftPreact`. See the migration
table in the project's MVP-2 plan.
