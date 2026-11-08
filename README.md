# DigUp Search — Nextcloud App

DigUp Search is a Nextcloud app (PHP, installed like any other Nextcloud app) that brings [DigUp](https://github.com/ARahim3/DigUp)'s semantic media search into Nextcloud without requiring any additional containers.

A search icon in the Nextcloud top bar opens a Files-style search page with a search box, results-count selector, and preview grid. Every result is filtered against the files the requesting user is allowed to access: their own files and files shared with them—nothing else.

The companion server configuration—including the Docker Compose setup, token map, exclusions, and binding/ACL contract—is documented in the DigUp repository at `docs/implementation/stage-12-integrations.md`.

## How It Works

```text
Browser
  │
  ├── Nextcloud login/session
  ▼
DigUp Search app (PHP, running inside Nextcloud)
  │
  ├── Server-to-server request
  │   └── Bearer token with read scope
  ▼
DigUp server (can run on a separate machine with a GPU)
```

- The app sends `POST /api/v1/search` to the DigUp server using a **read-scope bearer token** configured by an administrator. The token is stored in Nextcloud's appconfig and is sent only by the Nextcloud PHP process; it is never exposed to the browser.
- Each search hit is mapped back to a Nextcloud file that the requesting user can access. This includes the user's own files in their view root and files shared with them through incoming user or group shares. Share paths are resolved for each request, and inaccessible hits are discarded.
- Previews are served through **Nextcloud's own preview API**, using the user's session. Clicking a result opens the file in the **Files app**, where the appropriate viewer can handle it (including the video and PDF viewers).
- The app is **read-only by design**. It does not write files, create database tables, or modify Nextcloud's database schema. Its settings are stored as appconfig rows, using Nextcloud's standard mechanism for app settings. The DigUp server mounts the data directory read-only and does not modify it.

## Requirements

- Nextcloud 28–31. The app has been tested with both the `nextcloud:28-apache` Docker image and a base installation.
- A running DigUp server that indexes this Nextcloud instance's data directory.

The DigUp repository includes a ready-to-use deployment configuration at `deploy/docker-compose.nextcloud.yml`. It defines a stack containing Nextcloud and the DigUp server, mounts the Nextcloud data directory read-only at `/media/photos`, excludes the `appdata_<instanceid>` directory, and registers the instance in the server's token map.

The DigUp server can also run on a separate machine. In that setup, provide the server URL in the app settings and make the data tree available to the server through a read-only NFS or SMB mirror mount. DigUp indexes the files from that mounted tree.

## Installation

```sh
# 1. Clone the app into Nextcloud's apps directory.
#    For example, use the compose stack's DIGUP_NEXTCLOUD_APPS_DIR.
git clone https://github.com/Ocean572/digup_nextcloud.git <apps-dir>/digup

# 2. Enable the app.
docker exec -u www-data <nextcloud-container> php occ app:enable digup
```

After enabling the app, configure the DigUp server URL and read token under **Administration settings → DigUp Search**.

### CLI Configuration

The following commands configure the same appconfig values written by the settings form:

```sh
php occ config:app:set digup base_url --value="http://server:8787"
php occ config:app:set digup read_token --value="<the server's nextcloud read token>"
php occ config:app:set digup root_prefix --value="/media/photos" # As seen by the DigUp server
```

### SSRF Firewall: Docker and Private Networks

*Measured on Nextcloud 28.0.14.*

Nextcloud's HTTP client blocks requests to private-address destinations by default. The app supplies the core-supported per-request option below when calling the administrator-configured server URL:

```php
'nextcloud' => ['allow_local_address' => true]
```

However, on Nextcloud 28, that option alone does not bypass the restriction for app requests. This was verified during testing: even with the option set, core returned `Host violates local access rules`.

For deployments where the DigUp server is on a private address, enable the system-level setting:

```sh
php occ config:system:set allow_local_servers --value=true --type=bool
```

If the firewall blocks the connection, the app's **Test connection** action explains this setting in its error message.

For servers accessed from outside the LAN, configure TLS through a reverse proxy and leave the private-network firewall closed.

## Operator Settings

Configure the app under **Administration settings → DigUp Search**.

| Setting | Description |
|---|---|
| **DigUp server URL** | Base URL of the DigUp server, for example `http://192.0.2.10:8787`. |
| **Path the server mounts the share at** | Prefix under which the server indexes this Nextcloud instance's files. Examples: `/media/photos` for the data-directory layout or `/media/dav` for the WebDAV layout described below. |
| **How the server sees this instance's files** | Select **Data directory** when the server mounts the raw Nextcloud data directory. Rows then have the form `<prefix>/<user>/files/<rel>`. Select **WebDAV mounts** when the server uses per-user `davfs2` mounts. Rows then have the form `<prefix>/<user>/<rel>`, representing each user's own Nextcloud view. The prefix and mapping mode must match the server's mount configuration exactly. |
| **API key** | One field: paste the key from the DigUp server's **Settings → API key** — the row for this application. On a plain deployment this is the server's `DIGUP_SERVER_READ_TOKENS` entry (read scope: search, media, health — no index, prune, estimate). With the server's **named indexes**, paste the minted key for this app: every search, the status line, and Index now then answer **this app's own index**, and the key cannot touch any other index (docs/API.md "Named indexes", server repo). |
| **Verify TLS** | Enabled by default. Disable it only when connecting to a self-signed server on a trusted LAN. |

**Test connection** checks the server's unauthenticated `GET /api/v1/health` endpoint, which reports file and vector counts along with the engine tag. This is a quick way to identify common configuration problems, including the SSRF firewall, an incorrect token, or an unavailable server. Each failure is reported in plain language.

## Indexing

Indexing is performed by the DigUp server, not by the Nextcloud app.

Open the DigUp server UI using the **admin** token and select **Index now**, or send a request to `POST /api/v1/index`. Indexing never starts automatically. The app's own settings page shows a one-line status of the index this app's key answers — target, files, searchable, straight from the server — and its **Index now** button queues the crawl with the stored key: `202` accepted with the job, `409` while a job is running, `403` with the server's own words when the key cannot queue it. With the server's named indexes, that crawl is the app's own index, built in parallel with the server's media index (its writer lock lives beside its own index folder).

Only one indexing job can run at a time **per index**. If another request arrives at the same index while its job is running, the server responds with `409 Conflict`. This is intentional: a `flock` serializes writers.

The first indexing pass over a large directory tree can take several hours. Jobs survive restarts and resume where they left off.

## Deploying with WebDAV Mounts

The data-directory deployment described above shares Nextcloud's raw data directory with the DigUp server machine.

The **WebDAV deployment** does not require any setup on the Nextcloud machine. Nextcloud already exposes WebDAV through its sync endpoint; the DigUp machine mounts each user's WebDAV view with `davfs2`.

Each mount exposes exactly the files visible to that user, including:

- The user's own files.
- Files shared directly with the user.
- Group folders.
- Received external shares.

In this deployment, the mount itself enforces the access-control boundary. Server-side encryption is also supported because WebDAV serves the decrypted file contents.

### Setup on the DigUp Server

The supplied Compose configuration handles the mounts. No `sudo` access or changes on the Nextcloud machine are required.

The stack includes a `dav` mount container that runs `davfs2` in a container. It creates read-only mounts and propagates them live into the DigUp server container.

Create one app password per Nextcloud user. This can be done under **Nextcloud → Settings → Security → Create app password**, or on the Nextcloud host with:

```sh
occ user:auth-tokens:add <user>
```

Then start the stack with the appropriate environment variables:

```sh
NEXTCLOUD_URL=https://nc.example.lan \
DAV_USERS="alice bob carol" \
DAV_APPPW="alice:<pw> bob:<pw> carol:<pw>" \
DIGUP_SERVER_ROOTS=/media/dav/alice:/media/dav/bob:/media/dav/carol \
DIGUP_MEDIA_TARGET=/media/dav \
DIGUP_MEDIA_BIND=$PWD/data/ncdav \
DIGUP_MEDIA_BIND_OPTS=ro,rslave \
DIGUP_NEXTCLOUD_INDEX_DIR=./data/nextcloud/index-dav \
docker compose -f docker-compose.nextcloud.yml --profile dav up -d --build
```

Configure the app with the `/media/dav` prefix and select **WebDAV mounts** as the mapping mode. Then run indexing.

The roots list must contain one entry for each mounted user. The indexing engine's directory walk does not cross mount points, so each DAV view must be an explicit walk root. The colon-separated list shown above is the supported configuration shape.

Mounts propagate live into the server container. To remount, stop and start the `dav` container; restarting the DigUp server is not required.

If the mounts are unavailable, **Test connection** reports zero files and describes the required fix. The DAV container logs identify each mount.

### Host-Mount Alternative

For deployments that use host-level mounts through `/etc/fstab` or automount, `deploy/nextcloud-webdav.sh` provides the equivalent setup. Its header documents the required per-user environment variables.

After mounting or remounting through this method, restart the DigUp server container. A FUSE mount created after the container starts is not visible through an ordinary bind mount.

### Trade-offs

The measured costs and benefits of the WebDAV deployment are:

**Costs**
- Shared files are duplicated in the index: there is one engine row per viewer mount. For example, a folder shared with five users is embedded five times.
- `davfs2` adds crawl latency. This is acceptable for thousands of files but becomes slow at 100,000 files or more.
- Each user requires an app password.

**Benefits**
- No setup is required on the Nextcloud host.
- Compatible with server-side encryption because WebDAV exposes decrypted file contents.
- Safe for internet-facing deployments when used with HTTPS and app passwords; no file-server port needs to be exposed.
- Per-user access control is enforced by each user's own mount.

## Measured Pilot Gates

*The app and supplied Compose stack passed the following pilot gates on 2026-11-07.*

1. **Test suite:** 117 passed, 1 deselected (the `-m slow` timing run), and 0 skipped in 111.09 seconds. This includes all 15 integration tests in `test_integrations.py`.

2. **Shared-stack indexing and search:** The test stack contained 36 public photos and planted preview, thumbnail, and versions junk. `POST /estimate` counted the photos correctly (37 indexable files, 0 junk). `POST /index` returned `done`, producing 45 searchable files and 45 vectors in 40 seconds. Five searches each returned 3 hits and 3 strong matches, with latencies from 12.9 to 28.6 ms.

3. **Token scopes:** Search without a token returned `401`. A read token returned `200` for search and media requests, but `403` for index and estimate requests. The admin token returned `200` for estimate. The server audit log identified each principal (`principal=nextcloud` and `principal=admin`).

4. **Nextcloud UI and previews:** The search page returned `200` inside Nextcloud's UI. The top-bar entry was present in core's app state and rendered in the browser. Previews were served as `image/jpeg` through core's preview endpoint using the user's session. Test connection returned `200` with live counts.

5. **ACL enforcement:** The app's session-authenticated API was tested with pilot users A, B, and C. Each user saw their own files (A: 3 dog hits; B: 3 office hits; C: 1 tabby hit). Shared files mapped to the recipient's view (B: 1 shared dog hit; A: 1 shared office hit). An uninvolved user received 0 hits for those queries. Core's preview endpoint returned `404` when a user requested a file ID that had not been shared with them.

6. **Settings access:** The admin form rendered, and its save and test endpoints were connected. A non-admin POST to the settings route returned `403`. The read token did not appear in any page HTML.

7. **No file mutation:** All 36 of 36 user files remained byte-identical to the pre-pilot baseline. The only changed content was Nextcloud's own preview cache (`appdata_*`). A grep audit found no write APIs in the app code (no Filesystem or DB connection). The database diff contained only appconfig rows: `enabled`, `installed_version`, `types`, and four app-specific keys.

8. **Restart survival:** After restarting Nextcloud and stopping/starting the DigUp server, the server exited with code `143` and logged `Application shutdown complete`. Health returned `200` after restart, and search and preview tests passed.

9. **WebDAV mount-container deployment:** On 2026-11-07, the `--profile dav` deployment mounted three users' DAV views read-only and propagated them live. The server saw 65, 56, and 50 files in the three views; estimate reported 153 files, and indexing completed with `done`.

   The ACL test suite over the DAV roots returned these hit counts: Alice 6/3/3, Bob 4/6, and Carol 2/3/3. The shared dog was deduplicated to each viewer's own mount path. Carol's `dog running` query returned document-only hits because her view did not contain the shared photos.

   Preview requests returned `200` or `404` according to share access. A mount-flap test required no server restart: stopping the DAV container reduced estimate to 0 files; starting it again restored all 153 files. During graceful shutdown, the server exited with code `143` and the DAV container logged its unmount-trap line. After restart, search returned 5 hits, with a top score of `5.0`. All 36 fixture files remained byte-identical after the run.

10. **0.4.0 index line + Index now (2026-11-08, pilot stack, stage-13 server endpoints):** With the app deployed at 0.4.0 (files bind-swapped, `php -l` clean in the container, `node --check` clean on both scripts), the admin session's `GET /api/v1/status` returned `200` with the server's own numbers verbatim — target `media`, 131,973 files, 115,098 searchable, roots `['/media/photos']` (live counts mid-indexing). `POST /api/v1/index` passed the server's answer through verbatim: with the pilot's read-scope key it surfaced the honest words — *"that is this server's read-scope token; indexing and estimating need the admin token."* A non-admin user received `403` on both routes. The settings page gained the index line (target + counts) and the **Index now** button; the key field is now "Access key" (with named indexes, the server's minted application key, which answers this app's own index alone).
11. **0.4.1 "API key" (2026-11-08, the user's UI brief):** the settings section is one field, labeled **API key** — the same name the DigUp server's gear uses for the keys it shows with Copy. Deployed on the pilot stack (file bind-swap while the server's index job ran — no container restart): `php -l` clean, `occ config:app:set digup installed_version 0.4.1` green. No route or behavior changed: the field still saves the bearer the app sends on every request; the label, placeholder, and hint now point at the server's **Settings → API key** row for this application (the hint's two sentences name the two deployment shapes: env read-scope token, minted named-index key).
12. **0.4.2 preview-pane visibility fix (2026-11-08, user feedback round 1):** the pane's `display: flex` rule out-specified the browser's `[hidden]` rule — so on an empty page the pane, its bar (‹ ›, ✕, Open in Files) and the left border rule all rendered, and clicking ✕ "did nothing" (`hidden = true` was overridden just the same). The CSS fix is the one the lightbox already used: `#digup-detail[hidden] { display: none; }`. Now: nothing pane-shaped shows before a search, the pane opens only on a tile click (a fresh answer hides it), and ✕ closes it for real.

## Privacy

The app sends only the search query text and the requesting user's ID to the DigUp server, using a server-to-server request authenticated by the bearer token.

The server log records the principal and endpoint for each request, but never logs the query text or usernames.

The app never uploads file contents. The DigUp server indexes files by reading its own mounted copy of the data. The app does not send individual files to the server.

## Development Notes: Nextcloud 28 Requirements

The following behaviors and API requirements were confirmed during the pilot against Nextcloud 28.

- **Bootstrap class:** The bootable class is `OCA\<namespace>\AppInfo\Application`, which implements `IBootstrap`. A class defined elsewhere (for example, `lib/App.php`) is loaded as the app entry point, but its `register()` and `boot()` methods are not called. As a result, settings registration silently fails.
- **Registering settings:** Sections and forms must be registered during boot, not through `info.xml`. Use `$manager->registerSection('admin', AdminSection::class)` and `$manager->registerSetting('admin', Admin::class)`. The `<settings>` mapping in `info.xml` is not read in Nextcloud 28.
- **Section interface:** The public section interface in this version is `OCP\Settings\IIconSection`. `ISection` is not part of the public API in this version.
- **Template responses:** Page and settings forms return `OCP\AppFramework\Http\TemplateResponse`. Neither `OCP\AppFramework\Template` nor `OCP\AppFramework\Http\AppTemplate` exists in Nextcloud 28.
- **Resolving incoming shares:** Call `getSharedWith($recipient, $type, null, -1, 0)`. Passing a non-null node—even an empty string—causes the provider to crash. A limit of `0` returns no results.
- **Share paths:** `IShare::getTarget()` returns the source path (the path offered to the recipient), not the path in the recipient's view. Resolve the recipient's actual path with `viewRoot->getById($share->getNodeId())`. This returns shared nodes at their mounted location and returns nothing for files the user cannot access. This lookup is the per-request, per-user ACL check.
- **Relative paths:** `Node::getRelativePath()` is not part of the Node API. To obtain a view-relative path, subtract the view root's path from the node's path.
- **User groups:** `IGroupManager::getUserGroups()` accepts an `IUser` object, not a user ID.
- **Route registration:** `appinfo/routes.php` is the route registry for this version. Controller attribute routes are not automatically registered for legacy-shaped apps. Routes registered through `routes.php` are admin-only unless the controller method has a `@NoAdminRequired` docblock.
- **Core route links:** `linkToRoute()` resolves core routes only within the current request's app scope. A route in another scope resolves to an empty string. The app therefore composes core's documented paths, such as `/index.php/apps/<app>/…` and `linkTo('', 'core/preview', …)`.
- **Repairing `.htaccess` after disk-full errors:** `occ` can run against a corrupted or incomplete `config.php` / `.htaccess` state. The rewrite block is written by the web SAPI. After a disk-full crash, run `occ maintenance:update:htaccess`.

## License

MIT. See `LICENSE`.
