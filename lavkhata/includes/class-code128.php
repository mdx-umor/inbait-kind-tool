<?php
/**
 * Minimal Code 128 (set B) barcode as inline SVG. No external libraries.
 *
 * @package Lavkhata
 */

namespace Lavkhata;

defined( 'ABSPATH' ) || exit;

class Code128 {

	/** Bar/space widths for symbol values 0–106. */
	const PATTERNS = array(
		'212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
		'221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
		'221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
		'212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
		'231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
		'231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
		'314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
		'112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
		'111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
		'214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
		'114131', '311141', '411131', '211412', '211214', '211232', '2331112',
	);

	const START_B = 104;
	const STOP    = 106;

	/** Symbol values for a string (start, data, checksum, stop). */
	public static function values( $text ) {
		$text   = preg_replace( '/[^\x20-\x7E]/', '', (string) $text );
		$values = array( self::START_B );
		$sum    = self::START_B;
		$len    = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$v        = ord( $text[ $i ] ) - 32;
			$values[] = $v;
			$sum     += $v * ( $i + 1 );
		}
		$values[] = $sum % 103;
		$values[] = self::STOP;
		return $values;
	}

	/** Module widths, alternating bar/space, starting with a bar. */
	public static function widths( $text ) {
		$out = array();
		foreach ( self::values( $text ) as $v ) {
			foreach ( str_split( self::PATTERNS[ $v ] ) as $w ) {
				$out[] = (int) $w;
			}
		}
		return $out;
	}

	public static function svg( $text, $height = 50, $module = 1.6 ) {
		$x     = 10 * $module; // Quiet zone.
		$rects = '';
		$bar   = true;
		foreach ( self::widths( $text ) as $w ) {
			if ( $bar ) {
				$rects .= sprintf( '<rect x="%s" y="0" width="%s" height="%d"/>', round( $x, 2 ), round( $w * $module, 2 ), (int) $height );
			}
			$x  += $w * $module;
			$bar = ! $bar;
		}
		$width = round( $x + 10 * $module, 2 );
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$s %2$d" width="100%%" height="%2$d" preserveAspectRatio="none" role="img" aria-label="%3$s"><g fill="#000">%4$s</g></svg>',
			$width,
			(int) $height,
			esc_attr( $text ),
			$rects
		);
	}
}
