<!--
    Generated from src/Modules/Views.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Views

Reads from `resources/views/` &nbsp;·&nbsp; Dependencies [`path`](../path/)

Resolves and renders PHP view templates from the plugin directory.

A view is an ordinary PHP file under `resources/views/`. Each key in the data array becomes a local variable inside the template. Only names beginning `__include_` are reserved — the render scope holds two of them and nothing else — so every ordinary key reaches the template, `view` and `data` included.

The `.php` extension is optional, and a name may address a subdirectory, so `'emails/receipt'` and `'emails/receipt.php'` resolve to the same file. A name that escapes the views root is rejected.

[Adding it](#adding-it) &nbsp;·&nbsp; [Rendering a view](#rendering-a-view) &nbsp;·&nbsp; [Writing a template](#writing-a-template) &nbsp;·&nbsp; [Rendering an admin page](#rendering-an-admin-page) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add views
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `Views` does nothing until something asks, so it goes at the top level — which `wp zt add` writes for you. Left out, the first `with( Views::class )` throws rather than building it, which [`wp zt doctor`](../../commands/doctor.md) catches before a request does.

```php
// bootstrap.php
return array(
    Views::class,
);
```

## Rendering a view

`render()` echoes the template; `get()` returns it as a string.

```php
$views = $plugin->get( Views::class );

// Echoes resources/views/emails/receipt.php, with $order and $total in scope:
$views->render( 'emails/receipt', array(
    'order' => $order,
    'total' => $total,
) );

// Same, but returns the markup instead of echoing it:
$html = $views->get( 'emails/receipt', array( 'order' => $order ) );
```

## Writing a template

The template is plain PHP, with the passed data as local variables. Inside one, `$this` is this module, so a template renders a subview with the same `render()` everything else uses — and it costs no variable name to do it.

```php
<!-- resources/views/emails/receipt.php -->
<h1><?php echo esc_html( $order->title ); ?></h1>
<?php $this->render( 'emails/-lines', array( 'lines' => $order->lines ) ); ?>

A template is included rather than called, so nothing tells your editor what
is in scope. Say so at the top and you get completion for all of it,
`$this` included -- which is what the generated templates do:

@var \Acme\Plugin\Core\Modules\Views $this
@var string                             $title
```

## Rendering an admin page

This is the case most plugins reach for first, and it has a shortcut: an `AdminPage` calls `$this->view()` rather than resolving this module. `wp zt make page` writes both files, and the template gets exactly what the `render()` call names — nothing of the page itself, so its inputs are readable without opening it.

```php
// resources/admin-pages/settings.php
public function render(): void {
    $this->view( 'admin-pages/settings', array( 'items' => $this->items ) );
}
```

## Changing the defaults

`Views` takes no configuration. The entry above is all it needs — reach it with `$this->with( Views::class )` from any module or discovered file, or `$plugin->get( Views::class )` from your entry file.

## Constants

### `VIEWS_ROOT`

```php
const VIEWS_ROOT = 'resources/views';
```

Default plugin-relative directory of view files.

## Methods

### `render( $view, $data )`

Render a view directly to the current output stream.

```php
public function render( string $view, array $data = array() ): void
```

|  | Details |
|---|---|
| **Parameters** | `$view` — The view name<br>`$data` — Variables made available to the view |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When the views root or the view is missing, or the view resolves outside the root |

<br>

### `get( $view, $data )`

Render a view and return its output as a string.

```php
public function get( string $view, array $data = array() ): string
```

|  | Details |
|---|---|
| **Parameters** | `$view` — Logical view name<br>`$data` — Variables made available to the view |
| **Return** | Rendered template output |
| **Throws** | `InvalidArgumentException` — When the views root or the view is missing, or the view resolves outside the root |

Each key in `$data` becomes a template variable. For example, `get( 'card', array( 'title' => 'Hello' ) )` makes `$title` available to `resources/views/card.php`. Escape the data in the template according to context.

The including is `Path::include_file()`, which is also what reserves the names: only keys beginning `__include_` are, and every ordinary name reaches the template, `view` and `data` included. Rendering a subview costs no name at all, since a template reaches this module as `$this`.

<br>

### `on_wp_init( $callback, $priority )`

*Inherited from [`Module`](../module.md).*

Run a callback on `init`, or immediately if `init` has already fired.

```php
final public function on_wp_init( callable $callback, int $priority = 10 ): void
```

|  | Details |
|---|---|
| **Parameters** | `$callback` — What to run<br>`$priority` — WordPress hook priority, honoured only while `init` is still ahead |
| **Return** | — |
| **Throws** | — |

<br>

### `get_plugin()`

*Inherited from [`WithPlugin`](../../kernel/with-plugin.md).*

Get the plugin this class belongs to.

```php
final public function get_plugin(): Plugin
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The plugin instance |
| **Throws** | — |

<br>

### `with( $name )`

*Inherited from [`WithPlugin`](../../kernel/with-plugin.md).*

Reach another module.

```php
final public function with( string $name ): object
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The module class to reach |
| **Return** | The shared instance |
| **Throws** | `ModuleException` — If it is not declared, or has not booted yet |

## See also

- [`path`](../path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add views`](../../commands/add.md) — the command that copies it
