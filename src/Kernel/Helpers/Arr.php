<?php

/**
 * Core API: Arr
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Helpers;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;


/**
 * The array operations you would otherwise write out at every use.
 *
 * Reaching into a nested array without four `isset()`s, taking only the keys you
 * accept, plucking one field off a list of rows. If you have used Laravel's
 * `Arr`, these are the same names doing the same things.
 *
 * Static, and not a {@see \Zestry\WPToolkit\Kernel\Abstracts\Module}, because there is
 * nothing here to configure or inject: every method is a pure function of its
 * arguments.
 *
 * @example Reading a nested value
 * The path is the point. Each of these is one line instead of a chain of
 * `isset()` calls, and each returns the fallback rather than a warning when a
 * step along the way is missing.
 *
 * ```
 * use Acme\Plugin\Core\Kernel\Helpers\Arr;
 *
 * $city = Arr::get( $order, 'billing.address.city', '' );
 * $rate = Arr::get( $settings, array( 'tax', 'rates', 0 ), 0.0 );
 *
 * if ( Arr::has( $request, 'meta.consent' ) ) {
 *     // Present, even when its value is null -- which `get() !== null` cannot tell you.
 * }
 *
 * Arr::set( $settings, 'mail.from.name', 'Acme' );
 * ```
 *
 * A key with a dot in it still works, because the whole path is tried as a
 * literal key before it is split: `get( $data, 'acme.version' )` finds a
 * top-level `'acme.version'` if that is what you have.
 *
 * @example Shaping a list
 * ```
 * $emails = Arr::pluck( $orders, 'billing.email' );
 * $by_id  = Arr::pluck( $orders, 'total', 'id' );      // keyed
 *
 * $safe   = Arr::only( $_POST, array( 'name', 'email' ) );
 * $rest   = Arr::except( $attributes, array( 'className' ) );
 *
 * // A value that may arrive as one thing or several, which WordPress does often.
 * foreach ( Arr::wrap( $post_types ) as $post_type ) { ... }
 * ```
 *
 * `pluck()` takes a dotted path where Laravel's takes a plain key, so it reads a
 * nested field without a second call.
 */
final class Arr {

	/**
	 * Read a value from a nested array by path.
	 *
	 * @param array<array-key, mixed>       $data    The array to read from.
	 * @param string|array<int, string|int> $path    A dotted path, or its segments.
	 * @param mixed                         $fallback Returned when the path does not resolve.
	 * @return mixed The value found, or `$fallback`.
	 */
	public static function get( array $data, string|array $path, mixed $fallback = null ): mixed {
		$cursor = $data;

		foreach ( self::get_path_segments( $data, $path ) as $segment ) {
			if ( ! \is_array( $cursor ) || ! \array_key_exists( $segment, $cursor ) ) {
				return $fallback;
			}

			$cursor = $cursor[ $segment ];
		}

		return $cursor;
	}

	/**
	 * Whether a path resolves, however the value at the end of it reads.
	 *
	 * Distinct from `null !== get( ... )`: a key that is present and holds
	 * `null` answers true here and false there. That difference is the reason
	 * this exists -- an unchecked box and an absent field are not the same
	 * thing, and only one of them means the form never had that field.
	 *
	 * @param array<array-key, mixed>       $data The array to look in.
	 * @param string|array<int, string|int> $path A dotted path, or its segments.
	 * @return bool
	 */
	public static function has( array $data, string|array $path ): bool {
		$cursor = $data;

		foreach ( self::get_path_segments( $data, $path ) as $segment ) {
			if ( ! \is_array( $cursor ) || ! \array_key_exists( $segment, $cursor ) ) {
				return false;
			}

			$cursor = $cursor[ $segment ];
		}

		return true;
	}

	/**
	 * Write a value into a nested array by path, creating what is missing.
	 *
	 * Takes the array by reference and returns nothing, so the call reads as the
	 * statement it is. A step that does not exist is created; a step that exists
	 * and is not an array is replaced, because the path you asked for is the one
	 * you get.
	 *
	 * @param array<array-key, mixed>       $data  The array to write into, by reference.
	 * @param string|array<int, string|int> $path  A dotted path, or its segments.
	 * @param mixed                         $value What to store at the end of it.
	 * @return void
	 */
	public static function set( array &$data, string|array $path, mixed $value ): void {
		$cursor = &$data;

		foreach ( self::split_path( $path ) as $segment ) {
			if ( ! isset( $cursor[ $segment ] ) || ! \is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = array();
			}

			$cursor = &$cursor[ $segment ];
		}

		$cursor = $value;
	}

