/**
 * Main reviews module - handles display and interactions
 *
 * @license AGPL-3.0-or-later
 */
( function () {
	'use strict';

	var starlight = {
		api: null,

		init: function () {
			this.api = new mw.Api();
			this.bindEvents();
		},

		bindEvents: function () {
			var self = this;

			// Sort control
			$( document ).on( 'change', '.starlight-sort-select', function () {
				var $section = $( this ).closest( '.starlight-reviews-section' );
				var pageId = $section.data( 'page-id' );
				var sort = $( this ).val();
				self.loadReviews( $section, pageId, sort, 0 );
			} );

			// Load more button
			$( document ).on( 'click', '.starlight-load-more', function () {
				var $section = $( this ).closest( '.starlight-reviews-section' );
				var pageId = $section.data( 'page-id' );
				var sort = $section.data( 'sort' );
				var $list = $section.find( '.starlight-reviews-list' );
				var offset = $list.find( '.starlight-review' ).length;
				self.loadMoreReviews( $section, pageId, sort, offset );
			} );

			// Expand/collapse review text
			$( document ).on( 'click', '.starlight-review-collapsed', function ( e ) {
				if ( !$( e.target ).is( 'button, a' ) ) {
					$( this ).removeClass( 'starlight-review-collapsed' );
				}
			} );

			// Edit button
			$( document ).on( 'click', '.starlight-action-edit', function () {
				var reviewId = $( this ).data( 'review-id' );
				self.showEditForm( reviewId );
			} );

			// Delete button
			$( document ).on( 'click', '.starlight-action-delete', function () {
				var reviewId = $( this ).data( 'review-id' );
				self.confirmDelete( reviewId );
			} );

			// Flag button
			$( document ).on( 'click', '.starlight-action-flag', function () {
				var reviewId = $( this ).data( 'review-id' );
				self.showFlagDialog( reviewId );
			} );
		},

		loadReviews: function ( $section, pageId, sort, offset ) {
			var self = this;
			var $list = $section.find( '.starlight-reviews-list' );

			$list.addClass( 'starlight-loading' );

			this.api.get( {
				action: 'starlightlist',
				pageid: pageId,
				sort: sort,
				limit: 10,
				offset: offset,
				render: true
			} ).done( function ( response ) {
				$list.removeClass( 'starlight-loading' );
				if ( response.starlightlist && response.starlightlist.html ) {
					$list.html( response.starlightlist.html );
					$section.data( 'sort', sort );
				}
			} ).fail( function () {
				$list.removeClass( 'starlight-loading' );
				mw.notify( mw.msg( 'starlight-error-loading' ), { type: 'error' } );
			} );
		},

		loadMoreReviews: function ( $section, pageId, sort, offset ) {
			var self = this;
			var $list = $section.find( '.starlight-reviews-list' );
			var $button = $section.find( '.starlight-load-more' );

			$button.prop( 'disabled', true );

			this.api.get( {
				action: 'starlightlist',
				pageid: pageId,
				sort: sort,
				limit: 10,
				offset: offset,
				render: true
			} ).done( function ( response ) {
				$button.prop( 'disabled', false );
				if ( response.starlightlist && response.starlightlist.html ) {
					$list.append( response.starlightlist.html );

					// Hide button if no more reviews
					var total = response.starlightlist.total;
					var loaded = $list.find( '.starlight-review' ).length;
					if ( loaded >= total ) {
						$button.hide();
					}
				}
			} ).fail( function () {
				$button.prop( 'disabled', false );
				mw.notify( mw.msg( 'starlight-error-loading' ), { type: 'error' } );
			} );
		},

		showEditForm: function ( reviewId ) {
			// TODO: Implement edit form modal
			mw.notify( 'Edit form coming soon', { type: 'info' } );
		},

		confirmDelete: function ( reviewId ) {
			var self = this;

			OO.ui.confirm( mw.msg( 'starlight-confirm-delete' ) ).done( function ( confirmed ) {
				if ( confirmed ) {
					self.deleteReview( reviewId );
				}
			} );
		},

		deleteReview: function ( reviewId ) {
			var self = this;

			this.api.postWithToken( 'csrf', {
				action: 'starlightdelete',
				reviewid: reviewId
			} ).done( function ( response ) {
				if ( response.starlightdelete && response.starlightdelete.success ) {
					$( '.starlight-review[data-review-id="' + reviewId + '"]' ).fadeOut( function () {
						$( this ).remove();
					} );
					mw.notify( mw.msg( 'starlight-success-deleted' ), { type: 'success' } );
				}
			} ).fail( function ( code, result ) {
				var msg = result.error && result.error.info || mw.msg( 'starlight-error-delete-failed' );
				mw.notify( msg, { type: 'error' } );
			} );
		},

		showFlagDialog: function ( reviewId ) {
			// TODO: Implement flag dialog
			mw.notify( 'Flag dialog coming soon', { type: 'info' } );
		}
	};

	mw.hook( 'wikipage.content' ).add( function () {
		if ( $( '.starlight-reviews-section' ).length ) {
			starlight.init();
		}
	} );

	// Export for other modules
	mw.starlight = mw.starlight || {};
	mw.starlight.reviews = starlight;

}() );
