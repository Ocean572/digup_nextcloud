# DigUp Search — Nextcloud app

A Nextcloud app (PHP, installed like any Nextcloud app, zero extra containers) that
brings [DigUp](https://github.com/ARahim3/DigUp)'s semantic media search into
Nextcloud: a top-bar icon opens a Files-style search page — search box, results-count
selector, preview grid — and every answer is filtered to the files the asking user can
access: their own files plus files shared with them, never anything else.

The companion server side (compose shape, token map, exclusions, the binding ACL
contract) lives in the DigUp repository: `docs/implementation/stage-12-integrations.md`.

## How it works

```
browser ── Nextcloud login/session ──▶ this app (PHP, inside Nextcloud)
                                          │ server-to-server, bearer READ token
                                          ▼
                                       DigUp server (any machine, the one with the GPU)
```

* The app asks the DigUp server `POST /api/v1/search` with a **read-scope bearer token**
  the operator pastes into Administration settings. The token never reaches the browser:
  it lives in appconfig, sent only from Nextcloud's PHP process.
* Every hit's path is mapped back to a Nextcloud file **the asking user can access**
  (own files in the user's view root; foreign files only through incoming user/group
  shares, resolved per request). Hits the user cannot see are dropped completely.
* Previews render through **Nextcloud's own preview API** with the user's own session;
  clicking a result opens it in the **Files app** (viewer/Videos/PDF viewer).
* **Read-only by design**: the app writes no files, creates no database tables, touches
  no Nextcloud schema. Its own settings are appconfig rows — the mechanism every
  Nextcloud app uses. The server mounts the data dir read-only and never modifies it.

## Requirements

* Nextcloud 28–31 (works with the Docker image `nextcloud:28-apache` and the base install).
* A running DigUp server indexing this instance's data directory. The ready-made shape is
  `deploy/docker-compose.nextcloud.yml` in the DigUp repository (one stack: Nextcloud +
  server, the data dir mounted read-only at `/media/photos`, the `appdata_<instanceid>`
  junk excluded, the token map registering this instance). The server may live on
  another machine: give the app its URL; the data tree reaches the server as a
  read-only NFS/SMB mirror mount (a file indexer indexes files).

## Install

```sh
# 1. clone into the apps dir (e.g. the compose stack's DIGUP_NEXTCLOUD_APPS_DIR):
git clone https://github.com/Ocean572/digup_nextcloud.git <apps-dir>/digup

# 2. enable (occ), then set server URL + token in Administration settings → DigUp Search
docker exec -u www-data <nextcloud-container> php occ app:enable digup
```

CLI configuration (the same appconfig rows the settings form writes):

```sh
php occ config:app:set digup base_url   --value="http://server:8787"
php occ config:app:set digup read_token --value="<the server's nextcloud read token>"
php occ config:app:set digup root_prefix --value="/media/photos"   # as the SERVER sees the data dir
```

### SSRF firewall (Docker / private networks) — measured on 28.0.14

Nextcloud's HTTP client refuses private-address destinations by default. The app passes
core's sanctioned per-request option (`'nextcloud' => ['allow_local_address' => true]`) on
its calls to the admin-configured server URL, **and that flag alone is inert on Nextcloud
28** for app requests: measured, core's guard answers "Host violates local access rules"
with the flag set. The deployment shape for a server on a private address is the system
knob:

```sh
php occ config:system:set allow_local_servers --value=true --type=bool
```

The app's "Test connection" names this fix in words when the firewall bites. Off-LAN, put
TLS on the server via a reverse proxy and keep the firewall closed.

## Operator settings (Administration settings → DigUp Search)

| Field | Meaning |
|---|---|
| DigUp server URL | base URL of the server, e.g. `http://192.0.2.10:8787` |
| Path the server mounts the share at | the prefix the server indexes this instance's files at — `/media/photos` (data-dir shape) or `/media/dav` (WebDAV shape below) |
| How the server sees this instance's files | **Data directory**: the raw Nextcloud data dir mounted on the server, rows shaped `<prefix>/<user>/files/<rel>`. **WebDAV mounts**: per-user davfs2 mounts on the server, rows shaped `<prefix>/<user>/<rel>` — each user's own view (the WebDAV deployment below). The prefix and the mode must match the server's mount, exactly. |
| Read token | the server's `DIGUP_SERVER_READ_TOKENS` entry for this instance — read scope: search, media, health. It can NOT index, prune, or estimate. |
| Verify TLS | on by default; uncheck only for self-signed LAN servers |

**Test connection** probes the server's open `/api/v1/health` (file/vector counts,
engine tag) — the fastest way to see the SSRF firewall, a token typo, or a dead server,
each named in plain words.

