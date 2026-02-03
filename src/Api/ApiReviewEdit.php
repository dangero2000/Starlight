<?php
/**
 * API module for editing reviews.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight\Api;

use ApiBase;
use MediaWiki\Extension\Starlight\ReviewStore;
use MediaWiki\Extension\Starlight\ReviewValidator;
use MediaWiki\Extension\Starlight\SessionManager;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

class ApiReviewEdit extends ApiBase {

	private ReviewStore $reviewStore;
	private ReviewValidator $validator;
	private SessionManager $sessionManager;

	public function __construct(
		$mainModule,
		$moduleName,
		ReviewStore $reviewStore,
		ReviewValidator $validator,
		SessionManager $sessionManager
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->reviewStore = $reviewStore;
		$this->validator = $validator;
		$this->sessionManager = $sessionManager;
	}

	public function execute() {
		$user = $this->getUser();
		$params = $this->extractRequestParams();

		// Check rate limit before any processing
		if ( $user->pingLimiter( 'starlight-edit' ) ) {
			$this->dieWithError( 'apierror-ratelimited' );
		}

		// Get the review
		$review = $this->reviewStore->getReview( $params['reviewid'] );
		if ( !$review ) {
			$this->dieWithError( 'starlight-error-review-not-found' );
		}

		// Check permission to edit
		if ( !$this->sessionManager->canEditReview( $review, $user ) ) {
			// Increment failure rate limiter to prevent brute-force token guessing
			$user->pingLimiter( 'starlight-edit-fail' );

			// Check if user has moderate permission as fallback
			if ( !$this->getAuthority()->isAllowed( 'starlight-moderate' ) ) {
				$this->dieWithError( 'starlight-error-cannot-edit' );
			}
		}

		// Build update data
		$updateData = [];

		if ( $params['rating'] !== null ) {
			$ratingResult = $this->validator->validateRating( $params['rating'] );
			if ( !$ratingResult->isGood() ) {
				$this->dieStatus( $ratingResult );
			}
			$updateData['rating'] = $params['rating'];
		}

		if ( $params['name'] !== null ) {
			$nameResult = $this->validator->validateName( $params['name'] );
			if ( !$nameResult->isGood() ) {
				$this->dieStatus( $nameResult );
			}
			$updateData['reviewer_name'] = $this->validator->sanitize( $params['name'] );
		}

		if ( $params['experience'] !== null ) {
			$experienceResult = $this->validator->validateExperience( $params['experience'] );
			if ( !$experienceResult->isGood() ) {
				$this->dieStatus( $experienceResult );
			}
			$updateData['experience'] = $this->validator->sanitize( $params['experience'] );
		}

		if ( $params['text'] !== null ) {
			$textResult = $this->validator->validateText( $params['text'] );
			if ( !$textResult->isGood() ) {
				$this->dieStatus( $textResult );
			}
			$reviewText = $this->validator->processLinks( $params['text'] );
			$updateData['review_text'] = $this->validator->sanitize( $reviewText );
		}

		if ( empty( $updateData ) ) {
			$this->dieWithError( 'starlight-error-no-changes' );
		}

		// Perform the update
		$success = $this->reviewStore->updateReview(
			$params['reviewid'],
			$updateData,
			$user->getId()
		);

		if ( !$success ) {
			$this->dieWithError( 'starlight-error-update-failed' );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'success' => true,
			'reviewid' => $params['reviewid'],
		] );
	}

	public function needsToken() {
		return 'csrf';
	}

	public function isWriteMode() {
		return true;
	}

	public function getAllowedParams() {
		return [
			'reviewid' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'rating' => [
				ParamValidator::PARAM_TYPE => 'integer',
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => 5,
			],
			'name' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'experience' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'text' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=starlightedit&reviewid=456&rating=4&token=abc123'
				=> 'apihelp-starlightedit-example',
		];
	}
}
