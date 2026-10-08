# Stable PDF Links 2.2.1

This plugin publishes changing PDF files behind stable public URLs such as:

```text
https://example.com/documents/weekly/
```

The `/documents/` base and the top-level upload folder are configurable. Each
PDF Link has its own slug, storage folder, current version, retention target,
and absolute file limit.

## Important scope

The plugin is designed to coexist safely with an established WordPress site,
but no plugin can guarantee zero interaction with every host, cache, security
product, CDN, media-offload service, or third-party route. Take a current
backup and use staging when the live site has unusual routing or storage.

The plugin never requests a hard rewrite refresh. It never writes `.htaccess`,
`web.config`, or Nginx configuration.

## Installation

1. Back up the WordPress database and `wp-content`.
2. If the earlier Restaurant Menu Manager test plugin is installed, deactivate
   and remove it first. This version is a separate plugin and does not migrate
   test data from the earlier prototype.
3. In WordPress, open **Plugins → Add Plugin → Upload Plugin**.
4. Select `stable-pdf-links-site-custom.zip`.
5. Click **Install Now**, then **Activate Plugin**.
6. Open **PDF Links → General Settings**.
7. Choose the public URL base and upload base folder. Examples for the URL are
   `documents`, `downloads`, or `publications`. The upload folder defaults to
   `stable-pdf-links`.
8. Confirm that every item in **System check** is satisfactory.
9. Open **PDF Links → Add PDF Link** and create the first link.
10. Upload a small test PDF and test its permanent URL in a private browser
    window before using it on a public page.

The ZIP already contains the required plugin folder. Do not unzip it or create
a second ZIP around the included ZIP.

To upgrade from an earlier 2.x version, upload the new ZIP through the same screen and choose
**Replace current with uploaded** when WordPress recognizes the existing plugin
folder. Existing settings, PDF Links, and attachments are preserved. Missing-
PDF fallback remains disabled until it is explicitly tested and enabled.

## Recommended initial settings

```text
Public URL base:              documents
Upload base folder:           stable-pdf-links
Maximum PDF upload size:      5 MB
Default recent versions:      5
Default absolute limit:      10
Editor access:                disabled until needed
Missing-PDF fallback:         disabled until tested
```

The plugin upload limit can lower the effective limit but cannot raise PHP,
WordPress, multisite, or hosting limits.

## Cache configuration

The redirect endpoint sends a temporary `302`, WordPress no-cache headers,
`Cache-Control: no-store`, `Surrogate-Control: no-store`, and the commonly
recognized `DONOTCACHEPAGE` signal. A reverse proxy or CDN may nevertheless
override origin instructions.

Add the route base to every applicable cache layer:

- WordPress cache/optimization plugin;
- hosting control-panel cache;
- Nginx, Varnish, or another reverse proxy;
- CDN, including Cloudflare rules.

If the cache supports wildcards and the base is `documents`, exclude:

```text
/documents/*
```

Otherwise exclude every exact permanent path shown under
**PDF Links → General Settings → Cache exclusions**, for example:

```text
/documents/weekly/
/documents/seasonal/
```

Do not exclude all of `/wp-content/uploads/`. Actual PDFs use unique physical
filenames and can remain cached.

Without exclusions, the plugin still sends no-cache instructions and will work
correctly on caches that honor them. The risk is a cache layer configured to
ignore or override origin headers: it can store the `302` redirect and continue
sending visitors to an older physical PDF. If retention later deletes that
file, the same stale redirect can produce a 404. Exclusions prevent that class
of problem; a cache purge clears it after the fact.

When missing-PDF fallback is enabled, a cache layer may also retain a previous
404 for a deleted physical PDF. The plugin cannot replace a response that the
cache serves without contacting WordPress. Purge that cached 404 or disable
negative/404 caching for the plugin's upload base if this occurs. Existing PDF
files may remain normally cached.

After changing the public URL base:

1. Update all website buttons and external references.
2. Replace the old cache exclusions with the new ones.
3. Purge cached redirects at the WordPress, host, proxy, and CDN layers.
4. Test every permanent URL again.

The old URL base is not retained as an alias.

## Rewrite behavior

The plugin registers one generic route:

```text
/{configured-base}/{pdf-link-slug}/
```

It requests a soft, database-only rewrite refresh when:

- the URL base is saved for the first time;
- the URL base is later changed; or
- an existing configured installation is reactivated.

Uploading PDFs, changing individual PDF Link slugs, changing retention,
selecting a current version, and deleting versions do not refresh routes.

