<?php
/**
 * HTML formatting for reviews display.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight;

use MediaWiki\Config\Config;
use MediaWiki\Html\Html;
use MediaWiki\Context\RequestContext;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

class ReviewFormatter {

	private ReviewStore $reviewStore;
	private ReviewSorter $sorter;
	private VerificationStore $verificationStore;
	private SessionManager $sessionManager;
	private NameGenerator $nameGenerator;
	private Config $config;

	public function __construct(
		ReviewStore $reviewStore,
		ReviewSorter $sorter,
		VerificationStore $verificationStore,
		SessionManager $sessionManager,
		NameGenerator $nameGenerator,
		Config $config
	) {
		$this->reviewStore = $reviewStore;
		$this->sorter = $sorter;
		$this->verificationStore = $verificationStore;
		$this->sessionManager = $sessionManager;
		$this->nameGenerator = $nameGenerator;
		$this->config = $config;
	}

	/**
	 * Render the complete reviews section for a page.
	 *
	 * @param int $pageId
	 * @param string $sort
	 * @param int $limit
	 * @param bool $collapsed
	 * @param Title|null $title
	 * @return string HTML
	 */
	public function renderReviewsSection(
		int $pageId,
		string $sort,
		int $limit,
		bool $collapsed,
		?Title $title = null
	): string {
		$user = RequestContext::getMain()->getUser();
		$stats = $this->reviewStore->getPageStats( $pageId );
		$reviews = $this->reviewStore->getReviewsForPage( $pageId, $sort, $limit );
		$pageTitle = $title ? $title->getText() : '';

		$html = Html::openElement( 'div', [
			'class' => 'starlight-reviews-section',
			'data-page-id' => $pageId,
			'data-sort' => $sort,
		] );

		// Summary paragraph
		$html .= $this->renderSummary( $stats, $pageTitle );

		// Review form button (if user can submit)
		if ( $this->canSubmitReview( $user, $pageId ) ) {
			$html .= $this->renderReviewForm( $pageId, $user );
		}

		// Collapsible reviews section
		$html .= Html::openElement( 'details', [
			'class' => 'starlight-reviews-details',
			'open' => !$collapsed,
		] );

		$html .= Html::element( 'summary', [],
			wfMessage( 'starlight-show-hide-reviews' )->text() );

		// Reviews heading
		$html .= Html::element( 'h3', [
			'class' => 'starlight-reviews-heading',
		], wfMessage( 'starlight-reviews-heading' )->text() );

		// Sort controls
		$html .= $this->renderSortControls( $sort );

		// Reviews list
		if ( empty( $reviews ) ) {
			$html .= Html::element( 'p', [
				'class' => 'starlight-no-reviews',
			], wfMessage( 'starlight-no-reviews' )->text() );
		} else {
			foreach ( $reviews as $review ) {
				$html .= $this->renderReview( $review, $user );
			}
		}

		// Pagination controls
		if ( $stats && $stats['sps_review_count'] > $limit ) {
			$html .= $this->renderPagination( $pageId, $stats['sps_review_count'], $limit );
		}

		$html .= Html::closeElement( 'details' );

		// Rating distribution
		if ( $stats && $stats['sps_review_count'] > 0 ) {
			$html .= $this->renderRatingDistribution( $stats );
		}

		$html .= Html::closeElement( 'div' );

		return $html;
	}

	/**
	 * Render the summary paragraph.
	 *
	 * @param array|null $stats
	 * @param string $pageTitle
	 * @return string HTML
	 */
	private function renderSummary( ?array $stats, string $pageTitle ): string {
		if ( !$stats || $stats['sps_review_count'] == 0 ) {
			return Html::element( 'p', [
				'class' => 'starlight-summary',
			], wfMessage( 'starlight-no-ratings' )->text() );
		}

		$avgRating = number_format( (float)$stats['sps_avg_rating'], 1 );
		$reviewCount = (int)$stats['sps_review_count'];

		$summaryText = wfMessage( 'starlight-summary-text' )
			->params( $pageTitle, $avgRating )
			->numParams( $reviewCount )
			->text();

		return Html::element( 'p', [
			'class' => 'starlight-summary',
		], $summaryText );
	}

	/**
	 * Render rating distribution.
	 *
	 * @param array $stats
	 * @return string HTML
	 */
	private function renderRatingDistribution( array $stats ): string {
		$html = Html::openElement( 'div', [
			'class' => 'starlight-distribution',
		] );

		for ( $i = 5; $i >= 1; $i-- ) {
			$count = (int)$stats['sps_rating_' . $i];

			$html .= Html::openElement( 'div', [
				'class' => 'starlight-distribution-row',
			] );

			$html .= Html::element( 'span', [
				'class' => 'starlight-distribution-label',
			], wfMessage( 'starlight-rating-stars' )->numParams( $i )->text() );

			$html .= Html::element( 'span', [
				'class' => 'starlight-distribution-count',
			], (string)$count );

			$html .= Html::closeElement( 'div' );
		}

		$html .= Html::closeElement( 'div' );

		return $html;
	}

	/**
	 * Render the review submission form.
	 *
	 * @param int $pageId
	 * @param User $user
	 * @return string HTML
	 */
	private function renderReviewForm( int $pageId, User $user ): string {
		$defaultName = $this->nameGenerator->getDefaultName( $user );
		$hasPersistentName = !$user->isRegistered() && $this->nameGenerator->hasPersistentName();

		// Button to open the dialog
		$html = Html::element( 'button', [
			'type' => 'button',
			'class' => 'starlight-write-review-button',
			'data-page-id' => $pageId,
		], wfMessage( 'starlight-write-review' )->text() );

		// Dialog container (hidden by default)
		$html .= Html::openElement( 'div', [
			'class' => 'starlight-form-dialog',
			'role' => 'dialog',
			'aria-modal' => 'true',
			'aria-labelledby' => 'starlight-dialog-title',
			'hidden' => true,
		] );

		$html .= Html::openElement( 'div', [
			'class' => 'starlight-form-dialog-backdrop',
		] );
		$html .= Html::closeElement( 'div' );

		$html .= Html::openElement( 'div', [
			'class' => 'starlight-form-container',
		] );

		// Dialog header
		$html .= Html::openElement( 'div', [
			'class' => 'starlight-form-header',
		] );
		$html .= Html::element( 'h3', [
			'id' => 'starlight-dialog-title',
			'class' => 'starlight-form-title',
		], wfMessage( 'starlight-write-review' )->text() );
		$html .= Html::element( 'button', [
			'type' => 'button',
			'class' => 'starlight-form-close',
			'aria-label' => wfMessage( 'starlight-form-close' )->text(),
		], wfMessage( 'starlight-form-close' )->text() );
		$html .= Html::closeElement( 'div' );

		$html .= Html::openElement( 'form', [
			'class' => 'starlight-review-form',
			'data-page-id' => $pageId,
		] );

		// Rating input
		$html .= $this->renderRatingInput();

		// Name input
		$html .= Html::openElement( 'div', [
			'class' => 'starlight-form-field',
		] );
		$html .= Html::element( 'label', [
			'for' => 'starlight-name',
		], wfMessage( 'starlight-form-name' )->text() );
		$html .= Html::input( 'name', $defaultName, 'text', [
			'id' => 'starlight-name',
			'class' => 'starlight-input',
			'maxlength' => $this->config->get( 'StarlightMaxNameLength' ),
		] );

		// Save name checkbox for anonymous users
		if ( !$user->isRegistered() ) {
			$html .= Html::openElement( 'label', [
				'class' => 'starlight-save-name-label',
			] );
			$html .= Html::check( 'save-name', $hasPersistentName );
			$html .= ' ' . wfMessage( 'starlight-form-save-name' )->text();
			$html .= Html::closeElement( 'label' );
		}
		$html .= Html::closeElement( 'div' );

		// Experience input
		$html .= Html::openElement( 'div', [
			'class' => 'starlight-form-field',
		] );
		$html .= Html::element( 'label', [
			'for' => 'starlight-experience',
		], wfMessage( 'starlight-form-experience' )->text() );
		$html .= Html::input( 'experience', '', 'text', [
			'id' => 'starlight-experience',
			'class' => 'starlight-input',
			'placeholder' => wfMessage( 'starlight-form-experience-placeholder' )->text(),
			'maxlength' => $this->config->get( 'StarlightMaxExperienceLength' ),
		] );
		$html .= Html::closeElement( 'div' );

		// Review text
		$html .= Html::openElement( 'div', [
			'class' => 'starlight-form-field',
		] );
		$html .= Html::element( 'label', [
			'for' => 'starlight-text',
		], wfMessage( 'starlight-form-text' )->text() );
		$html .= Html::textarea( 'text', '', [
			'id' => 'starlight-text',
			'class' => 'starlight-textarea',
			'rows' => 4,
			'maxlength' => $this->config->get( 'StarlightMaxReviewLength' ),
		] );
		$html .= Html::closeElement( 'div' );

		// Cookie consent for anonymous users
		if ( !$user->isRegistered() ) {
			$html .= Html::openElement( 'div', [
				'class' => 'starlight-form-field',
			] );
			$html .= Html::openElement( 'label' );
			$html .= Html::check( 'remember-me', false );
			$html .= ' ' . wfMessage( 'starlight-form-remember-me' )->text();
			$html .= Html::closeElement( 'label' );
			$html .= Html::element( 'p', [
				'class' => 'starlight-cookie-explanation',
			], wfMessage( 'starlight-form-cookie-explanation' )->text() );
			$html .= Html::closeElement( 'div' );
		}

		// Submit button
		$html .= Html::openElement( 'div', [
			'class' => 'starlight-form-field',
		] );
		$html .= Html::submitButton(
			wfMessage( 'starlight-form-submit' )->text(),
			[ 'class' => 'starlight-submit-button' ]
		);
		$html .= Html::closeElement( 'div' );

		$html .= Html::closeElement( 'form' );
		$html .= Html::closeElement( 'div' ); // .starlight-form-container
		$html .= Html::closeElement( 'div' ); // .starlight-form-dialog

		return $html;
	}

	/**
	 * Render rating input using accessible radio buttons.
	 *
	 * @return string HTML
	 */
	private function renderRatingInput(): string {
		$html = Html::openElement( 'fieldset', [
			'class' => 'starlight-rating-input',
		] );

		$html .= Html::element( 'legend', [],
			wfMessage( 'starlight-form-rating' )->text() );

		for ( $i = 5; $i >= 1; $i-- ) {
			$html .= Html::openElement( 'label', [
				'class' => 'starlight-rating-option',
			] );

			$html .= Html::input( 'rating', (string)$i, 'radio', [
				'required' => true,
			] );

			$html .= ' ' . wfMessage( 'starlight-rating-stars' )
				->numParams( $i )
				->text();

			$html .= Html::closeElement( 'label' );
		}

		$html .= Html::closeElement( 'fieldset' );

		return $html;
	}

	/**
	 * Render sort controls.
	 *
	 * @param string $currentSort
	 * @return string HTML
	 */
	private function renderSortControls( string $currentSort ): string {
		$sortOptions = [
			'smart' => 'starlight-sort-smart',
			'newest' => 'starlight-sort-newest',
			'oldest' => 'starlight-sort-oldest',
			'highest-rating' => 'starlight-sort-highest',
			'lowest-rating' => 'starlight-sort-lowest',
			'most-verified' => 'starlight-sort-verified',
		];

		$html = Html::openElement( 'div', [
			'class' => 'starlight-sort-controls',
		] );

		$html .= Html::element( 'label', [
			'for' => 'starlight-sort-select',
		], wfMessage( 'starlight-sort-label' )->text() );

		$html .= Html::openElement( 'select', [
			'id' => 'starlight-sort-select',
			'class' => 'starlight-sort-select',
		] );

		foreach ( $sortOptions as $value => $messageKey ) {
			$html .= Html::element( 'option', [
				'value' => $value,
				'selected' => $value === $currentSort,
			], wfMessage( $messageKey )->text() );
		}

		$html .= Html::closeElement( 'select' );
		$html .= Html::closeElement( 'div' );

		return $html;
	}

	/**
	 * Render a single review with proper semantic structure.
	 *
	 * @param array $review
	 * @param User $user
	 * @return string HTML
	 */
	public function renderReview( array $review, User $user ): string {
		$canEdit = $this->sessionManager->canEditReview( $review, $user );
		$verifyStats = $this->verificationStore->getVerificationStats( $review );
		$rating = (int)$review['sr_rating'];

		$html = Html::openElement( 'article', [
			'class' => 'starlight-review',
			'data-review-id' => $review['sr_id'],
		] );

		// Reviewer name as heading
		$html .= Html::element( 'h4', [
			'class' => 'starlight-reviewer-name',
		], $review['sr_reviewer_name'] );

		// Metadata list
		$html .= Html::openElement( 'ul', [
			'class' => 'starlight-review-meta',
		] );

		// Rating
		$html .= Html::rawElement( 'li', [],
			Html::element( 'strong', [], wfMessage( 'starlight-rating-label' )->text() ) .
			' ' . wfMessage( 'starlight-rating-value' )->numParams( $rating )->text()
		);

		// Experience
		if ( $review['sr_experience'] ) {
			$html .= Html::rawElement( 'li', [],
				Html::element( 'strong', [], wfMessage( 'starlight-experience-label' )->text() ) .
				' ' . htmlspecialchars( $review['sr_experience'] )
			);
		}

		// Date
		$html .= Html::rawElement( 'li', [],
			Html::element( 'strong', [], wfMessage( 'starlight-date-label' )->text() ) .
			' ' . $this->formatDate( $review['sr_timestamp'] )
		);

		$html .= Html::closeElement( 'ul' );

		// Review text
		if ( $review['sr_review_text'] ) {
			$html .= Html::element( 'p', [
				'class' => 'starlight-review-text',
			], $review['sr_review_text'] );
		}

		// Verification status
		$html .= $this->renderVerificationStatus( $review, $verifyStats, $user );

		// Action buttons
		$html .= $this->renderReviewActions( $review, $user, $canEdit );

		$html .= Html::closeElement( 'article' );

		return $html;
	}

	/**
	 * Render verification status and buttons.
	 *
	 * @param array $review
	 * @param array $verifyStats
	 * @param User $user
	 * @return string HTML
	 */
	private function renderVerificationStatus( array $review, array $verifyStats, User $user ): string {
		$html = Html::openElement( 'div', [
			'class' => 'starlight-verification',
		] );

		// Status text
		$html .= Html::element( 'p', [
			'class' => 'starlight-verify-status',
		], wfMessage( 'starlight-verify-status-text' )
			->params( wfMessage( 'starlight-verify-' . $verifyStats['status'] )->text() )
			->text() );

		// Vote counts (if threshold met)
		$threshold = $this->config->get( 'StarlightVerificationHideCountsUntilThreshold' );
		if ( $verifyStats['total'] >= $threshold ) {
			$html .= Html::element( 'p', [
				'class' => 'starlight-verify-count',
			], wfMessage( 'starlight-verify-count' )
				->numParams( $verifyStats['total'] )
				->text() );
		}

		// Verification buttons (for authorized users)
		if ( $this->verificationStore->canVerify( (int)$review['sr_id'], $user ) ) {
			$html .= $this->renderVerificationButtons( $review, $user );
		}

		$html .= Html::closeElement( 'div' );

		return $html;
	}

	/**
	 * Render verification voting buttons.
	 *
	 * @param array $review
	 * @param User $user
	 * @return string HTML
	 */
	private function renderVerificationButtons( array $review, User $user ): string {
		$userVote = $this->verificationStore->getUserVote(
			(int)$review['sr_id'],
			$user->getId()
		);
		$currentVerdict = $userVote ? $userVote['sv_verdict'] : null;

		$verdicts = [
			'true' => 'starlight-verify-btn-true',
			'mostly_true' => 'starlight-verify-btn-mostly-true',
			'mixed' => 'starlight-verify-btn-mixed',
			'mostly_false' => 'starlight-verify-btn-mostly-false',
			'false' => 'starlight-verify-btn-false',
			'inconclusive' => 'starlight-verify-btn-inconclusive',
		];

		$html = Html::openElement( 'fieldset', [
			'class' => 'starlight-verify-buttons',
		] );

		$html .= Html::element( 'legend', [],
			wfMessage( 'starlight-verify-legend' )->text() );

		foreach ( $verdicts as $verdict => $messageKey ) {
			$isSelected = $currentVerdict === $verdict;

			$html .= Html::element( 'button', [
				'type' => 'button',
				'class' => 'starlight-verify-btn' . ( $isSelected ? ' starlight-verify-btn-selected' : '' ),
				'data-verdict' => $verdict,
				'data-review-id' => $review['sr_id'],
				'aria-pressed' => $isSelected ? 'true' : 'false',
			], wfMessage( $messageKey )->text() );
		}

		$html .= Html::closeElement( 'fieldset' );

		return $html;
	}

	/**
	 * Render review action buttons (edit, delete).
	 *
	 * @param array $review
	 * @param User $user
	 * @param bool $canEdit
	 * @return string HTML
	 */
	private function renderReviewActions( array $review, User $user, bool $canEdit ): string {
		$html = Html::openElement( 'div', [
			'class' => 'starlight-review-actions',
		] );

		if ( $canEdit ) {
			$html .= Html::element( 'button', [
				'type' => 'button',
				'class' => 'starlight-action-edit',
				'data-review-id' => $review['sr_id'],
			], wfMessage( 'starlight-action-edit' )->text() );

			$html .= Html::element( 'button', [
				'type' => 'button',
				'class' => 'starlight-action-delete',
				'data-review-id' => $review['sr_id'],
			], wfMessage( 'starlight-action-delete' )->text() );
		}

		// Moderation actions
		if ( $user->isAllowed( 'starlight-moderate' ) ) {
			$html .= Html::element( 'button', [
				'type' => 'button',
				'class' => 'starlight-action-moderate',
				'data-review-id' => $review['sr_id'],
			], wfMessage( 'starlight-action-moderate' )->text() );
		}

		// Flag button
		if ( $user->isAllowed( 'starlight-flag' ) ) {
			$html .= Html::element( 'button', [
				'type' => 'button',
				'class' => 'starlight-action-flag',
				'data-review-id' => $review['sr_id'],
			], wfMessage( 'starlight-action-flag' )->text() );
		}

		$html .= Html::closeElement( 'div' );

		return $html;
	}

	/**
	 * Render pagination controls.
	 *
	 * @param int $pageId
	 * @param int $totalReviews
	 * @param int $limit
	 * @return string HTML
	 */
	private function renderPagination( int $pageId, int $totalReviews, int $limit ): string {
		$html = Html::openElement( 'div', [
			'class' => 'starlight-pagination',
			'data-total' => $totalReviews,
			'data-limit' => $limit,
		] );

		$html .= Html::element( 'button', [
			'type' => 'button',
			'class' => 'starlight-load-more',
		], wfMessage( 'starlight-load-more' )->text() );

		$html .= Html::closeElement( 'div' );

		return $html;
	}

	/**
	 * Format a timestamp for display in a human-readable format.
	 *
	 * @param string $timestamp MediaWiki timestamp
	 * @return string
	 */
	private function formatDate( string $timestamp ): string {
		$ts = wfTimestamp( TS_UNIX, $timestamp );
		$lang = RequestContext::getMain()->getLanguage();
		$user = RequestContext::getMain()->getUser();

		// Format as "January 30, 2026, 08:20 AM"
		return $lang->userDate( $ts, $user ) . ', ' . $lang->userTime( $ts, $user );
	}

	/**
	 * Check if a user can submit a review.
	 *
	 * @param User $user
	 * @param int $pageId
	 * @return bool
	 */
	private function canSubmitReview( User $user, int $pageId ): bool {
		// Check permission
		if ( !$user->isAllowed( 'starlight-submit' ) ) {
			return false;
		}

		// Check if anonymous reviews are allowed
		if ( !$user->isRegistered() && !$this->config->get( 'StarlightAllowAnonymous' ) ) {
			return false;
		}

		return true;
	}
}
