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
 * Keeps track of things like section= parameter in EditForm, wpMergeID and "Watch this" checkbox.
 */

namespace MediaWiki\Moderation;

use EditPage;
use MediaWiki\MediaWikiServices;
use RequestContext;
use SpecialPage;
use Title;
use User;

class EditFormOptions {
	/**
	 * @var int|null
	 * mod_id of the pending edit which is currently being merged (during modaction=merge)
	 */
	protected $newMergeID = null;

	/** @var int|string Number of edited section, if any (populated in onEditFilter) */
	protected $section = '';

	/** @var string Text of edited section, if any (populated in onEditFilter) */
	protected $sectionText = '';

	/**
	 * @var bool|null
	 * Value of "Watch this page" checkbox, if any.
	 * If true, pages passed to watchIfNeeded() will be Watched, if false, Unwatched.
	 * If null, then neither Watching nor Unwatching is necessary.
	 */
	protected $watchthis = null;

	/** @var IConsequenceManager */
	protected $consequenceManager;

	/**
	 * @param IConsequenceManager $consequenceManager
	 */
	public function __construct( IConsequenceManager $consequenceManager ) {
		$this->consequenceManager = $consequenceManager;
	}

	/**
	 * EditFilter hook handler.
	 * Save sections-related information, which will then be used in onPageContentSave.
	 * @param EditPage $editor
	 * @param string $text
	 * @param string $section
	 * @param string &$error @phan-unused-param
	 * @param string $summary @phan-unused-param
	 * @return true
	 */
	public static function onEditFilter( EditPage $editor, $text, $section, &$error, $summary ) {
		$opt = MediaWikiServices::getInstance()->getService( 'Moderation.EditFormOptions' );
		if ( $section !== '' ) {
			$opt->section = $section;
			$opt->sectionText = $text;
		}

		$opt->watchthis = (bool)$editor->watchthis;
		return true;
	}

	/**
	 * Detect "watch this" checkboxes on Special:Movepage and Special:Upload.
	 * @param SpecialPage $special
	 * @param string $subPage @phan-unused-param
	 */
	public static function onSpecialPageBeforeExecute( SpecialPage $special, $subPage ) {
		$opt = MediaWikiServices::getInstance()->getService( 'Moderation.EditFormOptions' );
		$title = $special->getPageTitle();
		$request = $special->getRequest();

		if ( $title->isSpecial( 'Movepage' ) ) {
			$opt->watchthis = $request->getCheck( 'wpWatch' );
		} elseif ( $title->isSpecial( 'Upload' ) ) {
			$opt->watchthis = $request->getBool( 'wpWatchthis' );
		}
	}

	/**
	 * @param int $modid
	 */
	public function setMergeID( $modid ) {
		$this->newMergeID = $modid;
	}

	/**
	 * @return int
	 */
	public function getMergeID() {
		if ( $this->newMergeID ) {
			return $this->newMergeID;
		}

		return RequestContext::getMain()->getRequest()->getInt( 'wpMergeID', 0 );
	}

	/**
	 * @return string
	 */
	public function getSection() {
		return $this->section;
	}

	/**
	 * @return string
	 */
	public function getSectionText() {
		return $this->sectionText;
	}

	/**
	 * Watch or Unwatch the pages depending on the current value of $watchthis.
	 * @param User $user
	 * @param Title[] $titles
	 */
	public function watchIfNeeded( User $user, array $titles ) {
		if ( $this->watchthis === null ) {
			// Neither Watch nor Unwatch were requested.
			return;
		}

		foreach ( $titles as $title ) {
			$this->consequenceManager->add(
				new WatchOrUnwatchConsequence( $this->watchthis, $title, $user )
			);
		}
	}
}
