# Shipping

The toolkit is not in your zip. `wp zt init` copied its kernel into `lib/Core/Kernel/` under your own namespace, and every module you added since landed beside it — that is your source now, and it ships the way the rest of your source does. What you build a release from is the plugin directory you have been working in, minus the tooling.

## The package is a dev dependency

```bash
composer require zestry-dev/wp-toolkit --dev
```

After `init`, nothing in your plugin's runtime references `Zestry\WPToolkit\`: the copy was rewritten to your namespace on the way in. What stays in `vendor/zestry-dev/wp-toolkit` is the `wp zt` commands themselves — `init`, `add`, `make`, `describe`, `doctor`, `overwrite`, `update` — and those are development tools, in the same category as phpcs.

The practical consequence: **`wp zt` disappears from a `--no-dev` install.** Run it while you are working, not while you are packaging.

## You still need `vendor/autoload.php`

This is the question the copy model most often gets wrong, so: **yes, your plugin needs a Composer autoloader at runtime, and `vendor/` ships.**

`init` added a PSR-4 entry to *your* `composer.json`:

```json
"autoload": {
    "psr-4": { "Acme\\Plugin\\": "lib/" }
}
```

That entry is what finds `Acme\Plugin\Core\Kernel\Plugin` and every module under it. Your entry file's `require_once __DIR__ . '/vendor/autoload.php';` is loading your own classes, not the toolkit's.

Build the shipping `vendor/` with:

```bash
composer install --no-dev --optimize-autoloader
```

What you get is Composer's generated autoloader — `vendor/autoload.php` and `vendor/composer/` — plus any runtime packages you require yourself. `zestry-dev/wp-toolkit` is not among them, and neither is phpcs, PHPUnit or anything else under `require-dev`. If your plugin has no runtime dependencies at all, `vendor/` is just the autoloader, and it still has to be in the zip.

> [!IMPORTANT]
> Run `composer install --no-dev` into a build copy, or run `composer install` again afterwards. It deletes your dev dependencies from the working tree, and `wp zt` along with them.

## What is committed, and what ships

They are not the same list, and the difference is what makes `git archive` the wrong packaging tool.

| Path | Committed | In the zip |
|---|---|---|
| `acme-plugin.php`, `bootstrap.php` | yes | **yes** — `bootstrap.php` is what builds your modules |
| `lib/` | yes | **yes** — your kernel, modules and services |
| `src/blocks/` | yes | no — source for the JS build |
| `build/` | no (gitignored) | **yes** — what the Blocks module reads |
| `vendor/` | no (gitignored) | **yes** — built with `--no-dev` |
| `node_modules/` | no | no |
| `zestry.json`, `zestry.lock.json` | yes | optional — nothing reads them at runtime |
| `tests/`, `phpcs.xml`, `eslint.config.mjs`, `package.json` | yes | no |

`init` writes a `.gitignore` covering `build/`, `vendor/`, `node_modules/`, `.DS_Store` and `*.log`, adding only what is missing — an existing `.gitignore` keeps everything already in it. Two of those entries are exactly the directories that must be present in the zip and absent from the repository, because both are reproducible from a lockfile or a build.

### `zestry.json` and `zestry.lock.json`

Both are development records, and both belong in the repository.

- **`zestry.json`** holds the three answers you gave `init` — namespace, text domain, source root. Every later `wp zt` command reads it.
- **`zestry.lock.json`** holds a hash per copied file, taken as the file was written. That is what lets `wp zt update` tell an edit of yours from a change upstream, rather than reporting one indistinguishable difference. Commit it for the same reason you commit `composer.lock`: a manifest that is not in the repository tells a colleague's checkout nothing.

Neither is loaded by your plugin at runtime. Leaving them in the zip is harmless; excluding them costs nothing either.

## The JavaScript build

If you have used `wp zt make block`, there are two directories and only one of them ships.

