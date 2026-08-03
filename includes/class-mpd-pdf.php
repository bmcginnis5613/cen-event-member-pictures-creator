<?php
/**
 * Small, dependency-free PDF writer for the RSVP picture directory.
 *
 * @package MemberPhotoDirectory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MPD_PDF {
	/** @var array<int,array{name:string,email:string,title:string,company:string,photo:string}> */
	private $members = array();

	/** Add one member to the document. */
	public function add_member( $name, $email, $photo_path = '', $title = '', $company = '' ) {
		$this->members[] = array(
			'name'    => $this->plain_text( $name ),
			'email'   => $this->plain_text( $email ),
			'title'   => $this->plain_text( $title ),
			'company' => $this->plain_text( $company ),
			'photo'   => is_string( $photo_path ) ? $photo_path : '',
		);
	}

	/** Stream a completed PDF download and stop request execution. */
	public function download( $filename ) {
		$pdf      = $this->build();
		$filename = sanitize_file_name( $filename );

		if ( headers_sent() ) {
			wp_die( esc_html__( 'The PDF could not be sent because output had already started.', 'cen-event-member-pictures-creator' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Assemble a PDF 1.4 document.
	 *
	 * @return string
	 */
	private function build() {
		$objects = array();
		$next_id = 1;

		$catalog_id = $next_id++;
		$pages_id   = $next_id++;
		$regular_id = $next_id++;
		$bold_id    = $next_id++;
		$info_id    = $next_id++;

		$objects[ $regular_id ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[ $bold_id ]    = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$objects[ $info_id ]    = '<< /Title (Event RSVP Pictures) /Creator (CEN Event Member Pictures Creator) /CreationDate (D:' . gmdate( 'YmdHis' ) . 'Z) >>';

		$groups = array_chunk( $this->members, 20 );
		if ( empty( $groups ) ) {
			$groups = array( array() );
		}

		$page_ids  = array();
		$page_count = count( $groups );

		foreach ( $groups as $page_index => $page_members ) {
			$page_id    = $next_id++;
			$content_id = $next_id++;
			$page_ids[] = $page_id;
			$images     = array();

			foreach ( $page_members as $member_index => $member ) {
				$image = $this->read_jpeg( $member['photo'] );
				if ( $image ) {
					$image['id']   = $next_id++;
					$image['name'] = 'Im' . ( $member_index + 1 );
					$images[ $member_index ] = $image;
					$objects[ $image['id'] ] = $this->image_object( $image );
				}
			}

			$content = $this->page_content( $page_members, $images, $page_index + 1, $page_count );
			$objects[ $content_id ] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";

			$xobjects = '';
			foreach ( $images as $image ) {
				$xobjects .= '/' . $image['name'] . ' ' . $image['id'] . ' 0 R ';
			}

			$resources = '<< /Font << /F1 ' . $regular_id . ' 0 R /F2 ' . $bold_id . ' 0 R >>';
			if ( $xobjects ) {
				$resources .= ' /XObject << ' . $xobjects . '>>';
			}
			$resources .= ' >>';

			$objects[ $page_id ] = '<< /Type /Page /Parent ' . $pages_id . ' 0 R /MediaBox [0 0 612 792] /Resources ' . $resources . ' /Contents ' . $content_id . ' 0 R >>';
		}

		$kids = '';
		foreach ( $page_ids as $page_id ) {
			$kids .= $page_id . ' 0 R ';
		}

		$objects[ $pages_id ]   = '<< /Type /Pages /Kids [ ' . $kids . '] /Count ' . count( $page_ids ) . ' >>';
		$objects[ $catalog_id ] = '<< /Type /Catalog /Pages ' . $pages_id . ' 0 R >>';

		ksort( $objects );
		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array( 0 );
		$max_id  = max( array_keys( $objects ) );

		for ( $id = 1; $id <= $max_id; $id++ ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf .= $id . " 0 obj\n" . $objects[ $id ] . "\nendobj\n";
		}

		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( $max_id + 1 ) . "\n";
		$pdf .= "0000000000 65535 f \n";
		for ( $id = 1; $id <= $max_id; $id++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $id ] );
		}

		$pdf .= "trailer\n<< /Size " . ( $max_id + 1 ) . ' /Root ' . $catalog_id . ' 0 R /Info ' . $info_id . " 0 R >>\n";
		$pdf .= "startxref\n" . $xref . "\n%%EOF";

		return $pdf;
	}

	/** Create the drawing commands for one letter-size page. */
	private function page_content( $members, $images, $page_number, $page_count ) {
		$content = '';

		if ( empty( $members ) ) {
			$content .= "0.3 0.3 0.3 rg\nBT /F1 11 Tf 30 740 Td (No RSVP attendees were found.) Tj ET\n";
		}

		foreach ( $members as $index => $member ) {
			$column = $index % 2;
			$row    = (int) floor( $index / 2 );
			$x      = 30 + ( $column * 283 );
			$y      = 710 - ( $row * 70 );
			$content .= $this->card_content( $member, isset( $images[ $index ] ) ? $images[ $index ] : null, $x, $y );
		}

		$content .= "0.46 0.50 0.56 rg\nBT /F1 8 Tf 536 20 Td (Page " . $page_number . ' of ' . $page_count . ") Tj ET\n";

		return $content;
	}

	/** Draw one compact member card for a 20-user page. */
	private function card_content( $member, $image, $x, $y ) {
		$width      = 269;
		$height     = 64;
		$photo_size = 56;
		$photo_x    = $x + $width - $photo_size - 4;
		$photo_y    = $y + 4;
		$name         = $this->pdf_string( $this->truncate( $member['name'], 40 ) );
		$email        = $this->pdf_string( $this->truncate( $member['email'], 49 ) );
		$company      = $this->pdf_string( $this->truncate( $member['company'], 49 ) );
		$title_lines  = $this->wrap_lines( $member['title'], 49, 2 );
		$name_size    = $this->fit_font_size( $member['name'], 10.5, 7, 192 );
		$email_size   = $this->fit_font_size( $member['email'], 7, 5.5, 192 );
		$company_size = $this->fit_font_size( $member['company'], 7.5, 5.5, 192 );

		$out  = "1 1 1 rg 0.79 0.82 0.86 RG 0.7 w " . $this->number( $x ) . ' ' . $this->number( $y ) . ' ' . $width . ' ' . $height . " re B\n";
		$out .= "0.11 0.17 0.25 rg\nBT /F2 " . $this->number( $name_size ) . ' Tf ' . $this->number( $x + 9 ) . ' ' . $this->number( $y + 50 ) . " Td (" . $name . ") Tj ET\n";

		$detail_y = $y + 37;
		foreach ( $title_lines as $title_line ) {
			$title_size = $this->fit_font_size( $title_line, 7.5, 5.5, 192 );
			$out .= "0.22 0.25 0.29 rg\nBT /F1 " . $this->number( $title_size ) . ' Tf ' . $this->number( $x + 9 ) . ' ' . $this->number( $detail_y ) . ' Td (' . $this->pdf_string( $title_line ) . ") Tj ET\n";
			$detail_y -= 10;
		}
		if ( '' !== $member['company'] ) {
			$out .= "0.22 0.25 0.29 rg\nBT /F1 " . $this->number( $company_size ) . ' Tf ' . $this->number( $x + 9 ) . ' ' . $this->number( $detail_y ) . " Td (" . $company . ") Tj ET\n";
			$detail_y -= 10;
		}
		if ( '' !== $member['email'] ) {
			$out .= "0.20 0.38 0.58 rg\nBT /F1 " . $this->number( $email_size ) . ' Tf ' . $this->number( $x + 9 ) . ' ' . $this->number( $detail_y ) . " Td (" . $email . ") Tj ET\n";
		}

		if ( $image ) {
			$scale = max( $photo_size / $image['width'], $photo_size / $image['height'] );
			$draw_w = $image['width'] * $scale;
			$draw_h = $image['height'] * $scale;
			$draw_x = $photo_x + ( $photo_size - $draw_w ) / 2;
			$draw_y = $photo_y + ( $photo_size - $draw_h ) / 2;
			$out .= 'q ' . $this->number( $photo_x ) . ' ' . $this->number( $photo_y ) . ' ' . $photo_size . ' ' . $photo_size . ' re W n ';
			$out .= $this->number( $draw_w ) . ' 0 0 ' . $this->number( $draw_h ) . ' ' . $this->number( $draw_x ) . ' ' . $this->number( $draw_y ) . ' cm /' . $image['name'] . " Do Q\n";
		} else {
			$out .= "0.94 0.95 0.96 rg " . $this->number( $photo_x ) . ' ' . $this->number( $photo_y ) . ' ' . $photo_size . ' ' . $photo_size . " re f\n";
			$out .= "0.55 0.58 0.62 rg\nBT /F1 6 Tf " . $this->number( $photo_x + 11 ) . ' ' . $this->number( $photo_y + 26 ) . " Td (NO PHOTO) Tj ET\n";
		}

		return $out;
	}

	/** Read JPEG metadata and bytes. */
	private function read_jpeg( $path ) {
		if ( ! $path || ! is_readable( $path ) ) {
			return null;
		}

		$info = wp_getimagesize( $path );
		if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/jpg' ), true ) ) {
			return null;
		}

		$data = file_get_contents( $path );
		if ( false === $data ) {
			return null;
		}

		return array(
			'width'      => (int) $info[0],
			'height'     => (int) $info[1],
			'bits'       => isset( $info['bits'] ) ? (int) $info['bits'] : 8,
			'components' => isset( $info['channels'] ) ? (int) $info['channels'] : 3,
			'data'       => $data,
		);
	}

	/** Build a JPEG image XObject. */
	private function image_object( $image ) {
		$color_space = 4 === $image['components'] ? '/DeviceCMYK' : ( 1 === $image['components'] ? '/DeviceGray' : '/DeviceRGB' );
		$decode      = 4 === $image['components'] ? ' /Decode [1 0 1 0 1 0 1 0]' : '';

		return '<< /Type /XObject /Subtype /Image /Width ' . $image['width'] . ' /Height ' . $image['height'] . ' /ColorSpace ' . $color_space . ' /BitsPerComponent ' . $image['bits'] . ' /Filter /DCTDecode' . $decode . ' /Length ' . strlen( $image['data'] ) . " >>\nstream\n" . $image['data'] . "\nendstream";
	}

	/** Convert user text to the built-in font's supported character set. */
	private function plain_text( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		if ( function_exists( 'iconv' ) ) {
			$converted = iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value );
			if ( false !== $converted ) {
				$value = $converted;
			}
		}

		return preg_replace( '/[^\x20-\x7E\x80-\xFF]/', '', $value );
	}

	/** Escape a literal PDF string. */
	private function pdf_string( $value ) {
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $value );
	}

	/** Truncate a line without requiring mbstring. */
	private function truncate( $value, $length ) {
		if ( strlen( $value ) <= $length ) {
			return $value;
		}

		return rtrim( substr( $value, 0, $length - 3 ) ) . '...';
	}

	/** Wrap text at word boundaries and limit it to a fixed number of lines. */
	private function wrap_lines( $value, $line_length, $max_lines ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array();
		}

		$lines = explode( "\n", wordwrap( $value, $line_length, "\n", true ) );
		if ( count( $lines ) <= $max_lines ) {
			return $lines;
		}

		$visible = array_slice( $lines, 0, $max_lines - 1 );
		$visible[] = $this->truncate( implode( ' ', array_slice( $lines, $max_lines - 1 ) ), $line_length );

		return $visible;
	}

	/** Fit a single text line within an approximate maximum width. */
	private function fit_font_size( $value, $preferred, $minimum, $max_width ) {
		$length = max( 1, strlen( $value ) );
		$size   = $max_width / ( $length * 0.52 );

		return max( $minimum, min( $preferred, $size ) );
	}

	/** Stable numeric formatting for PDF drawing operators. */
	private function number( $value ) {
		return rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' );
	}
}
