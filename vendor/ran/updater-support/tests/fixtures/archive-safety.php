<?php

declare(strict_types=1);

$pathBytes = static function ( int $length ): string {
	$parts = array();
	while ( $length > 255 ) {
		$part    = min( 255, $length - 2 );
		$parts[] = str_repeat( 'a', $part );
		$length -= $part + 1;
	}
	$parts[] = str_repeat( 'a', $length );
	return implode( '/', $parts );
};

return array(
	'paths'      => array(
		'empty path'             => array( '', null ),
		'file'                   => array(
			'plugin/main.php',
			array(
				'path'      => 'plugin/main.php',
				'directory' => false,
			),
		),
		'directory'              => array(
			'plugin/',
			array(
				'path'      => 'plugin',
				'directory' => true,
			),
		),
		'single trailing slash'  => array(
			'plugin/main.php/',
			array(
				'path'      => 'plugin/main.php',
				'directory' => true,
			),
		),
		'double trailing slash'  => array( 'plugin//', null ),
		'dot'                    => array( './plugin/main.php', null ),
		'dot dot'                => array( 'plugin/../main.php', null ),
		'embedded traversal'     => array( 'plugin/safe/../main.php', null ),
		'empty component'        => array( 'plugin//main.php', null ),
		'absolute path'          => array( '/plugin/main.php', null ),
		'windows drive'          => array( 'C:/plugin/main.php', null ),
		'nul byte'               => array( "plugin/\x00main.php", null ),
		'newline'                => array( "plugin/\nmain.php", null ),
		'control byte'           => array( "plugin/\x01main.php", null ),
		'literal UTF8'           => array( "plugin/\xc3\xa9.php", null ),
		'invalid UTF8'           => array( "plugin/\xc3\x28.php", null ),
		'non ascii byte'         => array( "plugin/\x80main.php", null ),
		'percent encoded UTF8'   => array(
			'plugin/%E2%82%AC.php',
			array(
				'path'      => 'plugin/%E2%82%AC.php',
				'directory' => false,
			),
		),
		'dash underscore dotted' => array(
			'plugin/a-b_c.1.php',
			array(
				'path'      => 'plugin/a-b_c.1.php',
				'directory' => false,
			),
		),
		'backslash'              => array( 'plugin\\main.php', null ),
		'colon'                  => array( 'plugin:main.php', null ),
		'windows reserved'       => array( 'plugin/CON.txt', null ),
		'windows reserved AUX'   => array( 'plugin/AUX', null ),
		'windows reserved PRN'   => array( 'plugin/PRN', null ),
		'windows reserved COM'   => array( 'plugin/COM1.dat', null ),
		'windows reserved LPT'   => array( 'plugin/LPT9', null ),
		'trailing dot'           => array( 'plugin/main.', null ),
		'trailing space'         => array( 'plugin/main ', null ),
		'less than'              => array( 'plugin/<main', null ),
		'greater than'           => array( 'plugin/>main', null ),
		'quote'                  => array( 'plugin/"main', null ),
		'pipe'                   => array( 'plugin/|main', null ),
		'question'               => array( 'plugin/?main', null ),
		'asterisk'               => array( 'plugin/*main', null ),
		'component 255'          => array(
			str_repeat( 'a', 255 ),
			array(
				'path'      => str_repeat( 'a', 255 ),
				'directory' => false,
			),
		),
		'component 256'          => array( str_repeat( 'a', 256 ), null ),
		'path 1024'              => array(
			$pathBytes( 1024 ),
			array(
				'path'      => $pathBytes( 1024 ),
				'directory' => false,
			),
		),
		'path 1025'              => array(
			$pathBytes( 1025 ),
			array(
				'path'      => $pathBytes( 1025 ),
				'directory' => false,
			),
		),
		'path 2048'              => array(
			$pathBytes( 2048 ),
			array(
				'path'      => $pathBytes( 2048 ),
				'directory' => false,
			),
		),
		'path 4096'              => array(
			$pathBytes( 4096 ),
			array(
				'path'      => $pathBytes( 4096 ),
				'directory' => false,
			),
		),
		'path 4097'              => array( $pathBytes( 4097 ), null ),
	),
	'metadata'   => array(
		'missing origin'                       => array( null, 0, false, 'entry_metadata_invalid' ),
		'missing attributes'                   => array( 3, null, false, 'entry_metadata_invalid' ),
		'unix unspecified file'                => array( 3, 0, false, null ),
		'unix unspecified directory'           => array( 3, 0, true, null ),
		'unix regular file'                    => array( 3, 0100000 << 16, false, null ),
		'unix regular directory mismatch'      => array( 3, 0100000 << 16, true, 'entry_metadata_invalid' ),
		'unix directory'                       => array( 3, 0040000 << 16, true, null ),
		'unix directory file mismatch'         => array( 3, 0040000 << 16, false, 'entry_metadata_invalid' ),
		'unix symlink'                         => array( 3, 0120000 << 16, false, 'entry_type_unsupported' ),
		'unix character device'                => array( 3, 0020000 << 16, false, 'entry_type_unsupported' ),
		'unix block device'                    => array( 3, 0060000 << 16, false, 'entry_type_unsupported' ),
		'unix fifo'                            => array( 3, 0010000 << 16, false, 'entry_type_unsupported' ),
		'unix socket'                          => array( 3, 0140000 << 16, false, 'entry_type_unsupported' ),
		'dos zero file'                        => array( 0, 0, false, null ),
		'dos zero directory'                   => array( 0, 0, true, null ),
		'dos directory'                        => array( 0, 0x10, true, null ),
		'dos directory file mismatch'          => array( 0, 0x10, false, 'entry_metadata_invalid' ),
		'dos non-directory file'               => array( 0, 0x20, false, null ),
		'dos non-directory directory mismatch' => array( 0, 0x20, true, 'entry_metadata_invalid' ),
		'dos volume'                           => array( 0, 0x08, false, 'entry_type_unsupported' ),
		'dos ignores unix high word'           => array( 0, 0120000 << 16, false, null ),
		'unknown origin'                       => array( 7, 0, false, 'entry_type_unsupported' ),
	),
	'collisions' => array(
		'explicit directory with child'      => array(
			array(
				array(
					'path'      => 'plugin',
					'directory' => true,
				),
				array(
					'path'      => 'plugin/main.php',
					'directory' => false,
				),
			),
			null,
		),
		'case duplicate'                     => array(
			array(
				array(
					'path'      => 'plugin/Main.php',
					'directory' => false,
				),
				array(
					'path'      => 'plugin/main.php',
					'directory' => false,
				),
			),
			'path_duplicate',
		),
		'file before child'                  => array(
			array(
				array(
					'path'      => 'plugin',
					'directory' => false,
				),
				array(
					'path'      => 'plugin/main.php',
					'directory' => false,
				),
			),
			'file_parent_collision',
		),
		'child before file'                  => array(
			array(
				array(
					'path'      => 'plugin/main.php',
					'directory' => false,
				),
				array(
					'path'      => 'plugin',
					'directory' => false,
				),
			),
			'file_parent_collision',
		),
		'case file before child'             => array(
			array(
				array(
					'path'      => 'Plugin',
					'directory' => false,
				),
				array(
					'path'      => 'plugin/main.php',
					'directory' => false,
				),
			),
			'file_parent_collision',
		),
		'case child before file'             => array(
			array(
				array(
					'path'      => 'plugin/main.php',
					'directory' => false,
				),
				array(
					'path'      => 'PLUGIN',
					'directory' => false,
				),
			),
			'file_parent_collision',
		),
		'file sibling before child'          => array(
			array(
				array(
					'path'      => 'a',
					'directory' => false,
				),
				array(
					'path'      => 'a!',
					'directory' => false,
				),
				array(
					'path'      => 'a/child',
					'directory' => false,
				),
			),
			'file_parent_collision',
		),
		'file sibling reversed before child' => array(
			array(
				array(
					'path'      => 'a/child',
					'directory' => false,
				),
				array(
					'path'      => 'a!',
					'directory' => false,
				),
				array(
					'path'      => 'a',
					'directory' => false,
				),
			),
			'file_parent_collision',
		),
	),
);