- **`src/blocks/{name}/`** is what you author and commit — `block.json`, scripts, styles, and the optional `block.php`.
- **`build/blocks/`** is what `npm run build` compiles, and it is the directory the [`Blocks`](modules/blocks/) module walks. A block that has never been built registers nothing.
- **`src/shared/{name}/`** is the same split for shared code: you author and commit it, [`npm run build`](javascript.md) compiles it to **`build/shared/`**, and the [`assets`](modules/assets/) module registers what it finds there.

```bash
npm install
npm run build
```

`wp zt add module blocks` wrote that script as `wp-scripts build --webpack-copy-php --experimental-modules --blocks-manifest`, so the build also copies each block's PHP into `build/` and writes a `blocks-manifest.php` the module reads in place of one `block.json` decode per block. **Build before packaging, every time** — `build/` is gitignored, so a fresh clone has nothing there at all.

The npm packages are all dev dependencies. `wp-scripts` maps every `@wordpress/*` import onto the `wp.*` globals WordPress already enqueues, so none of them end up in the bundle and `node_modules/` never ships.

### Making the zip

`wp zt add module blocks` also added `npm run plugin-zip`, which wraps `wp-scripts plugin-zip`. It is usable, with one thing to set up first.

Without a `files` field in your `package.json`, it falls back to a fixed list of Plugin Handbook directories — `admin/`, `build/`, `includes/`, `languages/`, `public/`, `{name}.php` and a few root files. **`lib/`, `vendor/` and `bootstrap.php` are not on that list**, so the zip it produces will be missing your entire plugin. Declare what ships:

```json
{
    "name": "acme-plugin",
    "files": [
        "acme-plugin.php",
        "bootstrap.php",
        "lib/",
        "build/",
        "vendor/",
        "languages/",
        "readme.txt"
    ]
}
```

The archive's root folder is named after `package.json`'s `name`, so keep that equal to your plugin's directory slug — WordPress resolves a plugin by its directory, and a mismatch installs your plugin under the wrong one.

A plugin that never added the `blocks` module has no npm tooling at all, and nothing here needs it: `zip` over a clean build copy does the same job.

## Updating the toolkit in a shipped plugin

A later release of the toolkit never arrives on its own. `wp zt update` re-copies everything under `lib/Core/` — the kernel, plus every module and service you have added — from whichever version of `zestry-dev/wp-toolkit` is currently installed. Nothing outside `lib/Core/` is touched.

```bash
composer update zestry-dev/wp-toolkit
wp zt update --dry-run
```

Every file comes back as `unchanged`, `upstream`, `missing`, `edited` or `conflict`; the first three are replaced or written back, and the last two are kept unless you pass `--force`. [Troubleshooting](troubleshooting.md#wp-zt-update-and-your-edits) has the full table.

Run `--dry-run` first, always — it reports and writes nothing. Then do the real run on a branch, with your [test suite](testing.md) as the check, exactly as you would any other code change. It is your code that just changed.

## Pre-release checklist

```bash
composer lint                                    # phpcs, if init set it up
npm run lint:js                                  # eslint, if init set it up
npm run test:php                                 # your suite, green
npm run build                                    # build/ from src/blocks/
wp zt doctor                                     # wiring that fails silently
```

Plus the things no command can check for you:

- **Bump `Version:`** in the entry file header. `$plugin->get_version()` reads it straight from there, so anything of yours keyed to a version follows automatically.
- **Migrations do not run themselves.** Adding one to `migrations/` ships a pending migration and nothing more — decide how the release triggers `run_pending()`, and see [Migrations](modules/migrations/) for why a hook is not it.

`wp zt doctor` comes last because it is the only check for a module that is on disk, correct, and never listed in `bootstrap.php` — which produces no error, just a missing feature — and because the `--no-dev` install in the next step takes it away.

Then build the release: `composer install --no-dev --optimize-autoloader`, and zip.

## Next

- [Testing](testing.md) — the suite the update step above leans on
- [`wp zt update`](commands/update.md) — every flag, and what each state reports
- [`wp zt doctor`](commands/doctor.md) — what it checks, and what it does not
