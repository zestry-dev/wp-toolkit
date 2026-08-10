<?php

/**
 * Abilities API: Effect enum
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Abilities;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * What running an ability does to the site.
 *
 * This is the one piece of metadata a caller acts on rather than reads. WordPress
 * turns it into the HTTP method the REST endpoint demands — and returns `405` for
 * any other — while an AI agent uses it to decide whether your ability can be
 * called without asking its user first, and whether a retry after a timeout is
 * safe.
 *
 * | Effect   | What it means                        | `/run` accepts |
 * |----------|--------------------------------------|----------------|
 * | `Read`   | Changes nothing                      | `GET`          |
 * | `Create` | Adds something new each time         | `POST`         |
 * | `Update` | Sets something; twice is once        | `POST`         |
 * | `Delete` | Removes something                    | `DELETE`       |
 *
 * `Create` and `Update` differ only in whether calling twice does the job twice.
 * "Publish a post" is a `Create`; "set the site title" is an `Update`.
 *
 * The method also decides where the input travels, and it is a single `input`
 * parameter either way -- never top-level arguments. A `GET` or `DELETE` carries
 * it in the query string as `?input[order_id]=42&input[notify]=1`; a `POST`
 * carries it in the JSON body as `{ "input": { "order_id": 42 } }`. A JSON string
 * in the query string arrives as a string and is refused for not being an object.
 */
enum Effect: string {

	case Read   = 'read';
	case Create = 'create';
	case Update = 'update';
	case Delete = 'delete';

	/**
	 * The annotations WordPress records for this effect.
	 *
	 * All three are stated for every case, never left null. WordPress reads an
	 * unstated annotation as false, so silence is not neutral — it is the same
	 * answer as "no", and would quietly make a read-only ability `POST`-only.
	 *
	 * @return array<string, bool>
	 *
	 * @internal
	 */
	public function get_annotations(): array {
		return array(
			'readonly'    => self::Read === $this,
			'destructive' => self::Delete === $this,
			'idempotent'  => self::Create !== $this,
		);
	}

	/**
	 * The HTTP method `wp-abilities/v1/abilities/{ability}/run` will accept.
	 *
	 * WordPress derives this from the annotations above and rejects every other
	 * method with `405`, so this is what a client has to send.
	 *
	 * @return string
	 */
	public function get_rest_method(): string {
		return match ( $this ) {
			self::Read   => 'GET',
			self::Delete => 'DELETE',
			default      => 'POST',
		};
	}
}
