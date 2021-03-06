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
 * Implements [[Special:Moderation]].
 */

use MediaWiki\MediaWikiServices;

class SpecialModeration extends QueryPage {

	/**
	 * @var string
	 * Currently selected folder (when viewing the moderation table)
	 */
	public $folder;

	/**
	 * @var array
	 * Maps folder names to their SQL filtering conditions for Database::select().
	 *
	 * @phan-var array<string,array>
	 */
	public $folders_list = [
		'pending' => [ # Not yet moderated
			'mod_rejected' => 0,
			'mod_merged_revid' => 0
		],
		'rejected' => [ # Rejected by the moderator
			'mod_rejected' => 1,
			'mod_rejected_auto' => 0,
			'mod_merged_revid' => 0
		],
		'merged' => [ # Manually merged (after the edit conflict on approval attempt)
			'mod_merged_revid <> 0'
		],
		'spam' => [ # Rejected automatically
			'mod_rejected_auto' => 1
		]
	];

	/**
	 * @var string
	 * Name of default folder.
	 */
	public $default_folder = 'pending';

	public function __construct() {
		parent::__construct( 'Moderation', 'moderation' );
	}

	public function getGroupName() {
		return 'spam';
	}

	public function isSyndicated() {
		return false;
	}

	public function isCacheable() {
		return false;
	}

	/**
	 * @param Wikimedia\Rdbms\IDatabase $db @phan-unused-param
	 * @param Wikimedia\Rdbms\IResultWrapper $res
	 */
	public function preprocessResults( $db, $res ) {
		/* Check all pages for whether they exist or not -
			improves performance of makeLink() in ModerationEntryFormatter */
		$batch = new LinkBatch();
		foreach ( $res as $row ) {
			ModerationEntryFormatter::addToLinkBatch( $row, $batch );
		}
		$batch->execute();

		$res->seek( 0 );
	}

	public function linkParameters() {
		return [ 'folder' => $this->folder ];
	}

	public function getPageHeader() {
		$linkRenderer = $this->getLinkRenderer();

		$folderLinks = [];
		foreach ( array_keys( $this->folders_list ) as $f_name ) {
			$label = $this->msg( 'moderation-folder-' . $f_name )->plain();

			if ( $f_name == $this->folder ) {
				$folderLinks[] = Xml::element( 'strong', [ 'class' => 'selflink' ], $label );
			} else {
				$folderLinks[] = $linkRenderer->makePreloadedLink(
					$this->getPageTitle(),
					$label,
					'',
					[ 'title' => $this->msg( 'tooltip-moderation-folder-' . $f_name )->plain() ],
					[ 'folder' => $f_name ]
				);
			}
		}

		return Xml::tags( 'div',
			[ 'class' => 'mw-moderation-folders' ],
			implode( ' | ', $folderLinks )
		);
	}

	public function execute( $unused ) {
		// Throw an exception if current user doesn't have "moderation" right.
		$this->checkPermissions();

		$this->setHeaders();
		$this->outputHeader();
		$this->getOutput()->preventClickjacking();

		if ( $this->getRequest()->getVal( 'modaction' ) ) {
			// Some action was requested.
			$this->runModerationAction();
			return;
		}

		// Show the list of pending edits.
		$this->showChangesList();
	}

	/**
	 * Show the list of pending changes in the current folder of Special:Moderation.
	 */
	public function showChangesList() {
		global $wgVersion;

		$out = $this->getOutput();
		$out->addModuleStyles( 'ext.moderation.special.css' );
		$out->addWikiMsg( 'moderation-text' );

		if ( $this->getConfig()->get( 'ModerationUseAjax' ) ) {
			$out->addModules( 'ext.moderation.special.ajax' );
		}

		if ( version_compare( $wgVersion, '1.35.0', '>=' ) ) {
			$out->addModuleStyles( 'mediawiki.interface.helpers.styles' );
		}

		/* Close "New changes await moderation" notification until new changes appear */
		$notifyModerator = MediaWikiServices::getInstance()->getService( 'Moderation.NotifyModerator' );
		$notifyModerator->setSeen( $this->getUser(), wfTimestampNow() );

		// The rest will be handled by QueryPage::execute()
		parent::execute( null );
	}

	/**
	 * Run ModerationAction.
	 */
	public function runModerationAction() {
		$actionFactory = MediaWikiServices::getInstance()->getService( 'Moderation.ActionFactory' );
		$A = $actionFactory->makeAction( $this->getContext() );
		if ( $A->requiresEditToken() ) {
			$token = $this->getRequest()->getVal( 'token' );
			if ( !$this->getUser()->matchEditToken( $token ) ) {
				throw new ErrorPageError( 'sessionfailure-title', 'sessionfailure' );
			}
		}

		$result = $A->run();

		$out = $this->getOutput();
		$A->outputResult( $result, $out );
		$out->addReturnTo( SpecialPage::getTitleFor( 'Moderation' ) );
	}

	public function getOrderFields() {
		return [ 'mod_timestamp' ];
	}

	public function getQueryInfo() {
		$this->folder = $this->getRequest()->getVal( 'folder', $this->default_folder );
		if ( !array_key_exists( $this->folder, $this->folders_list ) ) {
			$this->folder = $this->default_folder;
		}

		$conds = $this->folders_list[$this->folder];
		$index = 'moderation_folder_' . $this->folder;

		return array_merge_recursive(
			ModerationEntryFormatter::getQueryInfo(),
			[
				'conds' => $conds,
				'options' => [ 'USE INDEX' => [
					'moderation' => $index
				] ],
				'fields' => [ 'mod_id AS value' ] // Expected by ApiQueryQueryPage
			]
		);
	}

	/**
	 * @param Skin $skin @phan-unused-param
	 * @param object $row Result row
	 * @return string
	 */
	public function formatResult( $skin, $row ) {
		return MediaWikiServices::getInstance()
			->getService( 'Moderation.EntryFactory' )
			->makeFormatter( $row, $this->getContext() )
			->getHTML();
	}
}
