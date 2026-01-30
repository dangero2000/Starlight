<?php
/**
 * Session management for anonymous users.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight;

use MediaWiki\Context\RequestContext;
use MediaWiki\Request\WebRequest;

class SessionManager {

	private const COOKIE_SESSION = 'StarlightSession';
	private const COOKIE_NAME = 'StarlightName';
	private const TOKEN_LENGTH = 32;
	private const COOKIE_EXPIRY = 2592000; // 30 days in seconds

	/**
	 * Get the current web request.
	 *
	 * @return WebRequest
	 */
	private function getRequest(): WebRequest {
		return RequestContext::getMain()->getRequest();
	}

	/**
	 * Get existing session token or create a new one.
	 * Only call this when user has chosen to save a session cookie.
	 *
	 * @return string
	 */
	public function getOrCreateToken(): string {
		$request = $this->getRequest();
		$token = $request->getCookie( self::COOKIE_SESSION );

		if ( $token && $this->isValidToken( $token ) ) {
			return $token;
		}

		// Generate new token
		$token = bin2hex( random_bytes( self::TOKEN_LENGTH ) );

		// Set cookie
		$request->response()->setCookie(
			self::COOKIE_SESSION,
			$token,
			time() + self::COOKIE_EXPIRY,
			[
				'httpOnly' => true,
				'secure' => $request->getProtocol() === 'https',
				'sameSite' => 'Lax',
			]
		);

		return $token;
	}

	/**
	 * Get current session token without creating one.
	 *
	 * @return string|null
	 */
	public function getCurrentToken(): ?string {
		$token = $this->getRequest()->getCookie( self::COOKIE_SESSION );

		if ( $token && $this->isValidToken( $token ) ) {
			return $token;
		}

		return null;
	}

	/**
	 * Validate token format.
	 *
	 * @param string $token
	 * @return bool
	 */
	private function isValidToken( string $token ): bool {
		// Token should be a hex string of the correct length
		return preg_match( '/^[a-f0-9]{' . ( self::TOKEN_LENGTH * 2 ) . '}$/', $token ) === 1;
	}

	/**
	 * Check if user can edit a specific review.
	 *
	 * @param array $review Review data
	 * @param \MediaWiki\User\User $user Current user
	 * @return bool
	 */
	public function canEditReview( array $review, $user ): bool {
		// Logged-in users can edit their own reviews
		if ( $user->isRegistered() && (int)$review['sr_user_id'] === $user->getId() ) {
			return true;
		}

		// Reviews without session tokens cannot be edited by anonymous users
		if ( empty( $review['sr_session_token'] ) ) {
			return false;
		}

		// Anonymous users can edit if they have the matching session token
		if ( !$user->isRegistered() ) {
			$currentToken = $this->getCurrentToken();
			return $currentToken && $currentToken === $review['sr_session_token'];
		}

		return false;
	}

	/**
	 * Check if a review was submitted without a session cookie.
	 *
	 * @param array $review
	 * @return bool
	 */
	public function isAnonymousWithoutSession( array $review ): bool {
		return $review['sr_user_id'] === null && empty( $review['sr_session_token'] );
	}

	/**
	 * Get the persistent name for this anonymous user.
	 *
	 * @return string|null
	 */
	public function getPersistentName(): ?string {
		return $this->getRequest()->getCookie( self::COOKIE_NAME );
	}

	/**
	 * Save a persistent name for future reviews.
	 *
	 * @param string $name
	 */
	public function setPersistentName( string $name ): void {
		$request = $this->getRequest();

		$request->response()->setCookie(
			self::COOKIE_NAME,
			$name,
			time() + self::COOKIE_EXPIRY,
			[
				'httpOnly' => true,
				'secure' => $request->getProtocol() === 'https',
				'sameSite' => 'Lax',
			]
		);
	}

	/**
	 * Clear the persistent name.
	 */
	public function clearPersistentName(): void {
		$this->getRequest()->response()->clearCookie( self::COOKIE_NAME );
	}

	/**
	 * Check if user has a persistent name saved.
	 *
	 * @return bool
	 */
	public function hasPersistentName(): bool {
		return $this->getPersistentName() !== null;
	}

	/**
	 * Hash an IP address for storage.
	 *
	 * @param string $ip
	 * @return string
	 */
	public function hashIP( string $ip ): string {
		// Use a one-way hash with a salt
		// The salt should ideally come from configuration
		return hash( 'sha256', $ip . 'starlight_salt' );
	}
}
