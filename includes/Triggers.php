<?php
/**
 * This file holds the triggers for the notifications this extension defines.
 * It would usually be named "Hooks" since it uses hooks to trigger the notifications,
 * but for the sake of this demo and clarity of operation, I'm calling it "Triggers".
 * 
 * Presentation model can be represented through the notification.json definition
 * or through the 'create' call because all available prarametrs are available
 * and are calculated when the notification is created.
 * See https://www.mediawiki.org/wiki/Extension:Echo/Creating_a_new_notification_type#Creating_a_presentation_model
 */

namespace MediaWiki\Extension\SampNotifExtension;

use MediaWiki\Hook\PageSaveComplete;
use MediaWiki\Hook\LocalUserCreated;
use MediaWiki\Hook\LinksUpdateComplete;
use MediaWiki\Extension\Notifications\EchoEvent;

class Hooks implements
	PageSaveComplete,
	LocalUserCreated,
	LinksUpdateComplete
{
	public function onPageSaveComplete(
		$wikiPage,
		$userIdentity,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	) {
		if ( $editResult->isNullEdit() ) {
			return;
		}

		// [ ... logic ... ]

		// If the user is not an IP and this is not a null edit,
		// test for them reaching a congratulatory threshold
		$thresholds = [ 1, 10, 100, 1000, 10000, 100000, 1000000, 10000000 ];
		if ( $userIdentity->isRegistered() ) {
			$thresholdCount = $this->getEditCount( $userIdentity );
			if ( in_array( $thresholdCount, $thresholds ) ) {
				DeferredUpdates::addCallableUpdate( static function () use (
					$revisionRecord, $userIdentity, $title, $thresholdCount
				) {
					// [ ... logic to prevent thanking the user more than once ... ]

					// This used to be in the presentation model, but it should be
					// used to know how to create the requested notification:
					if ($title) {
						if ( $this->event->getExtraParam( 'revid' ) ) {
							$params = [
								'oldid' => 'prev',
								'diff' => $revisionRecord->getId()
							];
						} else {
							$params = [];
						}
						$url = $title->getLocalURL( $params );
					}
			
					Notification::create( [
						'type' => 'thank-you-edit',
						'title' => $title,
						'agent' => $userIdentity,
						// Edit threshold notifications are sent to the agent
						'extra' => [
							'editCount' => $thresholdCount,
							'revid' => $revisionRecord->getId(),
						],
						'presentation' => [
							// Overriding (or augmenting) the default definition so we can give the correct
							// i18n key
							'body' => [
								'msg' => 'notification-header-thank-you-' + $thresholdCount + '-edit',
								'params' => [ $userIdentity ]
							],
							'links' => [
								[
									'url' => $url,
									'label' => $this->msg( 'notification-link-thank-you-edit', $this->getViewingUserForGender() )->text()
								]
							]
						]
					] );
				} );
			}
		}

	}

	public function onLocalUserCreated( $user, $autocreated ) {
		if ( !$autocreated ) {

			// Moved from Presentation Model; the logic should be
			// where the code calls the creation of the notification
			$primaryLink = []
			$msg = $this->msg( 'notification-welcome-link' );

			if ( !$msg->isDisabled() && $title ) {
				$title = Title::newFromText( $msg->plain() );
				$primaryLink = [
					'url' => $title->getFullURL(),
					'label' => $this->msg( 'notification-welcome-linktext' )->text(),
				];
			}

			Notification::create( [
				'type' => 'welcome',
				'agent' => $user,
				'presentation' => [
					'links' => [
						$primaryLink
					]
				]
			] );
		}
	}

	public function onLinksUpdateComplete( $linksUpdate, $ticket ) {
		// Rollback or undo should not trigger link notification
		if ( $linksUpdate->getRevisionRecord() ) {
			$revId = $linksUpdate->getRevisionRecord()->getId();
			if ( isset( self::$revertedRevIds[$revId] ) ) {
				return;
			}
		}

		// Handle only
		// 1. content namespace pages &&
		// 2. non-transcluding pages &&
		// 3. non-redirect pages
		if ( !$this->namespaceInfo->isContent( $linksUpdate->getTitle()->getNamespace() )
			|| !$linksUpdate->isRecursive() || $linksUpdate->getTitle()->isRedirect()
		) {
			return;
		}

		$revRecord = $linksUpdate->getRevisionRecord();
		$revid = $revRecord ? $revRecord->getId() : null;
		$user = $revRecord ? $revRecord->getUser() : null;

		// link notification is boundless as you can include infinite number of links in a page
		// db insert is expensive, limit it to a reasonable amount, we can increase this limit
		// once the storage is on Redis
		$max = 10;
		// Only create notifications for links to content namespace pages
		// @Todo - use one big insert instead of individual insert inside foreach loop
		foreach ( $linksUpdate->getPageReferenceIterator( 'pagelinks', LinksTable::INSERTED ) as $pageReference ) {
			if ( $this->namespaceInfo->isContent( $pageReference->getNamespace() ) ) {
				$title = Title::newFromPageReference( $pageReference );
				if ( $title->isRedirect() ) {
					continue;
				}

				$linkFromPageId = $linksUpdate->getTitle()->getArticleID();
				// T318523: Don't send page-linked notifications for pages created by bot users.
				$articleAuthor = UserLocator::getArticleAuthorByArticleId( $title->getArticleID() );
				if ( $articleAuthor && $articleAuthor->isBot() ) {
					continue;
				}

				$bundleString = $event->getType();
				if ( $event->getTitle() ) {
					$bundleString .= '-' . $event->getTitle()->getNamespace()
						. '-' . $event->getTitle()->getDBkey();
				}

				Notification::create( [
					'type' => 'page-linked',
					'title' => $title,
					'agent' => $user,
					'extra' => [
						'target-page' => $linkFromPageId,
						'link-from-page-id' => $linkFromPageId,
						'revid' => $revid,
					],
					// Bundle-id is created here, since there are rules
					// on how to bundle notifications of this type based
					// on the page title, rather than all notifications
					// of this type generally
					"bundle-id" => $bundleString
				] );
				$max--;
			}
			if ( $max < 0 ) {
				break;
			}
		}
	}

}