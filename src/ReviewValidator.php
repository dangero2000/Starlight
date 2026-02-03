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
	 * Always strips dangerous URL schemes (javascript:, data:, vbscript:, file:)
	 * regardless of policy to prevent XSS attacks.
	 *
	 * @param string $text
	 * @return string
	 */
	public function processLinks( string $text ): string {
		$policy = $this->config->get( 'StarlightLinkPolicy' );

		// Pattern to match dangerous URL schemes that could be used for XSS
		// These are always stripped regardless of policy
		$dangerousSchemePattern = '/\b(?:javascript|data|vbscript|file):[^\s<>\[\]"]*/i';

		// Standard URL pattern for http/https/ftp
		$urlPattern = '/\b(?:https?|ftp):\/\/[^\s<>\[\]]+/i';

		switch ( $policy ) {
			case 'allow':
				// Even in 'allow' mode, always strip dangerous script schemes
				return preg_replace(
					$dangerousSchemePattern,
					'[dangerous content removed]',
					$text
				);

			case 'strip':
				// Remove dangerous schemes first
				$text = preg_replace(
					$dangerousSchemePattern,
					'[link removed]',
					$text
				);
				// Then remove standard URLs
				return preg_replace(
					$urlPattern,
					'[link removed]',
					$text
				);

			case 'internal-only':
				// Strip dangerous schemes first
				$text = preg_replace(
					$dangerousSchemePattern,
					'[external link removed]',
					$text
				);
				// Then strip external http/https/ftp links
				return preg_replace(
					$urlPattern,
					'[external link removed]',
					$text
				);

			default:
				// Always sanitize dangerous schemes even with unknown policy
				return preg_replace(
					$dangerousSchemePattern,
					'[dangerous content removed]',
					$text
				);
		}
	}

	/**
	 * Sanitize text for display.
	 *
	 * Removes control characters, bidirectional override characters, and
	 * zero-width characters that could be used for spoofing or fingerprinting.
	 * Normalizes Unicode to NFC form for consistent storage and comparison.
	 *
	 * @param string $text
	 * @return string
	 */
	public function sanitize( string $text ): string {
		$text = trim( $text );

		// Remove control characters except newlines (\n = \x0A) and tabs (\t = \x09)
		// Control characters: \x00-\x08, \x0B, \x0C, \x0E-\x1F, \x7F
		// Also remove Unicode control characters in C1 range: \x80-\x9F
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}]/u', '', $text );

		// Remove Unicode bidirectional override characters that can be used for spoofing
		// LRE (U+202A), RLE (U+202B), PDF (U+202C), LRO (U+202D), RLO (U+202E)
		// LRI (U+2066), RLI (U+2067), FSI (U+2068), PDI (U+2069)
		$text = preg_replace( '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text );

		// Remove zero-width characters that can be used for fingerprinting or spoofing
		// Zero-width space (U+200B), zero-width non-joiner (U+200C),
		// zero-width joiner (U+200D), word joiner (U+2060), BOM (U+FEFF)
		$text = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\x{2060}]/u', '', $text );

		// Normalize Unicode to NFC form for consistent storage and comparison
		// This helps prevent homograph attacks using different Unicode representations
		if ( class_exists( 'Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $text, \Normalizer::FORM_C );
			if ( $normalized !== false ) {
				$text = $normalized;
			}
		}

		// Normalize line endings
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );

		// Limit consecutive newlines
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		return $text;
	}
}
