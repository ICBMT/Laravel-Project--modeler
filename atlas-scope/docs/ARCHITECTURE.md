# AtlasScope — how it is put together

A short tour for whoever reads the code next. The `README.md` covers usage.

## The pipeline

An upload becomes a graph in eleven stages
(`app/Services/Scan/Stages/`), driven by `ScanPipeline`:

```
Extract → Manifest → FileIndex → ClassParse → Route → Models →
Schema → Views → Links → Insights → Layout
```

Two rules hold the design together:

1. **Stages communicate through files, not memory.** Each one reads and writes
   JSON under `{workspace}/.atlas/` through `ScanContext`, so a scan can be
   replayed stage by stage and every intermediate understanding of the target
   project is inspectable.
2. **`GraphBuilder` is the single mutable graph.** Stages add nodes and edges to
   it; nothing else does. Node keys are stable and human readable:
   `class:App\Models\Task`, `route:GET /tasks`, `table:tasks`,
   `view:tasks.index`, `package:laravel/framework`.

`LayoutStage` is last and turns the graph into coordinates: one horizontal deck
per architectural layer, nodes placed inside it by a golden-angle spiral sorted
by module. The result is deterministic — the same project always lands in the
same place, so two scans can be compared side by side. Those coordinates are the
**Architecture layers** view; the browser can also reproject the same graph into
three other views (see *The layout engines*).

## The payload

`app/Services/GraphPayload.php` shapes the persisted graph into exactly what the
renderer needs (`nodes`, `edges`, `layers`, `types`, `clusters`, `edge_kinds`,
`metrics`, `insights`, `limits`) and hands it to the atlas over
`GET /api/atlas/projects/{project}/graph` — with a `202 {ready:false}` while a
scan is still running, which the front end polls.

## The front end

`resources/js/atlas/` is plain ES modules on top of three.js. `main.js` is the
only place that knows about all of them:

| module | responsibility |
|---|---|
| `api.js` | endpoint client, reading the `data-*` contract on `#atlas` |
| `store.js` | graph index, visibility bitmaps, filters, selection, events |
| `layouts.js` | three layout engines (layers, modules, spiral) |
| `scene.js` | the renderer: instanced nodes, one edge buffer, decks, labels, bloom, picking, camera tweens, the focus/dim engine |
| `inspector.js` | the four-tab right panel, source viewer, insights |
| `trace.js` | walks a route's outgoing flow edges into a readable journey |
| `ui.js` | rail, legend, minimap, tooltips, toasts |
| `scan.js` | the live scanning overlay |

`resources/css/app.css` is a hand-written design system — no utility framework,
on purpose. Reuse the existing classes (`atlas`, `hud-pill`, `rail-item`,
`metric`, `card`, `chip`, `legend`, …).

## The layout engines

`resources/js/atlas/layouts.js` holds three engines behind one dispatcher,
`computeLayout(mode, nodes, edges, visible)`. Each returns a flat `Float32Array`
of `[x, y, z]` triplets indexed by node index, so `scene.setLayout()` is the same
call whatever produced the numbers.

| mode | shape | answers |
|---|---|---|
| `layers` | the server's decks, verbatim | where does this live in the architecture? |
| `modules` | one disc per module, packed outward from the centre, every node on its layer's shelf | which module owns what? |
| `spiral` | one helix ordered by layer, then weight | the whole codebase as a single strand |

Rules the engines have to follow:

- **Tiers come from the layer slug.** The payload carries `layer` (and
  `layer_label`), *not* a `layerTier` field — reading one silently returned
  `undefined` for every node, which flattened the module map onto a single plane
  and made the spiral's "layer, then importance" sort a no-op. `LAYER_TIERS`
  mirrors `App\Enums\Layer::tierSlot()` so the browser agrees with the decks.
- **A cluster is a disc, and discs must not touch.** A cluster's radius is
  `30 + 17·√size`; the module map walks a golden-angle spiral and takes the first
  spot that clears every disc already placed (14 % clearance), biggest first. Do
  not go back to a ring of fixed radius: it has to be sized for its two widest
  neighbours, and on the sample project that inflated the map to 3 300 px across.
- **A layout switch frames where the nodes are going.** `fitAll()` measures node
  positions to build its box, and during a switch those positions are still the
  *old* layout's: the camera framed the view the user had just left while the new
  one animated in off screen (this is what "Modules is empty" was). The switch
  calls `fitAll(true, { useTarget: true })`.

### Switching layouts reports itself

