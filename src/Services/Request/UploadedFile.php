<?php

/**
 * Request API: UploadedFile value
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Services\Request;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * A file the request carried, as an object rather than five array keys.
 *
 * Type an argument as one of these and the file arrives on the property:
 *
 *     #[RequestArgument( 'The image to attach.' )]
 *     public UploadedFile $image;
 *
 *     #[RequestArgument( 'Every page of the document.', of: UploadedFile::class )]
 *     public array $pages;
 *
 * **Only a route can take one.** An upload arrives as `multipart/form-data`,
 * which is not JSON and has no place in a JSON Schema — so WordPress keeps
 * uploads out of a request's parameters entirely, and an
 * {@see \Zestry\WPToolkit\Modules\Abilities\Ability} declaring one is refused at
 * registration rather than left waiting for input that can never come.
 *
 * A file is therefore not validated against a schema the way every other
 * argument is: it is absent from the route's published `args`, and all the
 * checking there is to do is yours.
 *
 * @api
 *
 * @example Storing one
 * {@see store()} does the checking, so a route that just wants the file kept
 * has three lines and no WordPress trivia in them.
 *
 * ```
 * public function handle( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
 *     $stored = $this->image->store();
 *
 *     if ( is_wp_error( $stored ) ) {
 *         return $stored;
 *     }
 *
 *     return new WP_REST_Response( array( 'url' => $stored['url'] ) );
 * }
 * ```
 *
 * @example Deciding for yourself
 * ```
 * if ( ! $this->image->is_ok() ) {
 *     return new \WP_Error( 'acme_no_image', $this->image->get_error_message(), array( 'status' => 400 ) );
 * }
 *
 * if ( $this->image->size > 5 * MB_IN_BYTES ) {
 *     return new \WP_Error( 'acme_image_too_large', __( 'Images must be under 5 MB.', 'acme-plugin' ), array( 'status' => 400 ) );
 * }
 *
 * $stored = $this->image->store( array( 'mimes' => array( 'png' => 'image/png' ) ) );
 * ```
 */
final class UploadedFile {

	/**
	 * @param string $name     The name the file had on the sender's machine.
	 * @param string $type     The media type the sender claimed, which is not evidence of anything.
	 * @param string $tmp_name Where PHP put it, valid only until this request ends.
	 * @param int    $error    One of PHP's `UPLOAD_ERR_*` constants.
	 * @param int    $size     Its size in bytes.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly string $tmp_name,
		public readonly int $error,
		public readonly int $size
	) {}

	/**
	 * Build one from a `$_FILES` entry.
	 *
	 * @param array<string, mixed> $file One entry of `WP_REST_Request::get_file_params()`.
	 * @return self
	 *
	 * @internal
	 */
	public static function from_array( array $file ): self {
		return new self(
			(string) ( $file['name'] ?? '' ),
			(string) ( $file['type'] ?? '' ),
			(string) ( $file['tmp_name'] ?? '' ),
			(int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ),
			(int) ( $file['size'] ?? 0 )
		);
	}

