/**
 * Verification module - handles verification voting
 *
 * @license AGPL-3.0-or-later
 */
( function () {
	'use strict';

	var starlightVerify = {
		api: null,

		init: function () {
			this.api = new mw.Api();
			this.bindEvents();
		},

		bindEvents: function () {
			var self = this;

			// Verification button clicks
			$( document ).on( 'click', '.starlight-verify-btn', function () {
				var $btn = $( this );
				var reviewId = $btn.data( 'review-id' );
				var verdict = $btn.data( 'verdict' );

				self.submitVote( reviewId, verdict, $btn );
			} );
		},

		submitVote: function ( reviewId, verdict, $btn ) {
			var self = this;
			var $container = $btn.closest( '.starlight-verify-buttons' );
			var $review = $btn.closest( '.starlight-review' );

			// Disable buttons during submission
			$container.find( '.starlight-verify-btn' ).prop( 'disabled', true );

			this.api.postWithToken( 'csrf', {
				action: 'starlightverify',
				reviewid: reviewId,
				verdict: verdict
			} ).done( function ( response ) {
				$container.find( '.starlight-verify-btn' ).prop( 'disabled', false );

				if ( response.starlightverify && response.starlightverify.success ) {
					// Update button states and aria-pressed
					$container.find( '.starlight-verify-btn' )
						.removeClass( 'starlight-verify-btn-selected' )
						.attr( 'aria-pressed', 'false' );
					$btn.addClass( 'starlight-verify-btn-selected' )
						.attr( 'aria-pressed', 'true' );

					// Update verification status text if stats returned
					if ( response.starlightverify.verification ) {
						self.updateStatusDisplay( $review, response.starlightverify.verification );
					}

					// Show brief confirmation
					self.showVoteConfirmation( $container, verdict );
				}
			} ).fail( function ( code, result ) {
				$container.find( '.starlight-verify-btn' ).prop( 'disabled', false );
				var msg = result.error && result.error.info || mw.msg( 'starlight-error-verify-failed' );
				mw.notify( msg, { type: 'error' } );
			} );
		},

		updateStatusDisplay: function ( $review, verification ) {
			var $statusElement = $review.find( '.starlight-verify-status' );
			if ( $statusElement.length ) {
				// Get the status message
				var statusKey = 'starlight-verify-' + verification.status;
				var statusText = mw.msg( statusKey );
				var fullText = mw.msg( 'starlight-verify-status-text', statusText );

				$statusElement.text( fullText );

				// Update status class for styling
				$statusElement
					.removeClass( 'starlight-verify-status-unverified starlight-verify-status-pending starlight-verify-status-accurate starlight-verify-status-mostly-accurate starlight-verify-status-mixed starlight-verify-status-mostly-inaccurate starlight-verify-status-inaccurate' )
					.addClass( 'starlight-verify-status-' + verification.status );
			}
		},

		showVoteConfirmation: function ( $container, verdict ) {
			var $confirmation = $container.find( '.starlight-verify-confirmation' );
			if ( !$confirmation.length ) {
				$confirmation = $( '<span class="starlight-verify-confirmation"></span>' );
				$container.append( $confirmation );
			}

			$confirmation.text( '✓' ).fadeIn( 200 ).delay( 1000 ).fadeOut( 200 );
		}
	};

	mw.hook( 'wikipage.content' ).add( function () {
		if ( $( '.starlight-verify-buttons' ).length ) {
			starlightVerify.init();
		}
	} );

	mw.starlight = mw.starlight || {};
	mw.starlight.verify = starlightVerify;

}() );
