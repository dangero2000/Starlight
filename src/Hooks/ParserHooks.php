<?php
/**
 * Parser hooks for the Starlight extension.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight\Hooks;

use MediaWiki\Extension\Starlight\ReviewStore;
use MediaWiki\Extension\Starlight\ReviewFormatter;
use MediaWiki\Hook\ParserFirstCallInitHook;
use Parser;
use PPFrame;

class ParserHooks implements ParserFirstCallInitHook {

	private ReviewStore $reviewStore;
	private ReviewFormatter $reviewFormatter;

	public function __construct(
		ReviewStore $reviewStore,
		ReviewFormatter $reviewFormatter
	) {
		$this->reviewStore = $reviewStore;
		$this->reviewFormatter = $reviewFormatter;
	}

	/**
	 * Register the <reviews> parser tag.
	 *
	 * @param Parser $parser
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setHook( 'reviews', [ $this, 'renderReviewsTag' ] );
	}

	/**
	 * Render the <reviews> tag.
	 *
	 * @param string|null $input Content between tags (unused)
	 * @param array $args Tag attributes
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string HTML output
	 */
	public function renderReviewsTag(
		?string $input,
		array $args,
		Parser $parser,
		PPFrame $frame
	): string {
		// Get the page being viewed
		$page = $parser->getPage();
		if ( $page === null ) {
			return '';
		}

		$pageId = $page->getId();
		if ( $pageId === 0 ) {
			// Page hasn't been saved yet
			return '';
		}

		// Parse tag attributes
		$sort = $args['sort'] ?? 'smart';
		$limit = min( (int)( $args['limit'] ?? 10 ), 50 );
		$collapsed = ( $args['collapsed'] ?? 'true' ) === 'true';

		// Don't cache pages with reviews (they're dynamic)
		$parser->getOutput()->updateCacheExpiry( 0 );

		// Add required modules
		$parser->getOutput()->addModules( [ 'ext.starlight.reviews', 'ext.starlight.form', 'ext.starlight.verify' ] );
		$parser->getOutput()->addModuleStyles( [ 'ext.starlight.reviews.styles', 'ext.starlight.form.styles' ] );

		// Get the title for the summary text
		$title = $parser->getTitle();

		// Render the reviews section
		return $this->reviewFormatter->renderReviewsSection(
			$pageId,
			$sort,
			$limit,
			$collapsed,
			$title
		);
	}
}
