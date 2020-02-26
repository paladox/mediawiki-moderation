<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

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
 * Consequence that writes new upload into the moderation queue.
 */

namespace MediaWiki\Moderation;

use ContentHandler;
use ModerationNewChange;
use ModerationUploadStorage;
use ModerationVersionCheck;
use MWException;
use RequestContext;
use UploadBase;
use User;
use WikiPage;

class QueueUploadConsequence implements IConsequence {
	/** @var UploadBase */
	protected $upload;

	/** @var User */
	protected $user;

	/** @var string */
	protected $comment;

	/** @var string */
	protected $pageText;

	/**
	 * @param UploadBase $upload
	 * @param User $user
	 * @param string $comment
	 * @param string $pageText
	 */
	public function __construct( UploadBase $upload, User $user, $comment, $pageText ) {
		$this->upload = $upload;
		$this->user = $user;
		$this->comment = $comment;
		$this->pageText = $pageText;
	}

	/**
	 * Execute the consequence.
	 * @return array|null Error (array of parameters for wfMessage) or null if queued successfully.
	 *
	 * @phan-return array<string>|null
	 */
	public function run() {
		/* Step 1. Upload the file into the user's stash */
		$status = $this->upload->tryStashFile(
			ModerationUploadStorage::getOwner(),
			true /* Don't run UploadStashFile hook */
		);
		if ( !$status->isOK() ) {
			return [ 'api-error-stashfailed' ];
		}

		$file = $status->getValue();

		/* Step 2. Create a page in File namespace (it will be queued for moderation) */
		$title = $this->upload->getTitle();
		$page = new WikiPage( $title );
		$status = $page->doEditContent(
			ContentHandler::makeContent( $this->pageText, $title ),
			$this->comment,
			0,
			$title->getLatestRevID(),
			$this->user
		);
		if ( $status->isOK() ) {
			// Sanity check: QueueUploadConsequence shouldn't even be called for those users
			// who can bypass moderation in File namespace.
			throw new MWException(
				"QueueUploadConsequence can't be used with automoderated users." );
		}

		// Disable the HTTP redirect after doEditContent.
		// (this redirect has just been added in ModerationEditHooks::onPageContentSave)
		// FIXME: why not just trigger QueueEditConsequence here directly instead of doEditContent?
		RequestContext::getMain()->getOutput()->redirect( '' );

		if ( !$status->hasMessage( 'moderation-edit-queued' ) ) {
			// Some error happened in doEditContent
			return $status->getErrorsArray()[0];
		}

		/*
			Step 3. Populate mod_stash_key field in newly inserted row
			of the moderation table (to indicate that this is an upload,
			not just editing the text on the image page)
		*/
		$fields = [
			'mod_stash_key' => $file->getFileKey()
		];
		if ( ModerationVersionCheck::areTagsSupported() ) {
			/* Apply AbuseFilter tags, if any */
			$fields['mod_tags'] = ModerationNewChange::findAbuseFilterTags(
				$title,
				$this->user,
				'upload'
			);
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			$fields,
			[ 'mod_id' => ModerationNewChange::$LastInsertId ],
			__METHOD__
		);

		// Successfully queued for moderation (no errors)
		return null;
	}
}
