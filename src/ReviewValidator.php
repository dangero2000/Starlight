<?php
/**
 * Input validation for reviews.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight;

use MediaWiki\Config\Config;
use StatusValue;

class ReviewValidator {

	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Validate review data.
	 *
	 * @param array $data
	 * @return StatusValue
	 */
	public function validate( array $data ): StatusValue {
		$status = StatusValue::newGood();

		// Validate rating
		$ratingStatus = $this->validateRating( $data['rating'] ?? null );
		if ( !$ratingStatus->isGood() ) {
			$status->merge( $ratingStatus );
		}

		// Validate name
		$nameStatus = $this->validateName( $data['name'] ?? '' );
		if ( !$nameStatus->isGood() ) {
			$status->merge( $nameStatus );
		}

		// Validate experience
		$experienceStatus = $this->validateExperience( $data['experience'] ?? '' );
		if ( !$experienceStatus->isGood() ) {
			$status->merge( $experienceStatus );
		}

		// Validate review text
		$textStatus = $this->validateText( $data['text'] ?? '' );
		if ( !$textStatus->isGood() ) {
			$status->merge( $textStatus );
		}

		return $status;
	}

	/**
	 * Validate rating.
	 *
	 * @param mixed $rating
	 * @return StatusValue
	 */
	public function validateRating( $rating ): StatusValue {
		if ( $rating === null ) {
			return StatusValue::newFatal( 'starlight-error-rating-required' );
		}

		$rating = (int)$rating;
		if ( $rating < 1 || $rating > 5 ) {
			return StatusValue::newFatal( 'starlight-error-invalid-rating' );
		}

		return StatusValue::newGood();
	}

	/**
	 * Validate reviewer name.
	 *
	 * @param string $name
	 * @return StatusValue
	 */
	public function validateName( string $name ): StatusValue {
		$name = trim( $name );

		if ( $name === '' ) {
			return StatusValue::newFatal( 'starlight-error-name-required' );
		}

		$maxLength = $this->config->get( 'StarlightMaxNameLength' );
		if ( mb_strlen( $name ) > $maxLength ) {
			return StatusValue::newFatal( 'starlight-error-name-too-long', $maxLength );
		}

		return StatusValue::newGood();
	}

	/**
	 * Validate experience field.
	 *
	 * @param string $experience
	 * @return StatusValue
	 */
	public function validateExperience( string $experience ): StatusValue {
		$experience = trim( $experience );

		if ( $experience === '' ) {
			return StatusValue::newFatal( 'starlight-error-experience-required' );
		}

		$maxLength = $this->config->get( 'StarlightMaxExperienceLength' );
		if ( mb_strlen( $experience ) > $maxLength ) {
			return StatusValue::newFatal( 'starlight-error-experience-too-long', $maxLength );
		}

		return StatusValue::newGood();
	}

	/**
	 * Validate review text.
	 *
	 * @param string $text
	 * @return StatusValue
	 */
	public function validateText( string $text ): StatusValue {
		$text = trim( $text );

		$requireText = $this->config->get( 'StarlightRequireReviewText' );
		if ( $requireText && $text === '' ) {
			return StatusValue::newFatal( 'starlight-error-text-required' );
		}

		$minLength = $this->config->get( 'StarlightMinReviewLength' );
		if ( $requireText && mb_strlen( $text ) < $minLength ) {
			return StatusValue::newFatal( 'starlight-error-text-too-short', $minLength );
		}

		$maxLength = $this->config->get( 'StarlightMaxReviewLength' );
		if ( mb_strlen( $text ) > $maxLength ) {
			return StatusValue::newFatal( 'starlight-error-text-too-long', $maxLength );
		}

		return StatusValue::newGood();
	}

	/**
	 * Process links in review text according to policy.
	 *
	 * @param string $text
	 * @return string
	 */
	public function processLinks( string $text ): string {
		$policy = $this->config->get( 'StarlightLinkPolicy' );

		switch ( $policy ) {
			case 'allow':
				return $text;

			case 'strip':
				// Remove all URLs
				return preg_replace(
					'/https?:\/\/[^\s<>\[\]]+/',
					'[link removed]',
					$text
				);

			case 'internal-only':
				// This would require knowing the wiki's domain
				// For now, strip all external links
				return preg_replace(
					'/https?:\/\/[^\s<>\[\]]+/',
					'[external link removed]',
					$text
				);

			default:
				return $text;
		}
	}

	/**
	 * Sanitize text for display.
	 *
	 * @param string $text
	 * @return string
	 */
	public function sanitize( string $text ): string {
		// Basic sanitization - MediaWiki's output escaping handles XSS
		$text = trim( $text );

		// Normalize line endings
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );

		// Limit consecutive newlines
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		return $text;
	}
}
