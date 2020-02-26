<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2020 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
 * @file
 * Hooks related to file uploads.
 */

use MediaWiki\Moderation\ConsequenceUtils;
use MediaWiki\Moderation\QueueUploadConsequence;

class ModerationUploadHooks {

	/**
	 * Intercept image uploads and queue them for moderation.
	 * @param UploadBase $upload
	 * @param User $user
	 * @param mixed $__unused
	 * @param string $comment
	 * @param string $pageText
	 * @param array &$error
	 * @return bool
	 */
	public static function onUploadVerifyUpload( $upload, $user, $__unused,
		$comment, $pageText, &$error
	) {
		if ( ModerationCanSkip::canUploadSkip( $user ) ) {
			return true;
		}

		$manager = ConsequenceUtils::getManager();
		$error = $manager->add( new QueueUploadConsequence(
			$upload, $user, $comment, $pageText
		) );
		if ( $error ) {
			// Failed. Reason has been placed into &$error.
			return true;
		}

		if ( $user->isLoggedIn() ) {
			/* Watch/Unwatch this file immediately:
				watchlist is the user's own business,
				no reason to wait for approval of the upload */
			$watch = $user->getRequest()->getBool( 'wpWatchthis' );
			WatchAction::doWatchOrUnwatch( $watch, $upload->getTitle(), $user );
		}

		/* Display user-friendly results page if the upload was caused
			by Special:Upload (not API, other extension, etc.) */
		$errorMsg = 'moderation-image-queued';
		ModerationQueuedSuccessException::throwIfNeeded( $errorMsg );

		// Return machine-readable error if this is NOT Special:Upload.
		$error = [ $errorMsg ];
		return true;
	}

	/**
	 * Prevent non-automoderated users from using action=revert.
	 * @param Title $title
	 * @param User $user
	 * @param string $action
	 * @param string &$result
	 * @return bool
	 */
	public static function ongetUserPermissionsErrors( $title, $user, $action, &$result ) {
		/*
			action=revert bypasses doUpload(), so it is not intercepted
			and is applied without moderation.
			Therefore we don't allow it.
		*/
		$context = RequestContext::getMain();
		$exactAction = Action::getActionName( $context );
		if ( $exactAction == 'revert' && !ModerationCanSkip::canUploadSkip( $user ) ) {
			$result = 'moderation-revert-not-allowed';
			return false;
		}

		return true;
	}
}
