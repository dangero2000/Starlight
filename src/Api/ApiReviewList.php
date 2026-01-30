<?php
/**
 * API module for listing reviews.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight\Api;

use ApiBase;
use MediaWiki\Extension\Starlight\ReviewFormatter;
use MediaWiki\Extension\Starlight\ReviewStore;
use MediaWiki\Extension\Starlight\SessionManager;
use MediaWiki\Extension\Starlight\VerificationStore;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

class ApiReviewList extends ApiBase {

	private ReviewStore $reviewStore;
	private ReviewFormatter $formatter;
	private VerificationStore $verificationStore;
	private SessionManager $sessionManager;

	public function __construct(
		$mainModule,
		$moduleName,
		ReviewStore $reviewStore,
		ReviewFormatter $formatter,
		VerificationStore $verificationStore,
		SessionManager $sessionManager
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->reviewStore = $reviewStore;
		$this->formatter = $formatter;
		$this->verificationStore = $verificationStore;
		$this->sessionManager = $sessionManager;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$user = $this->getUser();

		// Get reviews
		$reviews = $this->reviewStore->getReviewsForPage(
			$params['pageid'],
			$params['sort'],
			$params['limit'],
			$params['offset']
		);

		// Get page stats
		$stats = $this->reviewStore->getPageStats( $params['pageid'] );

		// Format reviews for output
		$formattedReviews = [];
		foreach ( $reviews as $review ) {
			$verifyStats = $this->verificationStore->getVerificationStats( $review );
			$canEdit = $this->sessionManager->canEditReview( $review, $user );

			$formattedReviews[] = [
				'id' => (int)$review['sr_id'],
				'rating' => (int)$review['sr_rating'],
				'name' => $review['sr_reviewer_name'],
				'experience' => $review['sr_experience'],
				'text' => $review['sr_review_text'],
				'timestamp' => wfTimestamp( TS_ISO_8601, $review['sr_timestamp'] ),
				'canEdit' => $canEdit,
				'verification' => $verifyStats,
			];
		}

		// Return HTML if requested
		if ( $params['render'] ) {
			$html = '';
			foreach ( $reviews as $review ) {
				$html .= $this->formatter->renderReview( $review, $user, true );
			}

			$this->getResult()->addValue( null, $this->getModuleName(), [
				'reviews' => $formattedReviews,
				'html' => $html,
				'total' => $stats ? (int)$stats['sps_review_count'] : 0,
				'avgrating' => $stats ? (float)$stats['sps_avg_rating'] : null,
			] );
		} else {
			$this->getResult()->addValue( null, $this->getModuleName(), [
				'reviews' => $formattedReviews,
				'total' => $stats ? (int)$stats['sps_review_count'] : 0,
				'avgrating' => $stats ? (float)$stats['sps_avg_rating'] : null,
			] );
		}
	}

	public function getAllowedParams() {
		return [
			'pageid' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'sort' => [
				ParamValidator::PARAM_TYPE => [
					'smart', 'newest', 'oldest',
					'highest-rating', 'lowest-rating', 'most-verified'
				],
				ParamValidator::PARAM_DEFAULT => 'smart',
			],
			'limit' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_DEFAULT => 10,
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => 50,
			],
			'offset' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_DEFAULT => 0,
				IntegerDef::PARAM_MIN => 0,
			],
			'render' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=starlightlist&pageid=123'
				=> 'apihelp-starlightlist-example-basic',
			'action=starlightlist&pageid=123&sort=newest&limit=20&render=true'
				=> 'apihelp-starlightlist-example-rendered',
		];
	}
}