## Indexing

The server (not the app) indexes: open the DigUp server UI with the **admin** token and
press "Index now", or `POST /api/v1/index`. Indexing never starts by itself. One job at
a time — a second request answers `409` while one runs (busy, by design; the flock
serializes writers). First pass over a big tree takes hours; the job survives restarts
and resumes.

## Deploying via WebDAV mounts (no setup on the Nextcloud machine)

The data-dir shape above shares Nextcloud's raw data folder with the server machine.
The **WebDAV shape** needs nothing from the Nextcloud machine at all: Nextcloud already
serves WebDAV (its sync endpoint), and the DigUp box mounts it per user with davfs2.
Each mount exposes exactly that user's own view — own files, everything shared to them,
groupfolders, received external shares — so the mount IS the ACL, and server-side
encryption is fine (WebDAV serves the decrypted bytes).

**Setup on the DigUp server machine — zero sudo, the compose file does the mounting.**
The stack ships a `dav` mount container (davfs2 in a container, read-only mounts, live
mount propagation to the server; the Nextcloud box stays untouched). One app password
per user (Nextcloud → Settings → Security → create, or on the Nextcloud host:
`occ user:auth-tokens:add <user>`), then:

```sh
NEXTCLOUD_URL=https://nc.example.lan DAV_USERS="alice bob carol" \
DAV_APPPW="alice:<pw> bob:<pw> carol:<pw>" \
DIGUP_SERVER_ROOTS=/media/dav/alice:/media/dav/bob:/media/dav/carol \
DIGUP_MEDIA_TARGET=/media/dav DIGUP_MEDIA_BIND=$PWD/data/ncdav \
DIGUP_MEDIA_BIND_OPTS=ro,rslave \
DIGUP_NEXTCLOUD_INDEX_DIR=./data/nextcloud/index-dav \
docker compose -f docker-compose.nextcloud.yml --profile dav up -d --build
```

The roots are one entry **per mounted user** (the engine's walk does not cross mount
points — a walk rooted AT each dav view works; the colon list is the shipped shape).
Set this app's settings to prefix `/media/dav` with mapping mode **WebDAV mounts**, then
index. The mounts propagate **live**: stop/start the dav container is the whole remount
procedure, no server restart. The settings page's **Test connection** reports 0 files
plus the exact fix when mounts are down; the dav container logs name each mount.

Host-mount alternative (fstab/automount deployments): `deploy/nextcloud-webdav.sh` does
the same mounts at the OS level — its header documents the per-user env shape; with
that shape, (re)mount, then RESTART the server container (a FUSE mount made after
container start is invisible to a plain bind).

The honest costs (measured, see the pilot gates): shares duplicate in the index (one
engine row per viewer-mount — a folder shared to 5 users embeds 5×), davfs2 crawl
latency (fine for thousands of files, slow at 100k+), and app passwords per user. The
wins: zero Nextcloud-host setup, server-side-encryption-safe, internet-safe (HTTPS +
app passwords, no file-server port exposed), and per-user ACL from the mount itself.

## Measured pilot gates (this app + the shipped compose stack, green 2026-11-07)

1. Suite: **117 passed, 1 deselected (the `-m slow` timing run), 0 skipped**, 111.09 s —
   `test_integrations.py` (15 integration tests) among them.
2. Shared stack: 36 public photos, planted preview/thumbnails/versions junk:
   `POST /estimate` counts the photos only (37 indexable, 0 junk), `POST /index`
   answers `done` (45 searchable / 45 vectors in 40 s), five searches 3 hits / 3 strong
   each at 12.9–28.6 ms.
3. Token scopes: tokenless search 401, read token 200 (search/media), read token on
   index/estimate 403, admin estimate 200; the server's audit log names each principal
   (`principal=nextcloud`, `principal=admin`).
