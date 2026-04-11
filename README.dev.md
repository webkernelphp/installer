# Webkernel Installer — Developer Notes

Single-file PHP 8.4 installer for [webkernel/webkernel](https://packagist.org/packages/webkernel/webkernel).

---

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.4+ |
| Extensions | `json`, `zip`, `openssl`, `curl`, `mbstring`, `pdo`, `tokenizer` |
| Functions | `proc_open`, `random_bytes`, `openssl_random_pseudo_bytes` |
| Composer | Auto-downloaded if not present |

---

## Routing

`index.php` serves two routes:

| Path | Behaviour |
|---|---|
| `/` | Landing page |
| `/fresh-install` or `/install` | Installer UI |

`.htaccess` is created automatically on first HTTP request (Apache). For Nginx: `try_files $uri $uri/ /index.php?$query_string`.

When `index.php` lives in a `public/` (or `public_html`, `htdocs`, `www`, `web`) subdirectory, `resolveTargetDirectory()` automatically walks up one level so Webkernel installs into the project root, not inside `public/`.

---

## Installation stages

| # | Stage | What happens |
|---|---|---|
| 1 | **Preparation** | PHP version, extensions, directory permissions, Composer detection |
| 2 | **Composer bootstrap** | Downloads `composer.phar` if no system Composer 2.x found |
| 3 | **Download** | `composer create-project webkernel/webkernel` into staging, then moves to target |
| 4 | **Verification** | Checks `composer.json`, `vendor/autoload.php`, `artisan` |
| 5 | **Configuration** | Creates `.env`, runs `artisan package:discover` + `artisan key:generate`, touches SQLite db |

---

## Session persistence

State is stored in `~/webkernel/installer/<sha256-of-target-path>/`:

```
├── sessions/<session-id>/state.json
├── staging-<session-id-prefix>/     ← cleaned after install
├── composer.phar                     ← cached
├── composer-home/                    ← Composer cache
├── access.hash                       ← Argon2id password hash
├── access.session                    ← authenticated session token
└── recovery.key                      ← bcrypt recovery key hash
```

---

## Security

- Password stored as Argon2id hash (`access.hash`)
- Recovery key: `XXXXXX-XXXXXX-XXXXXX-XXXXXX`, stored as bcrypt hash
- SSR gate: unauthenticated clients receive only the lock screen — zero app HTML is sent
- `SafeProcessRunner` uses `proc_open` exclusively — no `shell_exec`, `exec`, `passthru`

---

## Composer integration

The installer passes `WEBKERNEL_INSTALLER_MODE=1` to Composer during `create-project`. `bootstrap/webkernel/installer-guard.php` in the Webkernel repo reads this to skip `post-autoload-dump` scripts that would fail before `bootstrap/app.php` exists.

```json
"post-autoload-dump": [
    "Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
    "@php bootstrap/webkernel/installer-guard.php package:discover"
]
```

Guard order: `WEBKERNEL_INSTALLER_MODE=1` set → skip. `bootstrap/app.php` missing → skip. `.env` missing → skip. All clear → run `artisan package:discover`.

---

## Architecture

**PHP backend**
- Immutable value objects: `InstallPath`, `SecurityToken`, `StageResult`, `ProcessResult`, `InstallerTheme`
- Enums: `InstallerPhase`, `StageStatus`, `ComposerState`
- Stage pipeline: `PreflightStage` → `ComposerBootstrapStage` → `DownloadStage` → `VerifyStage` → `ConfigureStage`
- `FilesystemSessionStorage`: JSON sessions, looked up by glob
- `AccessGate`: Argon2id + bcrypt, SSR-gated lock screen

**JS frontend**
- Vanilla JS, no framework, no CDN
- Single IIFE `wk` module
- Theme: inline blocking `<script>` in `<head>` — no flash

---

## Theming

Edit `InstallerTheme::defaults()` in `index.php`. Dark and light palettes inject server-side into CSS `:root` and `html.light` — no JS involved.

---

## Troubleshooting

**`bootstrap/app.php` not found during install** — ensure `installer-guard.php` is present in the Webkernel repo.

**Composer timeout** — use CLI mode; no web timeout applies.

**Memory error** — add `memory_limit = 512M` to `php.ini` or run `php -d memory_limit=512M index.php`.

**Orphan staging dirs** — `~/webkernel/installer/<hash>/staging-*` — cleaned automatically on next download attempt, or remove manually.
