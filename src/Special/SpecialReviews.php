<?php
/**
 * Special page for browsing reviews.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight\Special;

use MediaWiki\Extension\Starlight\ReviewFormatter;
use MediaWiki\Extension\Starlight\ReviewStore;
use SpecialPage;

class SpecialReviews extends SpecialPage {

	private ReviewStore $reviewStore;
	private ReviewFormatter $formatter;

	public function __construct(
		ReviewStore $reviewStore,
		ReviewFormatter $formatter
	) {
		parent::__construct( 'Reviews' );
		$this->reviewStore = $reviewStore;
		$this->formatter = $formatter;
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->outputHeader();

		$output = $this->getOutput();
		$output->addModuleStyles( [ 'ext.starlight.reviews.styles' ] );
		$output->addModules( [ 'ext.starlight.reviews', 'ext.starlight.verify' ] );

		$request = $this->getRequest();

		// Get page ID from subpage or query parameter
		$pageId = 0;
		if ( $subPage ) {
			$pageId = (int)$subPage;
		} else {
			$pageId = $request->getInt( 'page' );
		}

		if ( $pageId > 0 ) {
			$this->showPageReviews( $pageId );
		} else {
			$this->showRecentReviews();
		}
	}

	/**
	 * Show reviews for a specific page.
	 *
	 * @param int $pageId
	 */
	private function showPageReviews( int $pageId ): void {
		$output = $this->getOutput();
		$user = $this->getUser();

		$sort = $this->getRequest()->getText( 'sort', 'smart' );
		$limit = $this->getRequest()->getInt( 'limit', 10 );

		$reviews = $this->reviewStore->getReviewsForPage( $pageId, $sort, $limit );
		$stats = $this->reviewStore->getPageStats( $pageId );

		if ( empty( $reviews ) ) {
			$output->addWikiMsg( 'starlight-no-reviews' );
			return;
		}

		// Render reviews
		$html = '';
		foreach ( $reviews as $review ) {
			$html .= $this->formatter->renderReview( $review, $user, false );
		}

		$output->addHTML( $html );
	}

	/**
	 * Show recent reviews across the wiki.
	 */
	private function showRecentReviews(): void {
		$output = $this->getOutput();

		// TODO: Implement a listing of pages with reviews
		$output->addWikiMsg( 'starlight-special-reviews-desc' );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'pages';
	}
}
