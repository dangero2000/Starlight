<?php
/**
 * Service wiring for the Starlight extension.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

use MediaWiki\Extension\Starlight\NameGenerator;
use MediaWiki\Extension\Starlight\ReviewFormatter;
use MediaWiki\Extension\Starlight\ReviewSorter;
use MediaWiki\Extension\Starlight\ReviewStore;
use MediaWiki\Extension\Starlight\ReviewValidator;
use MediaWiki\Extension\Starlight\SecurityLogger;
use MediaWiki\Extension\Starlight\SessionManager;
use MediaWiki\Extension\Starlight\VerificationStore;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'Starlight.ReviewStore' => static function ( MediaWikiServices $services ): ReviewStore {
		return new ReviewStore(
			$services->getConnectionProvider(),
			$services->getMainConfig()
		);
	},

	'Starlight.ReviewValidator' => static function ( MediaWikiServices $services ): ReviewValidator {
		return new ReviewValidator(
			$services->getMainConfig()
		);
	},

	'Starlight.ReviewSorter' => static function ( MediaWikiServices $services ): ReviewSorter {
		return new ReviewSorter(
			$services->getMainConfig()
		);
	},

	'Starlight.SessionManager' => static function ( MediaWikiServices $services ): SessionManager {
		return new SessionManager(
			$services->getMainConfig()
		);
	},

	'Starlight.NameGenerator' => static function ( MediaWikiServices $services ): NameGenerator {
		return new NameGenerator(
			$services->getService( 'Starlight.SessionManager' )
		);
	},

	'Starlight.VerificationStore' => static function ( MediaWikiServices $services ): VerificationStore {
		return new VerificationStore(
			$services->getConnectionProvider(),
			$services->getMainConfig(),
			$services->getService( 'Starlight.ReviewSorter' )
		);
	},

	'Starlight.ReviewFormatter' => static function ( MediaWikiServices $services ): ReviewFormatter {
		return new ReviewFormatter(
			$services->getService( 'Starlight.ReviewStore' ),
			$services->getService( 'Starlight.ReviewSorter' ),
			$services->getService( 'Starlight.VerificationStore' ),
			$services->getService( 'Starlight.SessionManager' ),
			$services->getService( 'Starlight.NameGenerator' ),
			$services->getMainConfig()
		);
	},

	'Starlight.SecurityLogger' => static function ( MediaWikiServices $services ): SecurityLogger {
		return new SecurityLogger(
			LoggerFactory::getInstance( 'Starlight' )
		);
	},
];
