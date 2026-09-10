<?php
namespace modules_insight;

/**
 * Minimal XLSX writer.
 *
 * Produces a real Office Open XML spreadsheet so the report opens with correct
 * columns and UTF-8 text in Excel, Excel Online and SharePoint — none of the
 * delimiter/encoding guessing a CSV forces on the reader.
 *
 * Self-contained: builds the .xlsx (a ZIP of XML parts) in memory with a tiny
 * store-only ZIP packer, so it needs neither the zip extension nor a temp file.
 * Every cell is written as an inline string, which also makes spreadsheet
 * formula injection impossible — an inline string is never evaluated.
 *
 * @package modules_insight
 * @since 4.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

/**
 * Builds a complete .xlsx document from a list of rows.
 *
 * @param array[] $rows       Rows of scalar cell values (ragged rows are fine).
 * @param string  $sheet_name Worksheet tab name.
 * @return string Raw .xlsx bytes.
 */
function mi_build_xlsx_document( array $rows, string $sheet_name = 'Modules Insight' ): string {
    $parts = array(
        '[Content_Types].xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>',

        '_rels/.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',

        'xl/workbook.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . mi_xlsx_escape( mi_xlsx_sheet_name( $sheet_name ) ) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>',

        'xl/_rels/workbook.xml.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>',

        'xl/worksheets/sheet1.xml' => mi_xlsx_sheet_xml( $rows ),
    );

    return mi_xlsx_zip( $parts );
}

/**
 * Serialises the rows into a worksheet part.
 *
 * @param array[] $rows Rows of scalar cell values.
 * @return string
 */
function mi_xlsx_sheet_xml( array $rows ): string {
    $max_cols = 0;
    foreach ( $rows as $cells ) {
        $max_cols = max( $max_cols, count( $cells ) );
    }
    $dimension = 'A1:' . mi_xlsx_col( max( 0, $max_cols - 1 ) ) . max( 1, count( $rows ) );

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<dimension ref="' . $dimension . '"/>'
        . '<sheetData>';

    foreach ( array_values( $rows ) as $r => $cells ) {
        $row_num = $r + 1;
        $xml    .= '<row r="' . $row_num . '">';
        foreach ( array_values( $cells ) as $c => $value ) {
            $ref  = mi_xlsx_col( $c ) . $row_num;
            $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                . mi_xlsx_escape( (string) $value )
                . '</t></is></c>';
        }
        $xml .= '</row>';
    }

    return $xml . '</sheetData></worksheet>';
}

/**
 * Zero-based column index to spreadsheet letters (0 → A, 26 → AA).
 *
 * @param int $index Column index.
 * @return string
 */
function mi_xlsx_col( int $index ): string {
    $letters = '';
    for ( $n = $index; $n >= 0; $n = intdiv( $n, 26 ) - 1 ) {
        $letters = chr( 65 + ( $n % 26 ) ) . $letters;
    }
    return $letters;
}

/**
 * XML-escapes a cell value and strips characters XML 1.0 forbids.
 *
 * @param string $value Raw value.
 * @return string
 */
function mi_xlsx_escape( string $value ): string {
    $value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
    return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
}

/**
 * Sanitises a worksheet tab name to Excel's rules (<=31 chars, no []:*?/\).
 *
 * @param string $name Proposed name.
 * @return string
 */
function mi_xlsx_sheet_name( string $name ): string {
    $name = preg_replace( '/[\[\]:*?\/\\\\]/', ' ', $name );
    $name = trim( $name );
    if ( '' === $name ) {
        $name = 'Sheet1';
    }
    return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 31 ) : substr( $name, 0, 31 );
}

/**
 * Packs named strings into a store-only (uncompressed) ZIP archive.
 *
 * @param array<string,string> $parts Map of archive path => file contents.
 * @return string Raw ZIP bytes.
 */
function mi_xlsx_zip( array $parts ): string {
    $local   = '';
    $central = '';
    $offset  = 0;

    foreach ( $parts as $name => $content ) {
        $crc  = crc32( $content ) & 0xFFFFFFFF;
        $size = strlen( $content );
        $nlen = strlen( $name );

        $header = "PK\x03\x04"
            . pack( 'v', 20 )   // version needed to extract
            . pack( 'v', 0 )    // general purpose flags
            . pack( 'v', 0 )    // compression method: store
            . pack( 'v', 0 )    // last mod time (fixed)
            . pack( 'v', 0x21 ) // last mod date (1980-01-01)
            . pack( 'V', $crc )
            . pack( 'V', $size ) // compressed size
            . pack( 'V', $size ) // uncompressed size
            . pack( 'v', $nlen )
            . pack( 'v', 0 )     // extra field length
            . $name;

        $local .= $header . $content;

        $central .= "PK\x01\x02"
            . pack( 'v', 20 )   // version made by
            . pack( 'v', 20 )   // version needed
            . pack( 'v', 0 )
            . pack( 'v', 0 )
            . pack( 'v', 0 )
            . pack( 'v', 0x21 )
            . pack( 'V', $crc )
            . pack( 'V', $size )
            . pack( 'V', $size )
            . pack( 'v', $nlen )
            . pack( 'v', 0 )    // extra length
            . pack( 'v', 0 )    // comment length
            . pack( 'v', 0 )    // disk number start
            . pack( 'v', 0 )    // internal attributes
            . pack( 'V', 0 )    // external attributes
            . pack( 'V', $offset )
            . $name;

        $offset += strlen( $header ) + $size;
    }

    $eocd = "PK\x05\x06"
        . pack( 'v', 0 )
        . pack( 'v', 0 )
        . pack( 'v', count( $parts ) )
        . pack( 'v', count( $parts ) )
        . pack( 'V', strlen( $central ) )
        . pack( 'V', strlen( $local ) )
        . pack( 'v', 0 );

    return $local . $central . $eocd;
}
