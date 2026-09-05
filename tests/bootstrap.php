<?php

declare(strict_types=1);

$autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_readable( $autoloader ) ) {
	fwrite( STDERR, "Dependencies not installed. Run `composer install` first.\n" );
	exit( 1 );
}

require_once $autoloader;