	/**
	 * Remove a value from a nested array by path.
	 *
	 * A path that does not resolve is not an error: the array is already in the
	 * state you asked for.
	 *
	 * @param array<array-key, mixed>       $data The array to remove from, by reference.
	 * @param string|array<int, string|int> $path A dotted path, or its segments.
	 * @return void
	 */
	public static function forget( array &$data, string|array $path ): void {
		$segments = self::split_path( $path );
		$last     = \array_pop( $segments );

		if ( null === $last ) {
			return;
		}

		$cursor = &$data;

		foreach ( $segments as $segment ) {
			if ( ! isset( $cursor[ $segment ] ) || ! \is_array( $cursor[ $segment ] ) ) {
				return;
			}

			$cursor = &$cursor[ $segment ];
		}

		unset( $cursor[ $last ] );
	}

	/**
	 * Keep only the named keys.
	 *
	 * The shape to reach for before writing request input anywhere: it states
	 * what you accept, rather than removing what you happened to think of.
	 *
	 * @param array<array-key, mixed>  $data The array to filter.
	 * @param array<int, string|int>   $keys The keys to keep.
	 * @return array<array-key, mixed>
	 */
	public static function only( array $data, array $keys ): array {
		return \array_intersect_key( $data, \array_flip( $keys ) );
	}

	/**
	 * Drop the named keys, keeping everything else.
	 *
	 * @param array<array-key, mixed> $data The array to filter.
	 * @param array<int, string|int>  $keys The keys to remove.
	 * @return array<array-key, mixed>
	 */
	public static function except( array $data, array $keys ): array {
		return \array_diff_key( $data, \array_flip( $keys ) );
	}

	/**
	 * Collect one value out of every row.
	 *
	 * Both arguments take a path, so a nested value needs no loop of its own.
	 * Give `$key` to key the result by another of the row's values.
	 *
	 * ```
	 * Arr::pluck( $orders, 'billing.email' );
	 * Arr::pluck( $orders, 'total', 'id' );
	 * ```
	 *
	 * @param array<array-key, mixed>            $rows  The rows to read.
	 * @param string|array<int, string|int>      $value Path to the value wanted.
	 * @param string|array<int, string|int>|null $key   Path to key the result by, if any.
	 * @return array<array-key, mixed>
	 */
	public static function pluck( array $rows, string|array $value, string|array|null $key = null ): array {
		$plucked = array();

		foreach ( $rows as $row ) {
			if ( ! \is_array( $row ) ) {
				continue;
			}

			if ( null === $key ) {
				$plucked[] = self::get( $row, $value );

				continue;
			}

			$plucked[ self::get( $row, $key ) ] = self::get( $row, $value );
		}

		return $plucked;
	}

	/**
	 * The first value passing the test, or `$fallback` when none does.
	 *
	 * Without a callback, simply the first value -- which for a keyed array is
	 * not something `$data[0]` can tell you.
	 *
	 * @param array<array-key, mixed>                 $data     The array to search.
	 * @param callable(mixed, array-key): bool|null   $matches  The test, or null for the first of anything.
	 * @param mixed                                   $fallback  Returned when nothing matches.
	 * @return mixed
	 */
	public static function first( array $data, ?callable $matches = null, mixed $fallback = null ): mixed {
		foreach ( $data as $key => $value ) {
			if ( null === $matches || $matches( $value, $key ) ) {
				return $value;
			}
		}

		return $fallback;
	}

	/**
	 * The last value, optionally the last one matching a test.
	 *
	 * The mirror of {@see first()}, and reached for the same way: the end of a
	 * list of revisions, the most recent row.
	 *
	 * @param array<array-key, mixed> $data    The array to read.
	 * @param callable|null           $matches Optional test; the last value passing it wins.
	 * @param mixed                   $fallback Returned when nothing matches.
	 * @return mixed
	 */
	public static function last( array $data, ?callable $matches = null, mixed $fallback = null ): mixed {
		return self::first( \array_reverse( $data, true ), $matches, $fallback );
	}

	/**
	 * Whether this array is keyed by name rather than numbered.
	 *
	 * The question worth asking before deciding how to walk something WordPress
	 * handed you: a numbered list is looped, a keyed array is read by name.
	 *
	 * "Numbered" is PHP's `array_is_list()` — the keys `0, 1, 2…` in that order
	 * and nothing else — so anything a positional read would get wrong is
	 * associative here: keys with gaps in them, keys out of order, and the case
	 * that catches people out, **a map keyed by id**. PHP casts a numeric string
	 * key to an integer on the way in, so by the time you see
	 * `array( '1' => 'a', '7' => 'b' )` every key is an integer, and it is still
	 * something you read by name rather than loop by position.
	 *
	 * WordPress's own `wp_is_numeric_array()` asks something narrower — whether
	 * *any* key is a string — and so calls that same id-keyed map numeric. Reach
	 * for it directly when a string key is genuinely what you are asking about.
	 *
	 * An empty array is a list, and so is not associative.
	 *
	 * @param array<array-key, mixed> $data The array to test.
	 * @return bool
	 */
	public static function is_assoc( array $data ): bool {
		return ! \array_is_list( $data );
	}

