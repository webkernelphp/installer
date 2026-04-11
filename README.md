<div align="center">
  <img src="https://raw.githubusercontent.com/numerimondes/.github/refs/heads/main/assets/brands/webkernel/logo-dark.png" height="48" alt="Webkernel"/>
  <br/>
  <sub>by <img src="https://raw.githubusercontent.com/numerimondes/.github/refs/heads/main/assets/brands/numerimondes/MARS2026_REBRAND/logo-officiel.png" height="14" alt="Numerimondes"/></sub>
  <br/><br/>
  <strong>Install Webkernel on your server — no terminal required.</strong>
  <br/><br/>
  <a href="https://webkernelphp.com/download">⬇ Download</a> &nbsp;·&nbsp;
  <a href="https://webkernelphp.com/docs">📖 Documentation</a> &nbsp;·&nbsp;
  <a href="https://github.com/webkernelphp/webkernel">GitHub</a>
</div>

---

## What is this?

One file. Drop it on your server. Open your browser. Webkernel is installed.

No command line. No Git. No Composer knowledge needed. The installer handles everything.

---

## How to install Webkernel

### In your browser (recommended)

1. **[Download index.php](https://webkernelphp.com/download)** and upload it to your server's web root or `public/` folder.
2. Open your domain: `https://yourdomain.com`
3. Navigate to: `https://yourdomain.com/fresh-install`
4. Follow the steps on screen.
5. **Delete `index.php` when the installer tells you to.** Security matters.

> The installer creates its own `.htaccess` routing file automatically on first visit. Nothing to configure.

### In the terminal

```bash
php index.php

# Install into a specific folder:
php index.php --dir /var/www/mysite
```

---

## What does my server need?

| Requirement | Version |
|---|---|
| PHP | **8.4 or newer** |
| PHP extensions | json, zip, openssl, curl, mbstring, pdo, tokenizer |
| Disk space | ~500 MB |
| Composer | Downloaded automatically if missing |

Not sure? The installer checks your environment and shows you exactly what's missing — before touching anything.

---

## Good to know

- **Close the tab mid-install?** No problem. Progress is saved on the server. Come back and refresh — it picks up where it left off.
- **Multiple attempts?** All previous sessions are listed in the sidebar. Resume any of them or start fresh.
- **Password protection?** Set a password from the installer UI so nobody else can access it while it's live. A one-time recovery key is shown when you set it — keep it somewhere safe.

---

## Something went wrong?

| Problem | Fix |
|---|---|
| 404 on `/fresh-install` | Apache `mod_rewrite` may be disabled. Ask your host to enable it, or check your Nginx config. |
| Composer times out | Use the terminal method — no time limit applies. |
| Forgot installer password | Click *Forgot password? Use recovery key* at the lock screen. |
| Memory error | Add `memory_limit = 512M` to `php.ini` |

Still stuck? [Open an issue on GitHub](https://github.com/webkernelphp/webkernel).

---

## Developer docs

See **[README.dev.md](README.dev.md)** for architecture, session internals, Composer integration, security implementation, and theming.