A switch is two waits, and both are visible. `main.js#applyLayout()` shows a
progress card in the bottom-right of the stage, yields two frames so it paints,
runs the engine, then hands the graph to the scene. From there `AtlasScene` reports real progress: `setLayout()`
records the total distance every node still has to travel, and each frame that
distance is re-measured, so the bar shows the fraction of travel completed — a
number that comes from the animation itself rather than a timer. It reads
"Building … layout" (an indeterminate sweep, because an engine's remaining work
genuinely is not known) and then "Settling nodes … 82 %", and hides once the
morph has snapped into place.

![A layout switch in flight — the bar reports travel already completed](screenshots/layout-switch.png)

Measured on the sample project (130 visible nodes, software renderer, all three
modes): every layout lands inside the frame with **0 overlapping pairs**, and the
settled boxes are layers `249×529×240` (min gap 41.7), modules `894×480×903`
(35.1) and spiral `569×1701×569` (54.4).

`computeLayout()` falls through to the architectural decks for anything it does
not recognise, so a `?layout=` link from before a mode was retired still lands on
a real view.

## The focus engine (dim everything else)

Legibility at 180+ nodes comes mostly from what you *don't* draw. `scene.js`
keeps the full colour and opacity of every node, glow instance, edge and deck at
build time (`nodeBaseColors`, `glowBaseColors`, `edgeBaseColors`, per-deck
`baseOpacity`) and an `adjacency` map derived from the edges. `setHighlight()` /
`setTrace()` then only decide *where attention goes*:

- `focusKeys` = the selection ∪ its direct neighbours ∪ the traced path;
- `#tickFocus(delta)` eases a single scalar, `focusMix`, from the render loop —
  so the transition is animated but everything else stays declarative;
- `#applyFocus(mix)` re-tints from the recorded base colours: focused nodes get a
  small lift, unfocused ones drop to 0.2, lit glow goes ×1.45 against ×0.17,
  touched edges ×2.5 against ×0.14, and decks with nothing left in them fade out.

Focus has three tiers, not two, once a journey is being traced. `focusKeys` is
the path *plus every neighbour of every step* — right for a plain selection, but
on a journey that is dozens of nodes hanging off six steps, and lighting them all
equally is what made a traced chain look like one bright slab. So the chain gets
the lift, its neighbours recede to context, and the rest dims:

| tier | TaskFlow, `POST /tasks` traced | mean luma |
|---|---|---|
| the chain (6 nodes) | the request's own steps | 0.583 |
| context (51 nodes) | neighbours of the steps | 0.252 |
| everything else (126) | — | 0.058 |

Edges follow the same rule: while tracing, **only the journey's own links** are
lit (5 of 371 on TaskFlow) and every other line touching a step drops to 8%. The
bloom pass is pulled back as focus comes in (`0.62 → 0.36`) because it is
additive — the more bright geometry there is, the more the whole frame lifts —
and even a touched deck steps back 25%, since those big translucent discs were
most of the remaining glare.

The walk itself stops at a *terminal*: a table or a view is where a request ends,
so it is not penalised for having no next step (that penalty used to make the
journey prefer another model over the table it writes to). `uses` is
de-prioritised between two models for the same reason — it means "mentions this
class" and is exactly the right hop from a service, but noise from one model to
the next. Journeys are capped at 16 hops as a runaway guard; the old cap of 7 was
silently cutting 23 of TaskFlow's 27 journeys off at exactly 8 nodes, so the bar
was showing a truncated chain rather than the end of one.

Routes get a stronger treatment than the rest: when a selection is active, every
unfocused route is pulled towards its own luminance (`#desaturate()`, Rec.709
weights because three.js is working in linear space here) in both the node buffer
*and* the bloom buffer. There are dozens of routes on one deck, and they are the
thing a reader is usually hunting for — greying them leaves a single coloured
route and its neighbourhood. Measured on TaskFlow: mean chroma across the 27
routes falls 0.77 → 0.05 while the selected route holds 0.99 and its connected
nodes keep ~1.16.

The camera has the same "get out of the way" rule: `#moveCamera()` refuses to
start a tween while `controls.interacting` is set, so a scripted fly-to never
fights your hand.

## Failing loudly, in the app's own voice

Status pages are local Blade views (`resources/views/errors/`) with inline CSS —
no Vite manifest, no vendor assets, nothing that can itself 404. That matters
because the most likely failure in this app is a body PHP rejects before Laravel
boots: `upload_max_filesize` discards the POST, the CSRF token disappears, and
you get a 419 that has nothing to do with CSRF. The 419 and 413 pages therefore
print the live `upload_max_filesize` / `post_max_size` (`App\Support\Ini`) and the
`zip -x` recipe, and `bootstrap/app.php` only defers to Laravel's debug renderer
when its stylesheet actually exists on disk.

