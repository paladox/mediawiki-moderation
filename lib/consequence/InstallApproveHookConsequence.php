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
 * Consequence that installs a new ApproveHook task.
 */

namespace MediaWiki\Moderation;

use ModerationApproveHook;
use Title;
use User;
use Wikimedia\Assert\Assert;

class InstallApproveHookConsequence implements IConsequence {
	/** @var Title */
	protected $title;

	/** @var User */
	protected $user;

	/** @var string */
	protected $type;

	/** @var array */
	protected $task;

	/**
	 * @param Title $title
	 * @param User $user
	 * @param string $type
	 * @param array $task
	 *
	 * @phan-param array{ip:?string,xff:?string,ua:?string,tags:?string,timestamp:?string} $task
	 */
	public function __construct( Title $title, User $user, $type, array $task ) {
		$this->title = $title;
		$this->user = $user;
		$this->type = $type;
		$this->task = $task;
	}

	/**
	 * Execute the consequence.
	 * @param ModerationApproveHook|null $approveHook
	 */
	public function run( ModerationApproveHook $approveHook = null ) {
		Assert::invariant( $approveHook !== null, 'approveHook must not be null' );
		$approveHook->addTask( $this->title, $this->user, $this->type, $this->task );
	}
}