On deactivation the plugin removes only its exact current rule from the cached
rewrite-rules array. It does not delete or rebuild the full site route map. The
plugin never calls `flush_rewrite_rules( true )` and never writes web-server
configuration.

WordPress core or another plugin may independently refresh rewrite rules. For
example, saving **Settings → Permalinks** asks WordPress to refresh them.

## Missing-PDF fallback

The optional fallback covers this specific sequence:

1. A visitor opens the current physical PDF.
2. A replacement upload becomes current.
3. Retention permanently deletes the previous file.
4. The visitor refreshes the deleted file's direct URL.
5. WordPress redirects that missing URL to the stable PDF Link, which redirects
   to the new current PDF.

The option is disabled by default. In **General Settings → System check**, run
**Test missing-PDF fallback** first. The primary test asks the administrator's
browser to request a unique nonexistent PDF through the configured public
uploads URL, then asks WordPress to verify that the request arrived. This avoids
false inconclusive results when the web server cannot call its own public URL.
The test creates no file, adds no rewrite rule, deletes its short-lived probe
tokens immediately, and stores only the latest result. The plugin refuses to
enable the option unless the current uploads path reports **Supported**.
Changing the upload base invalidates the result and requires a new test.

When enabled, the request handler acts only when all of the following are true:

- WordPress has classified the request as a 404;
- the requested path is a `.pdf` directly inside a registered PDF Link folder;
- no file or symbolic link exists at that physical path;
- the folder still belongs to a published PDF Link; and
- that PDF Link has a current attachment.

Existing files bypass WordPress and are never redirected by this feature.
Other uploads, deeper paths, unregistered folders, non-PDF requests, and PDF
Links without a current version retain their normal behavior. A mistyped PDF
filename inside a registered folder intentionally resolves to that folder's
current PDF.

Some web servers, media CDNs, or security configurations return a static 404
without sending a missing upload request to WordPress. The feasibility test
reports **Not supported or intercepted** in that situation. An **Inconclusive**
result means the browser request could not be verified. With JavaScript
disabled, the plugin offers a server-side loopback test; that fallback test can
be inconclusive when hosting blocks self-requests. Leave the option disabled
unless the test subsequently succeeds.

## Version and retention behavior

Every upload becomes the current public version automatically.

The **recent versions** value is a soft target:

```text
Keep the newest X versions, plus current or protected exceptions.
```

The **absolute limit** includes every managed PDF:

- recent versions;
- the current version; and
- protected versions.

The absolute limit must be at least the recent-version target.

If protected files plus a prospective new current file cannot fit within the
absolute limit, the upload is refused. The plugin never silently deletes a
protected version. If cleanup fails after an upload and the hard limit cannot
be satisfied, the new upload is rolled back where safely possible.

Changing a retention value does not immediately delete files. Use **Apply
retention now** when intentional cleanup is required. Normal cleanup also runs
after successful uploads.

The absolute limit is a logical limit after upload. The host still needs enough
temporary space to receive a new PDF before an old one can be removed.

## Current and protected versions

- Uploading a PDF makes it current.
- **Make current** can restore any retained historical version.
- **Protect** prevents automatic retention cleanup.
- Protected files still count toward the absolute limit.
- The current version can be deleted when another managed version exists. The
  newest remaining version becomes current.
- The final remaining current PDF cannot be deleted through the plugin.

The plugin also guards managed current attachments when deletion is attempted
through the normal Media Library. A current attachment with no fallback is
protected; if a fallback exists, the newest remaining attachment is selected.

## Public filenames

The upload form accepts an optional public filename. For example:

```text
Selected local file: scan0048.pdf
Public filename: weekly-menu-2026-09-22.pdf
```

The plugin sanitizes the name, forces the `.pdf` extension, and lets WordPress
make it unique if necessary. Visitors see this physical filename after the
permanent URL redirects them.

Existing physical attachment files are not renamed in place. Upload a new
version with the desired public filename instead.

## Permissions

Administrators receive both plugin capabilities on activation.

By default, Editors receive no plugin access. General Settings can optionally
allow the built-in Editor role to:

- upload PDF versions;
- make a version current;
- protect/unprotect versions; and
- permanently delete eligible versions.

Editors cannot create/delete PDF Links or change General Settings. If the site
uses custom roles, grant the `manage_stable_pdf_versions` capability with a
trusted role-management tool. Configuration requires
`manage_stable_pdf_links`.