Two details there are easy to get wrong twice:

- `App\Support\Ini::capacity()` is the single source of truth for the limits, and
  `GET /api/atlas/capacity` serves it to the browser. The guard in `app.js` is
  *advisory* — it re-reads that endpoint on load, on focus and on file pick, and
  it always offers "upload anyway", so a stale or wrong number can never lock a
  legitimate upload out. PHP still gets the final word, and its refusal is a
  readable 413.
- The fallback error renderer in `bootstrap/app.php` only fires when `APP_DEBUG`
  is on, the framework's debug stylesheet is missing, **and** the exception is
  not one Laravel already answers (validation, auth, 404, …). Without that last
  condition an over-eager catch-all turns every `ValidationException` into a
  500 — which is exactly what it did until a test caught it.

### Uploads never depend on php.ini

`upload_max_filesize` and `post_max_size` apply only to **multipart form** data. A
raw request body is not form data, so a `PUT` of `application/octet-stream`
sidesteps both — verified on a stock 2 MB install with a 12 MB body. That single
fact is the design: `UploadController` hands out a session, accepts ~1 MB raw
pieces at `PUT /uploads/{token}`, glues them into an archive, and passes it to
`ProjectManager::adoptArchive()` — the *same* code path as a normal upload, so
unpacking, sandboxing, measuring and queueing are shared (`storeUpload()` is now
just "move the file, then adopt it").

The client chooses per file: at or below the PHP ceiling it submits the form
normally; above it, `resources/js/app.js` streams pieces and shows progress.
Aborted sessions are deleted, out-of-order pieces are refused with a 409 rather
than appended, and an abandoned session is swept after six hours.

Uploads have **one** number: `bin/limits.env` holds the natural 150 MB ceiling
(and the `post_max_size` headroom above it). `AtlasServe` reads it, `config/atlas.php`
reads it through `Ini::devBytes()`, and `Ini::capacity()` publishes it to the UI — so
a limit can never be advertised in one place and enforced in another. Two guards are
easy to confuse and must stay separate: `atlas.max_archive_bytes` caps the archive
itself, while `atlas.max_extracted_bytes` (2 GB) caps the unpacked tree, because
source compresses several times over and a legitimate 150 MB zip expands past 150 MB.

`php artisan atlas:serve` (aliased as `composer serve` and `bin/serve`) exists for one
reason: `artisan serve` spawns a child `php -S`, so `-d` flags on the parent never
reach the process that receives the upload body. The command overrides
`ServeCommand::serverCommand()` and puts the flags on the child invocation — pure
PHP, so it works the same on Linux, macOS and Windows. Note that `php_binary()` is a
Foundation helper Laravel 13 does not autoload; use the `PHP_BINARY` constant.

## Things that had to be got right

Three of these were found by running the app in a browser, not by reading it:

- **`mergeNode` has to carry `pos_x/pos_y/pos_z`.** The layout stage writes
  coordinates long after a node is created, and the merge would otherwise drop
  them — every node ended up at the origin.
- **`InstancedMesh` caches a bounding sphere on first raycast.** Instances start
  at zero scale, so that cached sphere was empty and clicking a node never hit
  anything. It is invalidated whenever an instance matrix moves.
- **A `<canvas>` is a replaced element.** `inset: 0` alone left the stage at its
  intrinsic 300×150; it needs explicit `width/height: 100%`.
- **Hidden means hidden everywhere.** Nodes removed by a filter have to leave the
  glow layer and their empty deck too, or they keep glowing behind the filter.
- **A layout link is two map lookups, not two scans.** Resolving an edge endpoint
  with `nodes.find(...)` is an O(nodes) pass per endpoint, per edge — ~30 million
  comparisons on a 2 500-node graph every time a layout is recomputed (150 ms of
  pure bookkeeping, measured). Resolving key → node index → local index through
  two maps is 54× faster, and it makes the visibility test real: the local index
  is built from the visible set, so a successful lookup *is* the test.
  what lets them slot back in when a filter is lifted.
- **Camera framing** fits the projected *nodes* (not a bounding sphere or box
  corners, which are far too conservative for a tall stack of round decks) with
  a centring pass, because a stack viewed from above projects asymmetrically.

## Adding a stage

1. Implement `App\Services\Scan\Stage` — `name()`, `run(ScanContext)`.
2. Register it in the order array in `ScanPipeline`.
3. Read what the earlier stages wrote with `$context->readArtifact('…')`, write
   your own with `writeArtifact()`, and add to the graph through `GraphBuilder`.
4. Add the case to `App\Enums\ScanStage` (the enum drives the UI pipeline list).