	/**
	 * Move the file into the uploads directory.
	 *
	 * Everything WordPress needs to be told for this to work from a REST
	 * request, told for you: the upload functions live in `wp-admin`, which a
	 * REST request has not loaded, and `wp_handle_upload()` otherwise looks for
	 * a form field REST never sends and refuses the file for missing it.
	 * {@see is_ok()} is checked first, so a file that never arrived comes back as
	 * an error rather than a confusing one from deeper down.
	 *
	 * Returns WordPress's own array on success — `file` (the absolute path),
	 * `url` and `type` — or a `WP_Error` carrying the status to answer with.
	 * Both error codes are core's own, so a client written against the media
	 * endpoints handles yours the same way.
	 *
	 *     $stored = $this->image->store();
	 *
	 *     if ( is_wp_error( $stored ) ) {
	 *         return $stored;
	 *     }
	 *
	 * `$overrides` is passed to `wp_handle_upload()`, so `mimes` narrows what is
	 * accepted and `unique_filename_callback` names the result. `test_form` is
	 * always false, whatever you pass.
	 *
	 * This stores the file. Adding it to the media library is a second step:
	 * `wp_insert_attachment()` with the path this returns.
	 *
	 * @param array<string, mixed> $overrides Options for `wp_handle_upload()`.
	 * @return array<string, string>|\WP_Error
	 */
	public function store( array $overrides = array() ) {
		if ( ! $this->is_ok() ) {
			return new \WP_Error(
				'rest_upload_no_data',
				$this->get_error_message(),
				array( 'status' => 400 )
			);
		}

		// wp_handle_upload() is an admin function, and a REST request loads no
		// admin. Core's own attachments controller does exactly this first.
		require_once ABSPATH . 'wp-admin/includes/file.php';

		// A variable, because wp_handle_upload() takes its file by reference and
		// PHP refuses to hand a method's return value to one.
		$file = $this->to_array();

		$stored = \wp_handle_upload(
			$file,
			// Last, so it cannot be overridden: test_form looks for a form field
			// that only a browser form submission carries.
			\array_merge( $overrides, array( 'test_form' => false ) )
		);

		if ( isset( $stored['error'] ) ) {
			return new \WP_Error(
				'rest_upload_unknown_error',
				$stored['error'],
				array( 'status' => 500 )
			);
		}

		return $stored;
	}

	/**
	 * Whether the file actually arrived.
	 *
	 * A request can carry a file that did not: too large for the server, cut off
	 * part way, nowhere to write it. Ask before reading it.
	 *
	 * @return bool
	 */
	public function is_ok(): bool {
		return UPLOAD_ERR_OK === $this->error;
	}

	/**
	 * Why the file did not arrive, in a sentence you can show someone.
	 *
	 * @return string Empty when it did arrive.
	 */
	public function get_error_message(): string {
		switch ( $this->error ) {
			case UPLOAD_ERR_OK:
				return '';
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return \__( 'The file is larger than this site accepts.', 'zestry-toolkit' );
			case UPLOAD_ERR_PARTIAL:
				return \__( 'The file was only partly uploaded. Try again.', 'zestry-toolkit' );
			case UPLOAD_ERR_NO_FILE:
				return \__( 'No file was uploaded.', 'zestry-toolkit' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return \__( 'The server could not store the file.', 'zestry-toolkit' );
			case UPLOAD_ERR_EXTENSION:
				return \__( 'The upload was stopped by the server.', 'zestry-toolkit' );
			default:
				return \__( 'The file could not be uploaded.', 'zestry-toolkit' );
		}
	}

	/**
	 * The five keys back in the shape WordPress's own upload handling expects.
	 *
	 * `wp_handle_upload()` and `media_handle_sideload()` both take this array.
	 *
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
	 */
	public function to_array(): array {
		return array(
			'name'     => $this->name,
			'type'     => $this->type,
			'tmp_name' => $this->tmp_name,
			'error'    => $this->error,
			'size'     => $this->size,
		);
	}

	/**
	 * Split a multi-file `$_FILES` entry into one array per file.
	 *
	 * PHP transposes them: `name` becomes a list, `tmp_name` becomes a list, and
	 * a single file is indistinguishable from a list of one until you look at
	 * whether the keys hold arrays. Nothing else in a request is shaped this way,
	 * which is why it is untangled here rather than left to a route.
	 *
	 * @param array<string, mixed> $file One entry of `WP_REST_Request::get_file_params()`.
	 * @return array<int, self> One per file, in the order they were sent.
	 *
	 * @internal
	 */
	public static function from_multiple( array $file ): array {
		if ( ! \is_array( $file['name'] ?? null ) ) {
			return array( self::from_array( $file ) );
		}

		$files = array();

		foreach ( \array_keys( $file['name'] ) as $index ) {
			$files[] = self::from_array(
				array(
					'name'     => $file['name'][ $index ] ?? '',
					'type'     => $file['type'][ $index ] ?? '',
					'tmp_name' => $file['tmp_name'][ $index ] ?? '',
					'error'    => $file['error'][ $index ] ?? UPLOAD_ERR_NO_FILE,
					'size'     => $file['size'][ $index ] ?? 0,
				)
			);
		}

		return $files;
	}
}