4. Page 200 inside Nextcloud's chrome; the top-bar entry is present in core's app state
   (the browser renders it); previews serve `image/jpeg` through core's preview endpoint
   with the user's session; Test connection 200 with the live counts.
5. ACL gate (the app's own API, session-authenticated), pilot users A/B/C: every user
   sees their own files (A 3 dog hits, B 3 office hits, C 1 tabby); **shared files map
   to the recipient's view** (B 1 shared dog hit, A 1 shared office hit); the uninvolved
   user sees 0 for those queries; a file id not shared with a user answers 404 from
   core's preview for that user.
6. Settings surface: admin form renders with the save/test endpoints wired; a non-admin's
   POST to the settings route answers 403; the read token appears in no page HTML.
7. No mutation: 36/36 user files byte-identical to the pre-pilot baseline; the only
   changed content is Nextcloud's own preview cache (`appdata_*`); the app's code carries
   zero write APIs (grep-audited: no Filesystem, no DB connection); the DB diff shows
   appconfig rows only (`enabled`, `installed_version`, `types`, four app keys).
8. Restart survival (`docker restart` Nextcloud, stop/start server: exit **143** +
   `Application shutdown complete`) — health 200 after, search + preview green.
9. **WebDAV dav-container shape (2026-11-07, same box, same app code):** `--profile dav`
   mounted 3 users' dav views read-only, propagated live (server saw 65/56/50 files,
   estimate 153, index `done`); ACL battery over dav roots: alice 6/3/3, bob 4/6,
   carol 2/3/3 hits — the shared dog deduped to each viewer's own mount path, carol's
   "dog running" answering document-only hits (her view has no shared photos); preview
   200/404 by share; mount-flap with NO server restart (dav stop → estimate 0 files →
   dav start → 153 files back); graceful stop → server 143, dav unmount-trap line,
   post-restart search 5 hits top z 5.0; 36/36 fixtures byte-identical after the run.

## Privacy

The app sends only the search query text + the user id (server-to-server, bearer
token). The server's log line names the principal and endpoint of each request, never
query text, never usernames. The app never uploads file contents: indexing is the
server reading its own mounted copy, the app never talks to the server about individual
files.

## Development notes — what Nextcloud 28 actually requires (all measured in the pilot)

* **The bootable class is `OCA\<namespace>\AppInfo\Application`** implementing `IBootstrap`.
  A class named elsewhere (`lib/App.php`) is loaded as the app *entry* and its `register()`
  /`boot()` never run — settings never register, silently.
* **Sections/forms register at boot**, not from info.xml:
  `$manager->registerSection('admin', AdminSection::class)` +
  `registerSetting('admin', Admin::class)`. The info.xml `<settings>` map is not read at 28.
* The public section interface is `OCP\Settings\IIconSection` (`ISection` is not public API
  at this version).
* Page and settings forms return `OCP\AppFramework\Http\TemplateResponse`.
  `OCP\AppFramework\Template` and `OCP\AppFramework\Http\AppTemplate` do not exist at 28.
* `getSharedWith($recipient, $type, null, -1, 0)` — a non-null node (even `''`) crashes the
  provider, and a `0` limit answers nothing.
* `IShare::getTarget()` is the **source** path (what the recipient was offered), not a path
  in their view. The recipient's path comes from `viewRoot->getById($share->getNodeId())`
  — which answers shared nodes at their mounted location and **nothing** for files the user
  cannot see. That lookup is the ACL, per request, per user.
* `Node::getRelativePath()` is not part of the Node API. View paths = node path minus the
  view root's path.
* `IGroupManager::getUserGroups()` takes an `IUser`, not a uid.
* Routes: `appinfo/routes.php` is the registry at this version (controller attribute routes
  are not auto-registered for legacy-shape apps); routes.php-era routes are admin-only
  unless a `@NoAdminRequired` docblock says otherwise.
* `linkToRoute()` resolves core routes only within the request's own app scope (other scope
  → `''`); the app composes core's documented paths (`/index.php/apps/<app>/…`,
  `linkTo('', 'core/preview', …)`).
* `occ` runs against a full disk corrupt `config.php` / `.htaccess` (the rewrite block is
  written by the web SAPI). After a disk-full crash: `occ maintenance:update:htaccess`.

## License

MIT — see `LICENSE`.
