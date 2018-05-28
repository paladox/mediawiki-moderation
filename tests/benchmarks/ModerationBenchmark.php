<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018 Edward Chernenko.

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
	@file
	@brief Parent class for benchmark scripts.
*/

abstract class ModerationBenchmark extends Maintenance {
	/**
		@brief Prefix that is always prepended to all titles, etc.
		This ensures that two benchmarks won't work with the same pages,
		causing errors like "benchmark #1 was creating a new page,
		benchmark #2 was editing existing page,
		leading to performance of #1 and #2 being different".
	*/
	private $uniquePrefix = null;

	/**
		@brief User object. Always created before the benchmark.
	*/
	private $testUser = null;

	/**
		@brief Returns User object of test account.
	*/
	public function getUser() {
		return $this->testUser;
	}

	/**
		@brief Returns Title object for testing.
		@param $suffix Full text of the title, e.g. "Talk:Welsh corgi".

		During this benchmark, same value is returned for same $suffix,
		but another benchmark will get a different Title.
	*/
	public function getTestTitle( $suffix ) {
		$nonprefixedTitle = Title::newFromText( $suffix );
		return Title::makeTitle(
			$nonprefixedTitle->getNamespace(),
			$this->uniquePrefix . $nonprefixedTitle->getText()
		);
	}

	/**
		@brief This function will be benchmarked by execute().
	*/
	abstract public function doActualWork( $iterationNumber );

	/**
		@brief Initialize everything before the tests.
	*/
	public function beforeBenchmark( $numberOfLoops ) {
		/* Nothing to do.
			Will be redefined by benchmarks if they need this. */
	}

	/**
		@brief Default number of loops.
	*/
	public function getDefaultLoops() {
		return 500;
	}

	/**
		@brief Main function: test the performance of doActualWork().
	*/
	function execute() {
		$user = User::newSystemUser( 'Benchmark User', [ 'steal' => true ] );
		foreach( $user->getGroups() as $existingGroup ) {
			$user->removeGroup( $existingGroup );
		}
		$user->saveSettings();

		$this->uniquePrefix = wfTimestampNow() . '_' . rand() . '_';
		$this->testUser = $user;

		/* Prepare the initial conditions */
		$loops = $this->getDefaultLoops();

		$dbw = wfGetDB( DB_MASTER );
		$dbw->startAtomic( __METHOD__ );

		$this->beforeBenchmark( $loops );

		$dbw->endAtomic( __METHOD__ );

		$loopsInPercent = ceil( $loops / 100 );

		/* Run doActualWork() several times */
		$startTime = microtime( true );
		for ( $i = 0; $i < $loops; $i ++ ) {
			if ( $i % $loopsInPercent == 0 ) {
				printf( "\r%.1f%%", ( 100 * $i / $loops ) );
			}

			$this->doActualWork( $i );
		}
		$endTime = microtime( true );

		/* Performance report */
		printf( "\r%s: did %d iterations in %.3f seconds\n", get_class( $this ), $loops, ( $endTime - $startTime ) );
	}

	/**
		@brief Edit the page (convenience function to be used by benchmarks).
		@return Status object.
	*/
	public function edit( Title $title, $newText = 'Whatever', $summary = '', User $user = null ) {
		$page = WikiPage::factory( $title );
		$content = ContentHandler::makeContent( $newText, null, CONTENT_MODEL_WIKITEXT );

		$flags = 0;
		if ( defined( 'EDIT_INTERNAL' ) ) {
			$flags |= EDIT_INTERNAL; /* No need to check EditStash */
		}

		return $page->doEditContent(
			$content,
			$summary,
			$flags,
			false,
			$user
		);
	}

	/**
		@brief Edit the page by directly modifying the database. Very fast.

		This is used for initialization of tests.
		For example, if moveQueue benchmark needs 500 existing pages,
		it would take forever for doEditContent() to create them all,
		much longer than the actual benchmark.
	*/
	public function fastEdit( Title $title, $newText = 'Whatever', $summary = '' ) {
		$dbw = wfGetDB( DB_MASTER );

		$page = WikiPage::factory( $title );
		$page->insertOn( $dbw );

		$revision = new Revision( [
			'page'       => $page->getId(),
			'comment'    => $summary,
			'text'       => $newText, # No preSaveTransform or serialization
			'user'       => 0,
			'user_text'  => '127.0.0.1',
			'timestamp'  => wfTimestampNow(),
			'content_model' => CONTENT_MODEL_WIKITEXT
		] );

		$revision->insertOn( $dbw );
		$page->updateRevisionOn( $dbw, $revision );
	}

	/**
		@brief Queue the page by directly modifying the database. Very fast.
		This is used for initialization of tests.
	*/
	public function fastQueue( Title $title, $newText = 'Whatever', $summary = '' ) {
		$page = WikiPage::factory( $title );
		$user = User::newFromName( '127.0.0.1', false );
		$content = ContentHandler::makeContent( $newText, null, CONTENT_MODEL_WIKITEXT );

		$change = new ModerationNewChange( $title, $user );
		$change->edit( $page, $content )->setSummary( $summary )->queue();
	}
}