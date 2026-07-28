<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alphabets: named, reusable letter orderings (A-Z each valued 1-26).
 * Start/end dates are informational reminders only - never enforced.
 * Stored in one option: cp_alphabets = [ id => [id,name,start,end,letters] ].
 */
class CP_Alphabets {

	public static function all() {
		$all = get_option( 'cp_alphabets', array() );
		return is_array( $all ) ? $all : array();
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function save( $alphabet ) {
		$all = self::all();
		if ( empty( $alphabet['id'] ) ) {
			$alphabet['id'] = sanitize_key( $alphabet['name'] . '-' . wp_generate_password( 6, false ) );
		}
		$all[ $alphabet['id'] ] = $alphabet;
		update_option( 'cp_alphabets', $all, false );
		return $alphabet['id'];
	}

	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( 'cp_alphabets', $all, false );
	}

	/**
	 * Build a sortable key for one word under a custom alphabet.
	 * Each letter maps to its 2-digit value; non-letters keep natural order
	 * after letters so "O'Brien" and "St. John" behave predictably.
	 */
	private static function word_key( $word, $letters ) {
		$word = strtoupper( remove_accents( (string) $word ) );
		$key  = '';
		$len  = strlen( $word );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $word[ $i ];
			if ( isset( $letters[ $ch ] ) ) {
				$key .= str_pad( (string) (int) $letters[ $ch ], 2, '0', STR_PAD_LEFT );
			} elseif ( $ch >= '0' && $ch <= '9' ) {
				$key .= '3' . $ch; // digits after all letters (values 01-26)
			}
			// apostrophes, spaces, hyphens: ignored for ordering
		}
		return $key;
	}

	/**
	 * Sort an array of cp_candidate posts by last name, then first name,
	 * using the letter values of the given alphabet id.
	 */
	public static function sort_candidates( $candidates, $alphabet_id ) {
		$alphabet = self::get( $alphabet_id );
		if ( ! $alphabet || empty( $alphabet['letters'] ) ) {
			$alphabet = self::get( 'standard' );
		}
		$letters = $alphabet ? $alphabet['letters'] : array();

		usort( $candidates, function ( $a, $b ) use ( $letters ) {
			$a_last  = self::word_key( get_post_meta( $a->ID, '_cp_last_name', true ), $letters );
			$b_last  = self::word_key( get_post_meta( $b->ID, '_cp_last_name', true ), $letters );
			if ( $a_last !== $b_last ) {
				return strcmp( $a_last, $b_last );
			}
			$a_first = self::word_key( get_post_meta( $a->ID, '_cp_first_name', true ), $letters );
			$b_first = self::word_key( get_post_meta( $b->ID, '_cp_first_name', true ), $letters );
			return strcmp( $a_first, $b_first );
		} );

		return $candidates;
	}
}
