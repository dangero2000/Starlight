<?php
/**
 * API module for submitting reviews.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight\Api;

use ApiBase;
use MediaWiki\Extension\Starlight\NameGenerator;
use MediaWiki\Extension\Starlight\ReviewStore;
use MediaWiki\Extension\Starlight\ReviewValidator;
use MediaWiki\Extension\Starlight\SessionManager;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

class ApiReviewSubmit extends ApiBase {

	private ReviewStore $reviewStore;
	private ReviewValidator $validator;
	private SessionManager $sessionManager;
	private NameGenerator $nameGenerator;

	public function __construct(
		$mainModule,
		$moduleName,
		ReviewStore $reviewStore,
		ReviewValidator $validator,
		SessionManager $sessionManager,
		NameGenerator $nameGenerator
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->reviewStore = $reviewStore;
		$this->validator = $validator;
		$this->sessionManager = $sessionManager;
		$this->nameGenerator = $nameGenerator;
	}

	public function execute() {
		$user = $this->getUser();
		$params = $this->extractRequestParams();

		// Check permission
		$this->checkUserRightsAny( 'starlight-submit' );

		// Check anonymous submissions
		if ( !$user->isRegistered() ) {
			$config = $this->getConfig();
			if ( !$config->get( 'StarlightAllowAnonymous' ) ) {
				$this->dieWithError( 'starlight-error-anonymous-disabled' );
			}
		}

		// Validate input
		$validationResult = $this->validator->validate( [
			'rating' => $params['rating'],
			'name' => $params['name'],
			'experience' => $params['experience'],
			'text' => $params['text'],
		] );

		if ( !$validationResult->isGood() ) {
			$this->dieStatus( $validationResult );
		}

		// Check rate limiting
		$ipHash = $user->isRegistered() ? null : $this->sessionManager->hashIP(
			$this->getRequest()->getIP()
		);

		if ( $this->reviewStore->isRateLimited(
			$user->isRegistered() ? $user->getId() : null,
			$ipHash
		) ) {
			$this->dieWithError( 'starlight-error-rate-limited' );
		}

		// Handle session token for anonymous users
		$sessionToken = null;
		if ( !$user->isRegistered() && $params['remember'] ) {
			$sessionToken = $this->sessionManager->getOrCreateToken();

			// Save persistent name if requested
			if ( $params['savename'] ) {
				$this->nameGenerator->savePersistentName( $params['name'] );
			}
		}

		// Process links according to policy
		$reviewText = $this->validator->processLinks( $params['text'] );
		$reviewText = $this->validator->sanitize( $reviewText );

		// Create the review
		$reviewId = $this->reviewStore->createReview( [
			'page_id' => $params['pageid'],
			'user_id' => $user->isRegistered() ? $user->getId() : null,
			'session_token' => $sessionToken,
			'reviewer_name' => $this->validator->sanitize( $params['name'] ),
			'rating' => $params['rating'],
			'experience' => $this->validator->sanitize( $params['experience'] ),
			'review_text' => $reviewText,
			'ip_hash' => $ipHash,
		] );

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'success' => true,
			'reviewid' => $reviewId,
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
			'pageid' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'rating' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => 5,
			],
			'name' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'experience' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'text' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
			'remember' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
			'savename' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=starlightsubmit&pageid=123&rating=5&name=Happy%20Customer&experience=Used%20for%202%20years&text=Great%20product!&token=abc123'
				=> 'apihelp-starlightsubmit-example',
		];
	}
}
