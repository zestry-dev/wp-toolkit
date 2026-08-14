<!--
    Generated from src/Modules/Request/UploadedFile.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# UploadedFile

[Storing one](#storing-one) &nbsp;·&nbsp; [Deciding for yourself](#deciding-for-yourself) &nbsp;·&nbsp; [Methods](#methods)

A file the request carried, as an object rather than five array keys.

Type an argument as one of these and the file arrives on the property:

```php
#[RequestArgument( 'The image to attach.' )]
public UploadedFile $image;

#[RequestArgument( 'Every page of the document.', of: UploadedFile::class )]
public array $pages;
```

**Only a route can take one.** An upload arrives as `multipart/form-data`, which is not JSON and has no place in a JSON Schema — so WordPress keeps uploads out of a request's parameters entirely, and an `Ability` declaring one is refused at registration rather than left waiting for input that can never come.

A file is therefore not validated against a schema the way every other argument is: it is absent from the route's published `args`, and all the checking there is to do is yours.

## Storing one

`store()` does the checking, so a route that just wants the file kept has three lines and no WordPress trivia in them.

```php
public function handle( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
    $stored = $this->image->store();

    if ( is_wp_error( $stored ) ) {
        return $stored;
    }

    return new WP_REST_Response( array( 'url' => $stored['url'] ) );
}
```

## Deciding for yourself

```php
if ( ! $this->image->is_ok() ) {
    return new \WP_Error( 'acme_no_image', $this->image->get_error_message(), array( 'status' => 400 ) );
}

if ( $this->image->size > 5 * MB_IN_BYTES ) {
    return new \WP_Error( 'acme_image_too_large', __( 'Images must be under 5 MB.', 'acme-plugin' ), array( 'status' => 400 ) );
}

$stored = $this->image->store( array( 'mimes' => array( 'png' => 'image/png' ) ) );
```

## Methods

### `__construct( $name, $type, $tmp_name, $error, $size )`

@param string $name     The name the file had on the sender's machine. @param string $type     The media type the sender claimed, which is not evidence of anything. @param string $tmp_name Where PHP put it, valid only until this request ends. @param int    $error    One of PHP's `UPLOAD_ERR_*` constants. @param int    $size     Its size in bytes.

```php
public function __construct( public readonly string $name, public readonly string $type, public readonly string $tmp_name, public readonly int $error, public readonly int $size )
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The name the file had on the sender's machine<br>`$type` — The media type the sender claimed, which is not evidence of anything<br>`$tmp_name` — Where PHP put it, valid only until this request ends<br>`$error` — One of PHP's `UPLOAD_ERR_*` constants<br>`$size` — Its size in bytes |
| **Return** | — |
| **Throws** | — |

<br>

### `store( $overrides )`

Move the file into the uploads directory.

```php
public function store( array $overrides = array() )
```

|  | Details |
|---|---|
| **Parameters** | `$overrides` — Options for `wp_handle_upload()` |
| **Return** | — |
| **Throws** | — |

Everything WordPress needs to be told for this to work from a REST request, told for you: the upload functions live in `wp-admin`, which a REST request has not loaded, and `wp_handle_upload()` otherwise looks for a form field REST never sends and refuses the file for missing it. `is_ok()` is checked first, so a file that never arrived comes back as an error rather than a confusing one from deeper down.

Returns WordPress's own array on success — `file` (the absolute path), `url` and `type` — or a `WP_Error` carrying the status to answer with. Both error codes are core's own, so a client written against the media endpoints handles yours the same way.

```php
$stored = $this->image->store();

if ( is_wp_error( $stored ) ) {
    return $stored;
}
```

`$overrides` is passed to `wp_handle_upload()`, so `mimes` narrows what is accepted and `unique_filename_callback` names the result. `test_form` is always false, whatever you pass.

This stores the file. Adding it to the media library is a second step: `wp_insert_attachment()` with the path this returns.

<br>

### `is_ok()`

Whether the file actually arrived.

```php
public function is_ok(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

A request can carry a file that did not: too large for the server, cut off part way, nowhere to write it. Ask before reading it.

<br>

### `get_error_message()`

Why the file did not arrive, in a sentence you can show someone.

```php
public function get_error_message(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Empty when it did arrive |
| **Throws** | — |

<br>

### `to_array()`

The five keys back in the shape WordPress's own upload handling expects.

```php
public function to_array(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

`wp_handle_upload()` and `media_handle_sideload()` both take this array.
