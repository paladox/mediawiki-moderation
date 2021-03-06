<?php

// This file helps Phan to avoid false positives in "detect unused code" mode.
// For example, MediaWiki hook handlers (listed in extension.json) shouldn't be considered unused.

/** @phan-file-suppress PhanParamTooFew */

MediaWiki\Moderation\EditFormOptions::onEditFilter();
MediaWiki\Moderation\EditFormOptions::onSpecialPageBeforeExecute();
ModerationApiHooks::onApiBeforeMain();
ModerationApiHooks::onApiCheckCanExecute();
ModerationApiHooks::onwgQueryPages();
ModerationApproveHook::onCheckUserInsertForRecentChange();
ModerationApproveHook::onFileUpload();
ModerationApproveHook::onNewRevisionFromEditComplete();
ModerationApproveHook::onPageContentSaveComplete();
ModerationApproveHook::onRecentChange_save();
ModerationApproveHook::onTitleMoveComplete();
ModerationEditHooks::onBeforePageDisplay();
ModerationEditHooks::onListDefinedTags();
ModerationEditHooks::onPageContentSave();
ModerationEditHooks::onPageContentSaveComplete();
ModerationEditHooks::prepareEditForm();
ModerationMoveHooks::onMovePageCheckPermissions();
ModerationNotifyModerator::onBeforeInitialize();
ModerationNotifyModerator::onGetNewMessagesAlert();
ModerationPageForms::onModerationContinueEditingLink();
ModerationPageForms::preloadText();
ModerationPlugins::install();
ModerationPreload::onAlternateEdit();
ModerationPreload::onEditFormInitialText();
ModerationPreload::onEditFormPreloadText();
ModerationPreload::onLocalUserCreated();
ModerationUpdater::onLoadExtensionSchemaUpdates();
ModerationUploadHooks::ongetUserPermissionsErrors();
ModerationUploadHooks::onUploadVerifyUpload();
ModerationVersionCheck::invalidateCache();