	/**
	 * Replace values into a nested array, descending only into keyed maps.
	 *
	 * The merge for anything shaped like configuration: state the one value you
	 * are changing, at the depth it lives at, and everything beside it is left
	 * exactly as it was.
	 *
	 * ```
	 * $settings = Arr::replace_recursive(
	 *     array(
	 *         'mail'  => array( 'from' => array( 'name' => 'Acme', 'email' => 'no-reply@acme.test' ) ),
	 *         'roles' => array( 'editor', 'author' ),
	 *     ),
	 *     array(
	 *         'mail'  => array( 'from' => array( 'name' => 'Acme Support' ) ),
	 *         'roles' => array( 'editor' ),
	 *     )
	 * );
	 *
	 * // The `email` beside the renamed `name` survives, and `roles` is exactly
	 * // array( 'editor' ).
	 * ```
	 *
	 * PHP's own `array_replace_recursive()` is the same idea with one difference:
	 * it descends into **lists** as well, and replaces them by position.
	 * `array( 'editor' )` over `array( 'editor', 'author' )` leaves both there,
	 * because nothing replaced index 1 — so a value you meant to drop is still in
	 * the array, with nothing said about it. Here a list is a value, taken as
	 * written.
	 *
	 * "Keyed map" is {@see is_assoc()}, so a map keyed by id is descended into as
	 * readily as one keyed by name, and an empty array is a list — replacing with
	 * one empties that key rather than merging into nothing.
	 *
	 * Both sides have to be maps for either to be descended into, which is what
	 * keeps a real list safe from a replacement with holes in its keys:
	 * `array_filter()` leaves gaps, and its result stated over `array( 'a', 'b' )`
	 * is still taken whole.
	 *
	 * @param array<array-key, mixed> $data         The array to replace into.
	 * @param array<array-key, mixed> $replacements The values to state over it.
	 * @return array<array-key, mixed>
	 */
	public static function replace_recursive( array $data, array $replacements ): array {
		foreach ( $replacements as $key => $value ) {
			$descends = \is_array( $value )
				&& isset( $data[ $key ] )
				&& \is_array( $data[ $key ] )
				&& self::is_assoc( $value )
				&& self::is_assoc( $data[ $key ] );

			$data[ $key ] = $descends ? self::replace_recursive( $data[ $key ], $value ) : $value;
		}

		return $data;
	}

	/**
	 * Wrap a value in an array unless it already is one.
	 *
	 * WordPress hands you one thing or several with the same argument name all
	 * over its API, and `null` means none rather than one null.
	 *
	 * @param mixed $value Anything.
	 * @return array<array-key, mixed>
	 */
	public static function wrap( mixed $value ): array {
		if ( null === $value ) {
			return array();
		}

		return \is_array( $value ) ? $value : array( $value );
	}

	/**
	 * Flatten nested arrays into one level.
	 *
	 * @param array<array-key, mixed> $data  The array to flatten.
	 * @param int                     $depth How many levels to descend; default all of them.
	 * @return array<int, mixed>
	 */
	public static function flatten( array $data, int $depth = PHP_INT_MAX ): array {
		$flat = array();

		foreach ( $data as $value ) {
			if ( ! \is_array( $value ) || $depth < 1 ) {
				$flat[] = $value;

				continue;
			}

			foreach ( self::flatten( $value, $depth - 1 ) as $nested ) {
				$flat[] = $nested;
			}
		}

		return $flat;
	}

	/**
	 * The path split into segments, with the whole path tried as a key first.
	 *
	 * Checking the literal key up front is what lets an array hold a key with a
	 * dot in it -- `'acme.version'` is a name someone will have used -- without
	 * this reading it as two steps that do not exist.
	 *
	 * @param array<array-key, mixed>       $data The array being read.
	 * @param string|array<int, string|int> $path A dotted path, or its segments.
	 * @return array<int, string|int> The segments to walk.
	 */
	private static function get_path_segments( array $data, string|array $path ): array {
		if ( ! \is_array( $path ) && \array_key_exists( $path, $data ) ) {
			return array( $path );
		}

		return self::split_path( $path );
	}

	/**
	 * A path as segments, however it was given.
	 *
	 * @param string|array<int, string|int> $path A dotted path, or its segments.
	 * @return array<int, string|int>
	 */
	private static function split_path( string|array $path ): array {
		return \is_array( $path ) ? \array_values( $path ) : \explode( '.', $path );
	}
}
