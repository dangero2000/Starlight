<?php
/**
 * Security event logging for the Starlight extension.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight;

use MediaWiki\User\User;
use Psr\Log\LoggerInterface;

class SecurityLogger {

	private LoggerInterface $logger;

	public function __construct( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Log a failed authentication attempt (e.g., session token mismatch).
	 *
	 * @param User $user
	 * @param int $reviewId
	 * @param string $action The action attempted (edit, delete)
	 * @param string $ip Client IP address
	 */
	public function logAuthFailure( User $user, int $reviewId, string $action, string $ip ): void {
		$this->logger->warning(
			'Starlight: Failed {action} authentication for review {reviewId}',
			[
				'action' => $action,
				'reviewId' => $reviewId,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'ip' => $ip,
				'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
			]
		);
	}

	/**
	 * Log a rate limit violation.
	 *
	 * @param User $user
	 * @param string $action The rate-limited action
	 * @param string $ip Client IP address
	 */
	public function logRateLimitHit( User $user, string $action, string $ip ): void {
		$this->logger->notice(
			'Starlight: Rate limit hit for {action}',
			[
				'action' => $action,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'ip' => $ip,
			]
		);
	}

	/**
	 * Log suspicious flagging activity.
	 *
	 * @param User $user
	 * @param int $reviewId
	 * @param string $reason Flag reason
	 * @param string $ip Client IP address
	 */
	public function logSuspiciousFlag( User $user, int $reviewId, string $reason, string $ip ): void {
		$this->logger->warning(
			'Starlight: Suspicious flagging activity on review {reviewId}',
			[
				'reviewId' => $reviewId,
				'reason' => $reason,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
				'ip' => $ip,
			]
		);
	}

	/**
	 * Log a successful security-sensitive action.
	 *
	 * @param User $user
	 * @param string $action The action performed
	 * @param array $context Additional context
	 */
	public function logSecurityAction( User $user, string $action, array $context = [] ): void {
		$this->logger->info(
			'Starlight: Security action {action}',
			array_merge( [
				'action' => $action,
				'userId' => $user->getId(),
				'userName' => $user->getName(),
			], $context )
		);
	}

	/**
	 * Log dangerous content that was stripped from input.
	 *
	 * @param string $contentType Type of content (e.g., 'javascript_url', 'control_chars')
	 * @param string $ip Client IP address
	 */
	public function logDangerousContent( string $contentType, string $ip ): void {
		$this->logger->notice(
			'Starlight: Dangerous content stripped: {contentType}',
			[
				'contentType' => $contentType,
				'ip' => $ip,
			]
		);
	}
}
