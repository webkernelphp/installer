# Webkernel Installer

**Single-file installer for the [Webkernel](https://github.com/webkernelphp/webkernel) framework.**

> License: Webkernel Unified License + EPL Eclipse v2

---

## Overview

`install.php` downloads and configures `webkernel/webkernel` via Composer into a target directory. It runs in both **HTTP (browser)** and **CLI** modes from the same file, with no code duplication between the two paths.

Sessions are persisted to disk in your home directory. You can close your browser tab mid-install and resume without losing progress. The server tracks state — not the browser.

---

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.4+ |
| Extensions | `json`, `zip`, `openssl`, `curl`, `mbstring`, `pdo`, `tokenizer` |
| Composer | Auto-downloaded if not present |
| Disk | ~500 MB for Webkernel + dependencies |

---

## Installation

### HTTP (browser)

1. Copy `install.php` into the directory where you want to install Webkernel.
2. Serve the directory with PHP:
   ```bash
   php -S localhost:9000
   ```
3. Open `http://localhost:9000/install.php` in your browser.
4. Follow the installation steps.
5. **Delete `install.php` immediately after installation.**

### CLI

```bash
php install.php
```

To install into a specific directory:

```bash
php install.php --dir /path/to/target
```

The CLI runner prints `rm` with the exact path at the end — run it to remove the installer.

---

## Installation stages

| # | Stage | What happens |
|---|---|---|
| 1 | **Preparation** | Checks PHP version, required extensions, directory permissions, and detects Composer |
| 2 | **Bootstrapping Composer** | Downloads `composer.phar` if no system Composer 2.x is found |
| 3 | **Download** | Runs `composer create-project webkernel/webkernel` into a staging directory, then moves files into the target |
| 4 | **Verification** | Confirms `composer.json`, `vendor/autoload.php`, and `artisan` are present |
| 5 | **Configuration** | Creates `.env` from `.env.example`, runs `artisan package:discover` and `artisan key:generate`, touches `database/database.sqlite` |

---

## Session persistence

The installer stores state in your home directory, keyed by a SHA-256 hash of the target path:

```
~/webkernel/installer/<sha256-of-target-path>/
├── sessions/
│   └── <session-id>/
│       └── state.json
├── staging-<session-id-prefix>/   ← temporary, cleaned after install
├── composer.phar                  ← cached if downloaded
├── composer-home/                 ← Composer cache
├── access.hash                    ← Argon2id hash of installer password (if set)
├── access.session                 ← session token for authenticated access
└── recovery.key                   ← bcrypt hash of recovery key (if set)
```

**Resuming:** If the install is interrupted (timeout, closed tab, server restart), reload the page or re-run `php install.php`. The installer detects the existing session and resumes from where it left off. If Composer had already finished downloading, the move step picks up without re-downloading.

**Multiple sessions:** All sessions for a given target are listed in the sidebar. You can switch between them, resume any, or delete old ones individually or in bulk.

---

## Security (optional)

The installer can be password-protected to prevent unauthorized access while it is publicly reachable.

### Setting a password

Click **Security** in the sidebar (or the lock panel on the welcome screen) to open the security modal. After entering and confirming a password:

1. A **one-time recovery key** is displayed. **Save it immediately** — it is shown only once.
2. The page reloads. You are automatically authenticated.
3. Future visitors see only a lock screen — no installer HTML is sent by the server to unauthenticated clients (SSR gate).

### Recovery key

If you lose the password, use the recovery key at the lock screen prompt. This removes password protection entirely and reloads the page.

The recovery key format is `XXXXXX-XXXXXX-XXXXXX-XXXXXX`. Store it in a password manager or a printed note.

### Removing protection

While authenticated, open the Security modal and click **Remove password**.

---

## Codenames

Webkernel releases follow a codename series by major version:

| Major | Codename |
|---|---|
| 1.x | **Waterfall** |
| 2.x | **Greenfields** |
| 3.x | **Horizon** |
| 4.x | **Stonebridge** |
| 5.x | **Evergreen** |

The codename is detected automatically from `composer.lock` after installation and displayed in the topbar and on the success screen.

---

## Customising the theme

Edit `InstallerTheme::defaults()` near the top of `install.php`. All values are typed PHP 8.4 readonly properties with inline comments referencing the Tailwind gray scale:

```php
final class InstallerTheme
{
    private function __construct(
        public readonly string $darkBg    = '#030712',  // gray-950
        public readonly string $primary   = '#2563eb',  // blue-600
        // ...
    ) {}
}
```

Dark and light palettes are injected server-side into the CSS `:root` and `html.light` blocks — no client-side JavaScript is involved in theming.

---

## Composer integration (for the Webkernel repo)

The installer passes `WEBKERNEL_INSTALLER_MODE=1` to Composer during `create-project`. This environment variable is read by `bootstrap/webkernel/installer-guard.php`, which short-circuits `post-autoload-dump` scripts (specifically `artisan package:discover`) that would otherwise fail because `bootstrap/app.php` does not exist yet in the staging directory.

`composer.json` in the Webkernel repository should use:

```json
"post-autoload-dump": [
    "Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
    "@php bootstrap/webkernel/installer-guard.php package:discover"
]
```

`installer-guard.php` performs three guards in order:

1. `WEBKERNEL_INSTALLER_MODE=1` is set → skip silently
2. `bootstrap/app.php` does not exist → skip silently
3. `.env` does not exist → skip silently
4. All guards passed → run `php artisan package:discover`

This makes `post-autoload-dump` safe for both `create-project` and normal `composer install`/`update` flows.

---

## Architecture

The installer is a single PHP 8.4 file. The backend and frontend are strictly separated:

**PHP backend**
- Immutable value objects: `InstallPath`, `SecurityToken`, `StageResult`, `ProcessResult`, `InstallerTheme`
- Enums: `InstallerPhase`, `StageStatus`, `ComposerState`
- Interfaces: `InstallerStageInterface`, `SessionStorageInterface`, `OutputInterface`
- Stage pipeline: `PreflightStage` → `ComposerBootstrapStage` → `DownloadStage` → `VerifyStage` → `ConfigureStage`
- `SafeProcessRunner`: uses `proc_open` exclusively — no `shell_exec`, `exec`, or `passthru`
- `FilesystemSessionStorage`: persists session state as JSON, looked up by glob across the userspace
- `AccessGate`: Argon2id password hashing, bcrypt recovery key, SSR gate (unauthenticated clients receive only the lock screen page — zero app HTML)

**JS frontend**
- Vanilla JS only — no framework, no CDN dependencies
- Single IIFE module `wk` with private state and a minimal public API
- `<details>`/`<summary>` native HTML for the sessions dropdown
- Theme applied by an inline blocking `<script>` in `<head>` before first paint — no flash

---

## File layout after install

```
your-directory/
├── app/
├── bootstrap/
│   └── webkernel/
│       └── installer-guard.php   ← must be present in the Webkernel repo
├── vendor/
├── .env
├── artisan
├── composer.json
├── composer.lock
└── ...
```

`install.php` is **not** copied into the project. Delete it from the web root immediately after installation.

---

## Troubleshooting

**`bootstrap/app.php` not found during install**
Ensure `bootstrap/webkernel/installer-guard.php` is present in the Webkernel repository and that `composer.json` references it in `post-autoload-dump`. The installer passes `WEBKERNEL_INSTALLER_MODE=1` automatically.

**Composer timeout**
The download stage allows 600 seconds. On slow connections, increase the PHP `max_execution_time` if running under a web server, or use CLI mode which has no web timeout.

**Locked out of the installer**
Use the recovery key at the lock screen. Click *Forgot password? Use recovery key →* and enter the key shown at password-setup time. This removes the password gate and reloads the page.

**Orphan staging directories**
If multiple install attempts were made, `~/webkernel/installer/<hash>/staging-*` directories may remain. The installer cleans up staging dirs from other sessions automatically on the next download attempt. They can also be removed manually.

**PHP memory limit**
Webkernel has many dependencies. If `composer install` fails with a memory error, add to `php.ini`:
```ini
memory_limit = 512M
```
or run:
```bash
php -d memory_limit=512M install.php
```