## Existing-site checks

Before production use, verify:

- WordPress uses a non-Plain permalink structure.
- The uploads directory is writable.
- The configured URL base does not overlap an existing page, post, taxonomy,
  shop route, multilingual route, membership route, or custom plugin endpoint.
- Every PDF Link URL works while all existing plugins are active.
- Cache exclusions are active at every caching layer.
- Media-offload/CDN plugins correctly preserve the dedicated upload path.
- Missing-PDF fallback reports **Supported** before it is enabled, if wanted.
- Security/WAF plugins accept the intended PDF size and MIME type.
- The selected WordPress roles have appropriate access.
- Available storage can hold retained files plus one incoming upload.

The live availability checks detect known WordPress content conflicts, but
arbitrary dynamic routes created by third-party code cannot be proven conflict
free in advance. Testing the final URLs remains necessary.

## Storage and deletion

Managed files are stored below the configured upload base:

```text
/wp-content/uploads/stable-pdf-links/{configured-folder}/
```

The upload base folder can be changed while it has no managed attachments and
its current directory is absent or empty. The requested new directory must
also be absent or empty. It locks after the first file exists so a settings
change cannot strand Media Library records or break direct PDF URLs. Changing
this folder does not trigger a rewrite refresh.

Files remain normal WordPress Media Library attachments.

Deleting a PDF version uses WordPress's permanent attachment deletion API.
Deleting a PDF Link offers two choices:

1. Remove the configuration and public link while retaining PDFs as ordinary
   Media Library attachments.
2. Delete the configuration and all PDFs managed by it.

The plugin removes a storage directory only when it is genuinely empty. It
never recursively deletes unknown files. If all managed PDFs are gone and the
directory is empty or absent, the storage-folder field becomes editable again.

Ordinary deactivation or plugin deletion does not automatically delete PDF
Links, settings, or PDF files. This is intentional protection against losing
content through an accidental plugin removal.

For an intentional clean removal:

1. Back up the site.
2. Open **PDF Links → General Settings → Full reset**.
3. Type the required confirmation and run the reset.
4. Review the completion notice and Maintenance audit.
5. Deactivate and delete the plugin.

Full reset permanently deletes all attachments carrying this plugin's managed
relationship, including detectable orphans, then deletes PDF Link definitions,
settings, and plugin route entries. It removes only empty directories and never
recursively deletes unknown files. If any managed attachment cannot be deleted,
the reset stops before deleting link definitions and settings.

## Maintenance and later cleanup

**General Settings → Maintenance audit** can later detect:

- a current-version pointer that no longer points to a valid managed PDF;
- a managed attachment whose PDF Link no longer exists;
- a WordPress attachment whose local file is missing;
- stale cached rewrite entries owned by this plugin;
- empty directories in the configured plugin upload base; and
- files or entries in that base which are not recognized as managed.

**Repair safe issues** restores current pointers, converts orphaned attachments
to ordinary Media Library items, removes stale plugin rewrite entries, and
removes empty directories. It does not delete unknown files or attachment
records whose local file is missing. A missing local file may be legitimate on
a site that offloads media to remote storage, so automatic deletion would be
unsafe.

The audit cannot discover or delete stale copies held by a WordPress cache
plugin, host cache, reverse proxy, browser, or CDN. It also cannot find old
bookmarks or links on other websites. Purge those cache layers after changing
or removing a public route. If a stale cached redirect targets a retained PDF,
visitors may see the old document; if retention already deleted that PDF, they
will normally receive a 404 until the cached redirect expires or is purged.

If the plugin files were removed before cleanup, reinstall and activate the
same plugin, then run **Maintenance audit** or **Full reset**. Ordinary plugin
deletion preserves its settings and relationship metadata, so these records
remain discoverable. If someone manually altered the database or moved files
outside WordPress, detection may be incomplete; unknown files are intentionally
reported for manual review rather than guessed at and deleted.

## Privacy and indexing

The PDFs are public static files. This plugin is not an access-control or
private-document system.

The redirect endpoint sends `X-Robots-Tag: noindex, nofollow`, but the final
static PDF may still be indexed if a crawler discovers its direct URL. Preventing
indexing of the static files requires web-server/CDN headers or authentication.

## Backup and recovery

Permanent deletions may still exist in host backups, external backups, or CDN
caches. Keep a tested backup before bulk cleanup. To restore a retained version,
use **Make current**. To restore a version already deleted from WordPress,
recover it from backup and upload it as a new version.
