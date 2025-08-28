<?php

/**
 * @file
 * Drush script to purge sample structures/content, import sample JSON, and verify.
 *
 * Usage:
 *   drush scr web/modules/custom/dcloud_import/scripts/import_sample.php
 */

// Drush scr usually runs with full bootstrap, so \Drupal is available.

/**
 * Print a line to output.
 */
function _dcloud_scr_msg(string $message): void {
  print $message . "\n";
}

$entityTypeManager = \Drupal::entityTypeManager();

// Paths based on this script location to avoid bootstrap helpers.
$moduleRoot = dirname(__DIR__);
$samplePath = $moduleRoot . '/resources/sample.json';

if (!file_exists($samplePath)) {
  _dcloud_scr_msg("ERROR: Sample JSON not found at: {$samplePath}");
  return;
}

_dcloud_scr_msg('Purging existing sample content and structures...');

// Delete nodes of bundle event.
$nodeStorage = $entityTypeManager->getStorage('node');
$nids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'event')->execute();
if (!empty($nids)) {
  $nodes = $nodeStorage->loadMultiple($nids);
  $nodeStorage->delete($nodes);
  _dcloud_scr_msg('Deleted existing event nodes.');
}

// Delete paragraphs of type event_detail.
$paragraphStorage = $entityTypeManager->getStorage('paragraph');
$pids = \Drupal::entityQuery('paragraph')->accessCheck(FALSE)->condition('type', 'event_detail')->execute();
if (!empty($pids)) {
  $paragraphs = $paragraphStorage->loadMultiple($pids);
  $paragraphStorage->delete($paragraphs);
  _dcloud_scr_msg('Deleted existing event_detail paragraphs.');
}

// Delete field instances and storages for node.event.
$fieldConfigStorage = $entityTypeManager->getStorage('field_config');
$fieldStorageStorage = $entityTypeManager->getStorage('field_storage_config');

$nodeFieldIds = \Drupal::entityQuery('field_config')->accessCheck(FALSE)
  ->condition('entity_type', 'node')
  ->condition('bundle', 'event')
  ->execute();
if (!empty($nodeFieldIds)) {
  $nodeFieldConfigs = $fieldConfigStorage->loadMultiple($nodeFieldIds);
  foreach ($nodeFieldConfigs as $fc) {
    $fieldName = $fc->getName();
    $fc->delete();
    $storageId = 'node.' . $fieldName;
    $storage = $fieldStorageStorage->load($storageId);
    if ($storage && count($storage->getBundles()) === 0) {
      $storage->delete();
    }
  }
  _dcloud_scr_msg('Deleted field instances for node.event.');
}

// Delete field instances and storages for paragraph.event_detail.
$paraFieldIds = \Drupal::entityQuery('field_config')->accessCheck(FALSE)
  ->condition('entity_type', 'paragraph')
  ->condition('bundle', 'event_detail')
  ->execute();
if (!empty($paraFieldIds)) {
  $paraFieldConfigs = $fieldConfigStorage->loadMultiple($paraFieldIds);
  foreach ($paraFieldConfigs as $fc) {
    $fieldName = $fc->getName();
    $fc->delete();
    $storageId = 'paragraph.' . $fieldName;
    $storage = $fieldStorageStorage->load($storageId);
    if ($storage && count($storage->getBundles()) === 0) {
      $storage->delete();
    }
  }
  _dcloud_scr_msg('Deleted field instances for paragraph.event_detail.');
}

// Delete form displays created by importer.
$efdStorage = $entityTypeManager->getStorage('entity_form_display');
foreach ([
  'node.event.default',
  'paragraph.event_detail.default',
] as $efdId) {
  if ($efd = $efdStorage->load($efdId)) {
    $efd->delete();
  }
}

// Delete node type.
$nodeTypeStorage = $entityTypeManager->getStorage('node_type');
if ($type = $nodeTypeStorage->load('event')) {
  $type->delete();
  _dcloud_scr_msg('Deleted node type event.');
}

// Delete paragraph type.
$paraTypeStorage = $entityTypeManager->getStorage('paragraphs_type');
if ($ptype = $paraTypeStorage->load('event_detail')) {
  $ptype->delete();
  _dcloud_scr_msg('Deleted paragraph type event_detail.');
}

// Ensure tags vocabulary exists and clear sample terms.
$vocabStorage = $entityTypeManager->getStorage('taxonomy_vocabulary');
if (!$vocabStorage->load('tags')) {
  $vocab = $vocabStorage->create([
    'vid' => 'tags',
    'name' => 'Tags',
  ]);
  $vocab->save();
  _dcloud_scr_msg('Created tags vocabulary.');
}

$termStorage = $entityTypeManager->getStorage('taxonomy_term');
foreach (['web-development', 'conference', 'technology'] as $name) {
  $existing = $termStorage->loadByProperties(['vid' => 'tags', 'name' => $name]);
  if (!empty($existing)) {
    foreach ($existing as $term) {
      $term->delete();
    }
  }
}

// Import sample JSON using the importer service.
_dcloud_scr_msg('Importing sample JSON...');
$json = file_get_contents($samplePath);
$data = json_decode($json, TRUE);
if (json_last_error() !== JSON_ERROR_NONE) {
  _dcloud_scr_msg('ERROR: Invalid JSON in sample.json: ' . json_last_error_msg());
  return;
}

/** @var \Drupal\dcloud_import\Service\DrupalContentImporter $importer */
$importer = \Drupal::service('dcloud_import.importer');
$result = $importer->import($data, FALSE);

// Print results.
if (!empty($result['summary'])) {
  foreach ($result['summary'] as $line) {
    _dcloud_scr_msg('IMPORT: ' . $line);
  }
}
if (!empty($result['warnings'])) {
  foreach ($result['warnings'] as $line) {
    _dcloud_scr_msg('WARN: ' . $line);
  }
}

// Verify structures and basic content existence.
$pass = TRUE;
if (!$nodeTypeStorage->load('event')) {
  $pass = FALSE;
  _dcloud_scr_msg('FAIL: node type event missing.');
}
if (!$paraTypeStorage->load('event_detail')) {
  $pass = FALSE;
  _dcloud_scr_msg('FAIL: paragraph type event_detail missing.');
}

$nids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'event')->range(0, 1)->execute();
if (empty($nids)) {
  $pass = FALSE;
  _dcloud_scr_msg('FAIL: No event node found.');
}

_dcloud_scr_msg($pass ? 'PASS: Sample import verified.' : 'FAIL: Sample import had issues.');


