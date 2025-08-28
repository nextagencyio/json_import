<?php

namespace Drupal\json_import\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for importing concise Drupal-friendly JSON configuration.
 */
class DrupalContentImporter {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Field type mapper.
   *
   * @var \Drupal\json_import\Service\FieldTypeMapper
   */
  protected $fieldTypeMapper;

  /**
   * Constructs a DrupalContentImporter object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, FieldTypeMapper $field_type_mapper) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->fieldTypeMapper = $field_type_mapper;
  }

  /**
   * Imports concise configuration with 'model' and 'content'.
   *
   * @param array $data
   *   The decoded JSON data.
   * @param bool $preview_mode
   *   Whether to run in preview mode (do not actually create).
   *
   * @return array
   *   Result array with summary and warnings.
   */
  public function import(array $data, $preview_mode = FALSE) {
    if (!isset($data['model']) && !isset($data['content'])) {
      throw new \InvalidArgumentException('JSON must contain a "model" and/or "content" array.');
    }
    return $this->importConcise($data, $preview_mode);
  }

  /**
   * Imports the concise schema with 'model' and 'content'.
   */
  private function importConcise(array $data, $preview_mode = FALSE) {
    $result = [
      'summary' => [],
      'warnings' => [],
    ];

    $bundle_defs = [];
    if (isset($data['model'])) {
      $model = $data['model'];
      // Support both: an array of bundle defs, or keyed by entity type.
      if (is_array($model) && (isset($model['node']) || isset($model['paragraph']))) {
        foreach (['node', 'paragraph'] as $entity_type) {
          if (!empty($model[$entity_type]) && is_array($model[$entity_type])) {
            foreach ($model[$entity_type] as $def) {
              $def['entity'] = $entity_type;
              $bundle_defs[] = $def;
            }
          }
        }
      } else {
        // Assume $model is a flat array of bundle definitions.
        $bundle_defs = is_array($model) ? $model : [];
      }
    }

    // Create bundles.
    foreach ($bundle_defs as $def) {
      $entity_type = $def['entity'] ?? 'node';
      if ($entity_type === 'paragraph') {
        $this->createBundleParagraphConcise($def, $preview_mode, $result);
      } else {
        $this->createBundleNodeConcise($def, $preview_mode, $result);
      }
    }

    // Configure GraphQL Compose after all bundles and fields are created.
    foreach ($bundle_defs as $def) {
      $entity_type = $def['entity'] ?? 'node';
      $bundle = $def['bundle'];
      $this->configureGraphQLCompose($entity_type, $bundle, $preview_mode, $result);
    }

    // Create content if present.
    if (!empty($data['content']) && is_array($data['content'])) {
      $this->createContentConcise($data['content'], $preview_mode, $result);
    }

    // Clear GraphQL caches after successful import (skip in preview mode).
    if (!$preview_mode) {
      $this->clearGraphQLCaches();
      $result['summary'][] = "Cleared GraphQL caches for schema updates";
    }

    return $result;
  }

  /**
   * Creates a node bundle from concise def.
   */
  private function createBundleNodeConcise(array $def, $preview_mode, array &$result) {
    $id = $def['bundle'];
    $name = $def['label'] ?? $id;
    $description = $def['description'] ?? '';

    if ($preview_mode) {
      $result['summary'][] = "Would create node type: {$name} ({$id})";
    } else {
      $existing = $this->entityTypeManager->getStorage('node_type')->load($id);
      if ($existing) {
        $result['warnings'][] = "Node type '{$id}' already exists, skipping creation";
      } else {
        $node_type = $this->entityTypeManager->getStorage('node_type')->create([
          'type' => $id,
          'name' => $name,
          'description' => $description,
          'new_revision' => TRUE,
          'preview_mode' => 1,
          'display_submitted' => TRUE,
        ]);
        $node_type->save();
        $result['summary'][] = "Created node type: {$name} ({$id})";
      }
    }

    // Add core body if requested.
    if (!empty($def['body'])) {
      $this->addBodyFieldToContentType($id, $preview_mode, $result);
    }

    // Fields.
    $fields = $def['fields'] ?? [];
    foreach ($fields as $field) {
      // Normalize field keys: label or name.
      if (!isset($field['name']) && isset($field['label'])) {
        $field['name'] = $field['label'];
      }
      $this->createField('node', $id, $field, $preview_mode, $result);
    }

    // Form display with sensible defaults.
    if (!$preview_mode) {
      $this->createFormDisplayConcise('node', $id, $fields, $def['form_display'] ?? NULL, $result);
    }
  }

  /**
   * Creates a paragraph bundle from concise def.
   */
  private function createBundleParagraphConcise(array $def, $preview_mode, array &$result) {
    $id = $def['bundle'];
    $name = $def['label'] ?? $id;
    $description = $def['description'] ?? '';

    if ($preview_mode) {
      $result['summary'][] = "Would create paragraph type: {$name} ({$id})";
    } else {
      $existing = $this->entityTypeManager->getStorage('paragraphs_type')->load($id);
      if ($existing) {
        $result['warnings'][] = "Paragraph type '{$id}' already exists, skipping creation";
      } else {
        $paragraph_type = $this->entityTypeManager->getStorage('paragraphs_type')->create([
          'id' => $id,
          'label' => $name,
          'description' => $description,
        ]);
        $paragraph_type->save();
        $result['summary'][] = "Created paragraph type: {$name} ({$id})";
      }
    }

    $fields = $def['fields'] ?? [];
    foreach ($fields as $field) {
      if (!isset($field['name']) && isset($field['label'])) {
        $field['name'] = $field['label'];
      }
      $this->createField('paragraph', $id, $field, $preview_mode, $result);
    }

    if (!$preview_mode) {
      $this->createFormDisplayConcise('paragraph', $id, $fields, $def['form_display'] ?? NULL, $result);
    }
  }

  /**
   * Creates form display with concise defaults using mapper widget hints.
   */
  private function createFormDisplayConcise($entity_type, $bundle, array $fields, $overrides, array &$result) {
    $form_display_id = "{$entity_type}.{$bundle}.default";
    $form_display_storage = $this->entityTypeManager->getStorage('entity_form_display');
    $existing_display = $form_display_storage->load($form_display_id);
    if ($existing_display) {
      $result['warnings'][] = "Form display for {$entity_type}.{$bundle} already exists, skipping";
      return;
    }

    $display_config = [
      'targetEntityType' => $entity_type,
      'bundle' => $bundle,
      'mode' => 'default',
      'status' => TRUE,
      'content' => [],
      'hidden' => [],
    ];

    $weight = -5;
    if ($entity_type === 'node') {
      $display_config['content']['title'] = [
        'type' => 'string_textfield',
        'weight' => $weight++,
        'region' => 'content',
        'settings' => [
          'size' => 60,
          'placeholder' => '',
        ],
        'third_party_settings' => [],
      ];
    }

    foreach ($fields as $field_config) {
      $field_id = $field_config['id'];
      // Handle reserved fields.
      if ($this->isReservedField($field_id, $entity_type)) {
        $widget_type = 'string_textfield';
        if ($field_id === 'body') {
          $widget_type = 'text_textarea';
        }
        $display_config['content'][$field_id] = [
          'type' => $widget_type,
          'weight' => $weight++,
          'region' => 'content',
          'settings' => [],
          'third_party_settings' => [],
        ];
        continue;
      }

      $drupal = $this->fieldTypeMapper->mapFieldType($field_config);
      if (!$drupal) {
        continue;
      }
      if (!empty($drupal['required'])) {
        $field_config['required'] = TRUE;
      }
      $field_name = 'field_' . $this->sanitizeFieldName($field_id);
      $widget_type = $drupal['widget'] ?? 'string_textfield';
      
      // Configure widget settings
      $widget_settings = [];
      
      // Set paragraphs to be collapsed by default
      if ($widget_type === 'paragraphs') {
        $widget_settings = [
          'edit_mode' => 'closed',
          'closed_mode' => 'summary',
          'autocollapse' => 'none',
          'closed_mode_threshold' => 0,
          'add_mode' => 'dropdown',
          'form_display_mode' => 'default',
          'default_paragraph_type' => '',
        ];
      }
      
      $display_config['content'][$field_name] = [
        'type' => $widget_type,
        'weight' => $weight++,
        'region' => 'content',
        'settings' => $widget_settings,
        'third_party_settings' => [],
      ];
    }

    // Add standard node fields at the end.
    if ($entity_type === 'node') {
      $display_config['content']['uid'] = [
        'type' => 'entity_reference_autocomplete',
        'weight' => $weight++,
        'region' => 'content',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'match_limit' => 10,
          'size' => 60,
          'placeholder' => '',
        ],
        'third_party_settings' => [],
      ];
      $display_config['content']['created'] = [
        'type' => 'datetime_timestamp',
        'weight' => $weight++,
        'region' => 'content',
        'settings' => [],
        'third_party_settings' => [],
      ];
      $display_config['content']['promote'] = [
        'type' => 'boolean_checkbox',
        'weight' => $weight++,
        'region' => 'content',
        'settings' => ['display_label' => TRUE],
        'third_party_settings' => [],
      ];
      $display_config['content']['status'] = [
        'type' => 'boolean_checkbox',
        'weight' => $weight + 10,
        'region' => 'content',
        'settings' => ['display_label' => TRUE],
        'third_party_settings' => [],
      ];
    }

    $form_display = $form_display_storage->create($display_config);
    $form_display->save();
    $result['summary'][] = "Created form display for {$entity_type} {$bundle}";
  }

  /**
   * Creates content entities from concise 'content' array.
   */
  private function createContentConcise(array $content, $preview_mode, array &$result) {
    $created = [];

    // First pass: create entities without resolving @refs.
    foreach ($content as $item) {
      $entity = $this->createConciseEntry($item, $preview_mode, $result);
      if ($entity) {
        $created[$item['id']] = $entity;
      }
    }

    if ($preview_mode) {
      return;
    }

    // Second pass: resolve @refs and taxonomy terms.
    foreach ($content as $item) {
      if (isset($created[$item['id']])) {
        $this->resolveConciseReferences($item, $created[$item['id']], $created, $result);
      }
    }
  }

  private function createConciseEntry(array $item, $preview_mode, array &$result) {
    $type = $item['type'];
    $parts = explode('.', $type, 2);
    $entity_type = $parts[0] ?? 'node';
    $bundle = $parts[1] ?? NULL;
    $values = $item['values'] ?? [];

    if ($entity_type === 'paragraph') {
      if ($preview_mode) {
        $result['summary'][] = "Would create paragraph: {$item['id']} (type: {$bundle})";
        return NULL;
      }
      $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
      $data = ['type' => $bundle];
      foreach ($values as $field_id => $value) {
        $data['field_' . $this->sanitizeFieldName($field_id)] = $this->mapFieldValueConcise($value, $field_id);
      }
      $paragraph = $paragraph_storage->create($data);
      $paragraph->save();
      $title = $values['title'] ?? $item['id'];
      $result['summary'][] = "Created paragraph: {$title} (ID: {$paragraph->id()}, type: {$bundle})";
      return $paragraph;
    }

    // Default: node.
    if ($preview_mode) {
      $result['summary'][] = "Would create node: {$item['id']} (type: {$bundle})";
      return NULL;
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $node_data = [
      'type' => $bundle,
      'title' => $values['title'] ?? 'Untitled',
      'status' => 1,
      'uid' => 1,
    ];
    foreach ($values as $field_id => $value) {
      if ($field_id === 'title') {
        // Already handled.
        continue;
      }
      if ($this->isReservedField($field_id, 'node')) {
        $node_data[$field_id] = $this->mapFieldValueConcise($value, $field_id);
      } else {
        $node_data['field_' . $this->sanitizeFieldName($field_id)] = $this->mapFieldValueConcise($value, $field_id);
      }
    }
    $node = $node_storage->create($node_data);
    $node->save();

    // Handle path alias if specified.
    if (isset($item['path']) && !empty($item['path'])) {
      $this->createPathAlias($node, $item['path'], $result);
    }

    $result['summary'][] = "Created node: {$node_data['title']} (ID: {$node->id()}, type: {$bundle})";
    return $node;
  }

  /**
   * Map field values for concise format.
   */
  private function mapFieldValueConcise($value, $field_id) {
    if ($value === NULL) {
      return NULL;
    }
    // Reference marker like @foo.
    if (is_string($value) && strlen($value) > 1 && $value[0] === '@') {
      return NULL; // Will resolve later.
    }
    // Handle image field objects with URI.
    if (is_array($value) && isset($value['uri'])) {
      return $this->handleImageFieldValue($value, $field_id);
    }
    // Arrays of scalars -> [{value: item}].
    if (is_array($value)) {
      // If already an associative array for rich text body etc, pass through.
      $is_assoc = array_keys($value) !== range(0, count($value) - 1);
      if ($is_assoc) {
        return $value;
      }

      // Check if this is an array of image objects (each item has 'uri' property)
      $is_image_array = !empty($value) && is_array($value[0]) && isset($value[0]['uri']);
      if ($is_image_array) {
        $processed_images = [];
        foreach ($value as $image_item) {
          if (is_array($image_item) && isset($image_item['uri'])) {
            $processed_image = $this->handleImageFieldValue($image_item, $field_id);
            if ($processed_image) {
              $processed_images[] = $processed_image;
            }
          }
        }
        return $processed_images;
      }

      return array_map(function ($item) {
        return ['value' => $item];
      }, $value);
    }
    if (is_bool($value)) {
      return $value ? 1 : 0;
    }
    // Heuristic for date fields by id.
    if ($field_id === 'publish_date' || strpos($field_id, 'date') !== FALSE) {
      if (is_string($value)) {
        $timestamp = strtotime($value);
        if ($timestamp !== FALSE) {
          return date('Y-m-d\\TH:i:s', $timestamp);
        }
      }
    }
    // Default: simple value for string/text fields.
    return ['value' => $value, 'format' => 'basic_html'];
  }

  /**
   * Resolve @refs and taxonomy terms after entity creation.
   */
  private function resolveConciseReferences(array $item, $entity, array $created, array &$result) {
    $values = $item['values'] ?? [];
    $type = $item['type'];
    $parts = explode('.', $type, 2);
    $entity_type = $parts[0] ?? 'node';
    $bundle = $parts[1] ?? NULL;

    foreach ($values as $field_id => $value) {
      $drupal_field_name = $this->isReservedField($field_id, $entity_type) ? $field_id : 'field_' . $this->sanitizeFieldName($field_id);
      if (!$entity->hasField($drupal_field_name)) {
        continue;
      }

      // Get field definition once for type and settings.
      $field_definition = $entity->getFieldDefinition($drupal_field_name);

      // Handle references marked with @.
      if (is_string($value) && strlen($value) > 1 && $value[0] === '@') {
        $ref = substr($value, 1);
        if (isset($created[$ref])) {
          $field_type = $field_definition->getType();
          $ref_entity = $created[$ref];
          $cardinality = (int) $field_definition->getFieldStorageDefinition()->getCardinality();

          if ($field_type === 'entity_reference_revisions') {
            // Paragraph reference: set using explicit IDs as item list.
            $field_list = $entity->get($drupal_field_name);
            $item = [
              'target_id' => (int) $ref_entity->id(),
              'target_revision_id' => (int) $ref_entity->getRevisionId(),
            ];
            $field_list->setValue([$item]);
            $entity->save();
            $result['summary'][] = "Resolved paragraph reference: {$field_id} -> {$ref}";
          } elseif ($field_type === 'entity_reference') {
            // Node or term reference by target_id.
            $item = [
              'target_id' => (int) $ref_entity->id(),
            ];
            $entity->set($drupal_field_name, $cardinality === 1 ? $item : [$item]);
            $entity->save();
            $result['summary'][] = "Resolved entity reference: {$field_id} -> {$ref}";
          } else {
            // Fallback: best-effort assign ID.
            $entity->set($drupal_field_name, (int) $ref_entity->id());
            $entity->save();
            $result['summary'][] = "Resolved reference (fallback): {$field_id} -> {$ref}";
          }
        } else {
          $result['warnings'][] = "Could not resolve reference {$field_id} -> {$ref}";
        }
        continue;
      }

      // Handle arrays of references marked with @.
      if (is_array($value) && !empty($value)) {
        $all_refs = TRUE;
        foreach ($value as $item) {
          if (!is_string($item) || strlen($item) <= 1 || $item[0] !== '@') {
            $all_refs = FALSE;
            break;
          }
        }

        if ($all_refs) {
          $field_type = $field_definition->getType();
          $items = [];
          $resolved_refs = [];

          foreach ($value as $item) {
            $ref = substr($item, 1);
            if (isset($created[$ref])) {
              $ref_entity = $created[$ref];
              $resolved_refs[] = $ref;

              if ($field_type === 'entity_reference_revisions') {
                // Paragraph reference: set using explicit IDs as item list.
                $items[] = [
                  'target_id' => (int) $ref_entity->id(),
                  'target_revision_id' => (int) $ref_entity->getRevisionId(),
                ];
              } elseif ($field_type === 'entity_reference') {
                // Node or term reference by target_id.
                $items[] = [
                  'target_id' => (int) $ref_entity->id(),
                ];
              } else {
                // Fallback: best-effort assign ID.
                $items[] = (int) $ref_entity->id();
              }
            } else {
              $result['warnings'][] = "Could not resolve reference {$field_id} -> {$ref}";
            }
          }

          if (!empty($items)) {
            if ($field_type === 'entity_reference_revisions') {
              $field_list = $entity->get($drupal_field_name);
              $field_list->setValue($items);
              $entity->save();
              $result['summary'][] = "Resolved paragraph references: {$field_id} -> [" . implode(', ', $resolved_refs) . "]";
            } else {
              $entity->set($drupal_field_name, $items);
              $entity->save();
              $result['summary'][] = "Resolved entity references: {$field_id} -> [" . implode(', ', $resolved_refs) . "]";
            }
          }
          continue;
        }
      }

      // Handle taxonomy term creation for term() fields: if strings provided.
      $type_storage_settings = $field_definition->getSettings();
      $target_type = $type_storage_settings['target_type'] ?? NULL;
      if ($target_type === 'taxonomy_term') {
        $handler_settings = $field_definition->getSetting('handler_settings') ?: [];
        $target_bundles = $handler_settings['target_bundles'] ?? [];
        $vocab = is_array($target_bundles) ? array_key_first($target_bundles) : NULL;
        if ($vocab) {
          $term_ids = [];
          $values_list = is_array($value) && array_keys($value) === range(0, count($value) - 1) ? $value : [$value];
          foreach ($values_list as $term_value) {
            $tid = NULL;
            if (is_array($term_value)) {
              if (!empty($term_value['tid'])) {
                $tid = (int) $term_value['tid'];
              } elseif (!empty($term_value['name'])) {
                $tid = $this->ensureTerm($vocab, $term_value['name']);
              }
            } elseif (is_string($term_value)) {
              $tid = $this->ensureTerm($vocab, $term_value);
            }
            if ($tid) {
              $term_ids[] = ['target_id' => $tid];
            }
          }
          if (!empty($term_ids)) {
            $entity->set($drupal_field_name, $term_ids);
            $entity->save();
            $result['summary'][] = "Assigned taxonomy terms on {$field_id}";
          }
        }
      }
    }
  }

  /**
   * Ensure a taxonomy term exists by name in a vocabulary, return tid.
   */
  private function ensureTerm(string $vocabulary, string $name) {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $existing = $term_storage->loadByProperties(['vid' => $vocabulary, 'name' => $name]);
    if (!empty($existing)) {
      /** @var \Drupal\taxonomy\Entity\Term $term */
      $term = reset($existing);
      return (int) $term->id();
    }
    $term = $term_storage->create([
      'vid' => $vocabulary,
      'name' => $name,
    ]);
    $term->save();
    return (int) $term->id();
  }

  /**
   * Creates a field for an entity type.
   */
  private function createField($entity_type, $bundle, array $field_config, $preview_mode, array &$result) {
    $field_id = $field_config['id'];
    $field_label = $field_config['name'];
    $field_type = $field_config['type'];
    $required = $field_config['required'] ?? FALSE;

    // Handle reserved field names.
    if ($this->isReservedField($field_id, $entity_type)) {
      if ($field_id === 'body' && $entity_type === 'node') {
        // Special handling for body field. Need to add it to the content type.
        $this->addBodyFieldToContentType($bundle, $preview_mode, $result);
      } else {
        if ($preview_mode) {
          $result['summary'][] = "Would use existing reserved field: {$field_label} ({$field_id}) for {$entity_type} {$bundle}";
        } else {
          $result['summary'][] = "Using existing reserved field: {$field_label} ({$field_id}) for {$entity_type} {$bundle}";
        }
      }
      return;
    }

    // Convert camelCase and other formats to valid machine name.
    $field_name = 'field_' . $this->sanitizeFieldName($field_id);

    // Get Drupal field type mapping.
    $drupal_field_info = $this->fieldTypeMapper->mapFieldType($field_config);
    if (!$drupal_field_info) {
      $result['warnings'][] = "Unsupported field type '{$field_type}' for field '{$field_id}', skipping";
      return;
    }

    $drupal_field_type = $drupal_field_info['type'];
    $field_settings = $drupal_field_info['settings'] ?? [];
    $cardinality = $drupal_field_info['cardinality'] ?? 1;

    if ($preview_mode) {
      $result['summary'][] = "Would create field: {$field_label} ({$field_name}) of type {$drupal_field_type} for {$entity_type} {$bundle}";
      return;
    }

    // Create field storage.
    $field_storage_id = "{$entity_type}.{$field_name}";
    $field_storage = $this->entityTypeManager->getStorage('field_storage_config')->load($field_storage_id);
    if (!$field_storage) {
      $field_storage = $this->entityTypeManager->getStorage('field_storage_config')->create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => $drupal_field_type,
        'cardinality' => $cardinality,
        'settings' => $field_settings,
      ]);
      $field_storage->save();
    }

    // Create field instance.
    $field_id_full = "{$entity_type}.{$bundle}.{$field_name}";
    $field = $this->entityTypeManager->getStorage('field_config')->load($field_id_full);
    if (!$field) {
      $field = $this->entityTypeManager->getStorage('field_config')->create([
        'field_storage' => $field_storage,
        'bundle' => $bundle,
        'label' => $field_label,
        'required' => $required,
        'settings' => $drupal_field_info['instance_settings'] ?? [],
      ]);
      $field->save();
      $result['summary'][] = "Created field: {$field_label} ({$field_name}) for {$entity_type} {$bundle}";
    } else {
      $result['warnings'][] = "Field '{$field_name}' already exists for {$entity_type} {$bundle}, skipping";
    }
  }

  /**
   * Sanitizes a field ID to create a valid Drupal machine name.
   */
  private function sanitizeFieldName($field_id) {
    // Convert camelCase to snake_case.
    $field_name = preg_replace('/([a-z])([A-Z])/', '$1_$2', $field_id);
    // Convert to lowercase.
    $field_name = strtolower($field_name);
    // Replace invalid characters with underscores.
    $field_name = preg_replace('/[^a-z0-9_]/', '_', $field_name);
    // Remove multiple consecutive underscores.
    $field_name = preg_replace('/_+/', '_', $field_name);
    // Ensure it starts with a letter or underscore.
    if (preg_match('/^[0-9]/', $field_name)) {
      $field_name = '_' . $field_name;
    }
    // Trim underscores from start and end.
    $field_name = trim($field_name, '_');
    // Ensure it is not empty and has valid start.
    if (empty($field_name) || !preg_match('/^[a-z_]/', $field_name)) {
      $field_name = 'field_' . uniqid();
    }
    return $field_name;
  }

  /**
   * Checks if a field ID is a reserved field name in Drupal.
   */
  private function isReservedField($field_id, $entity_type) {
    $reserved_fields = [
      'node' => ['title', 'body', 'uid', 'status', 'created', 'changed', 'promote', 'sticky'],
      'paragraph' => [],
    ];
    return in_array($field_id, $reserved_fields[$entity_type] ?? []);
  }

  /**
   * Adds the body field to a node content type.
   */
  private function addBodyFieldToContentType($bundle, $preview_mode, array &$result) {
    if ($preview_mode) {
      $result['summary'][] = "Would add body field to node type: {$bundle}";
      return;
    }
    // Check if body field already exists for this bundle.
    $field_config_id = "node.{$bundle}.body";
    $field_storage = $this->entityTypeManager->getStorage('field_config');
    $existing_field = $field_storage->load($field_config_id);
    if ($existing_field) {
      $result['warnings'][] = "Body field already exists for {$bundle}, skipping";
      return;
    }
    // Get the body field storage (should already exist).
    $body_storage = $this->entityTypeManager->getStorage('field_storage_config')->load('node.body');
    if (!$body_storage) {
      $result['warnings'][] = "Body field storage does not exist, cannot add body field to {$bundle}";
      return;
    }
    // Create the body field instance.
    $field_config = $this->entityTypeManager->getStorage('field_config')->create([
      'field_storage' => $body_storage,
      'bundle' => $bundle,
      'label' => 'Body',
      'description' => '',
      'required' => FALSE,
      'settings' => [
        'display_summary' => TRUE,
        'required_summary' => FALSE,
      ],
    ]);
    $field_config->save();
    $result['summary'][] = "Added body field to node type: {$bundle}";
  }

  /**
   * Configure GraphQL Compose settings for a content type.
   *
   * @param string $entity_type
   *   The entity type ('node' or 'paragraph').
   * @param string $bundle
   *   The bundle/content type machine name.
   * @param bool $preview_mode
   *   Whether this is preview mode.
   * @param array &$result
   *   The result array to add messages to.
   */
  private function configureGraphQLCompose(string $entity_type, string $bundle, bool $preview_mode, array &$result): void {
    if ($preview_mode) {
      $result['summary'][] = "Would configure GraphQL Compose for {$entity_type}.{$bundle}";
      return;
    }

    // Check if GraphQL Compose is available.
    if (!\Drupal::moduleHandler()->moduleExists('graphql_compose')) {
      $result['warnings'][] = "GraphQL Compose module not found, skipping GraphQL configuration for {$entity_type}.{$bundle}";
      return;
    }

    $config_name = "graphql_compose.settings";
    $config = $this->configFactory->getEditable($config_name);

    // Get current settings.
    $entity_config = $config->get('entity_config') ?: [];
    $field_config = $config->get('field_config') ?: [];

    // Configure the entity type and bundle in entity_config.
    if (!isset($entity_config[$entity_type])) {
      $entity_config[$entity_type] = [];
    }

    // Enable all the main GraphQL options.
    $entity_config[$entity_type][$bundle] = [
      'enabled' => TRUE,
      'query_load_enabled' => TRUE,
      'edges_enabled' => TRUE,
    ];

    // Enable routes for nodes only.
    if ($entity_type === 'node') {
      $entity_config[$entity_type][$bundle]['routes_enabled'] = TRUE;
    }

    // Configure field_config section.
    if (!isset($field_config[$entity_type])) {
      $field_config[$entity_type] = [];
    }
    if (!isset($field_config[$entity_type][$bundle])) {
      $field_config[$entity_type][$bundle] = [];
    }

    // Get all fields for this bundle and enable them.
    $field_definitions = $this->entityTypeManager->getStorage('field_config')->loadByProperties([
      'entity_type' => $entity_type,
      'bundle' => $bundle,
    ]);

    // Enable base fields for nodes.
    if ($entity_type === 'node') {
      $base_fields = ['body', 'title', 'created', 'changed', 'status', 'path'];
      foreach ($base_fields as $base_field) {
        $field_config[$entity_type][$bundle][$base_field] = ['enabled' => TRUE];
      }
    }

    // Enable all custom fields.
    foreach ($field_definitions as $field_definition) {
      $field_name = $field_definition->getName();
      $field_config[$entity_type][$bundle][$field_name] = ['enabled' => TRUE];
    }

    // Save both configurations.
    $config->set('entity_config', $entity_config);
    $config->set('field_config', $field_config);
    $config->save();

    $result['summary'][] = "Configured GraphQL Compose for {$entity_type}.{$bundle} with all fields enabled";
  }

  /**
   * Create a URL alias for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   * @param string $path
   *   The desired path alias.
   * @param array &$result
   *   The result array to add messages to.
   */
  private function createPathAlias($node, string $path, array &$result): void {
    // Check if path alias module is available.
    if (!\Drupal::moduleHandler()->moduleExists('path_alias')) {
      $result['warnings'][] = "Path alias module not available, cannot set path for node {$node->id()}";
      return;
    }

    // Get the path alias storage.
    $path_alias_storage = $this->entityTypeManager->getStorage('path_alias');

    // Create the path alias.
    $path_alias = $path_alias_storage->create([
      'path' => '/node/' . $node->id(),
      'alias' => $path,
      'langcode' => $node->language()->getId(),
    ]);

    try {
      $path_alias->save();
      $result['summary'][] = "Created path alias: {$path} for node {$node->id()}";
    } catch (\Exception $e) {
      $result['warnings'][] = "Failed to create path alias '{$path}' for node {$node->id()}: " . $e->getMessage();
    }
  }

  /**
   * Handle image field values with URI references.
   *
   * @param array $value
   *   Array containing uri, alt, title, etc.
   * @param string $field_id
   *   The field identifier.
   *
   * @return array|null
   *   File entity reference array or NULL.
   */
  private function handleImageFieldValue(array $value, string $field_id) {
    $uri = $value['uri'] ?? NULL;
    if (!$uri) {
      \Drupal::logger('dcloud_import')->warning('No URI provided for image field @field_id', ['@field_id' => $field_id]);
      return NULL;
    }

    \Drupal::logger('dcloud_import')->info('Processing image field @field_id with URI: @uri', [
      '@field_id' => $field_id,
      '@uri' => $uri
    ]);

    $source_path = NULL;
    $filename = NULL;

    // Handle different URI schemes.
    if (strpos($uri, 'module://') === 0) {
      // Module resource: module://module_name/path/to/file.ext
      $path_parts = explode('/', substr($uri, 9)); // Remove 'module://'
      $module_name = array_shift($path_parts);
      $relative_path = implode('/', $path_parts);

      $module_path = \Drupal::service('extension.list.module')->getPath($module_name);
      $source_path = $module_path . '/' . $relative_path;
      $filename = basename($relative_path);
    } elseif (strpos($uri, '/') === 0) {
      // Relative path from Drupal root (e.g., /modules/custom/dcloud_import/resources/placeholder.png)
      $source_path = \Drupal::root() . $uri;
      $filename = basename($uri);
    } elseif (strpos($uri, 'http://') === 0 || strpos($uri, 'https://') === 0) {
      // HTTP/HTTPS URL - download the file
      $filename = basename(parse_url($uri, PHP_URL_PATH)) ?: 'imported_image_' . uniqid() . '.png';
      $temp_file = \Drupal::service('file_system')->tempnam('temporary://', 'import_');

      // Download the file
      $context = stream_context_create([
        'http' => [
          'timeout' => 30,
          'user_agent' => 'Drupal dcloud_import module'
        ]
      ]);

      if (copy($uri, $temp_file, $context)) {
        $source_path = $temp_file;
      }
    } else {
      // Assume it's a local file path
      $source_path = $uri;
      $filename = basename($uri);
    }

    if (!$source_path || !file_exists($source_path)) {
      \Drupal::logger('dcloud_import')->warning('Image source not found or accessible: @path for field @field_id', [
        '@path' => $source_path,
        '@field_id' => $field_id
      ]);
      return NULL;
    }

    // Create the file entity.
    $file_storage = $this->entityTypeManager->getStorage('file');

    // Generate destination path with date-based directory.
    $destination_dir = 'public://' . date('Y-m');
    $destination = $destination_dir . '/' . $filename;

    // Ensure directory exists.
    \Drupal::service('file_system')->prepareDirectory($destination_dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

    // Copy file to destination.
    $file_uri = \Drupal::service('file_system')->copy($source_path, $destination, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);

    // Clean up temp file if it was downloaded
    if (strpos($uri, 'http') === 0 && $source_path !== $uri) {
      unlink($source_path);
    }

    if ($file_uri) {
      // Create file entity.
      $file = $file_storage->create([
        'filename' => $filename,
        'uri' => $file_uri,
        'status' => 1,
        'uid' => 1,
      ]);
      $file->save();

      \Drupal::logger('dcloud_import')->info('Successfully created file entity ID @file_id for field @field_id', [
        '@file_id' => $file->id(),
        '@field_id' => $field_id
      ]);

      // Return image field value structure.
      return [
        'target_id' => $file->id(),
        'alt' => $value['alt'] ?? '',
        'title' => $value['title'] ?? '',
        'width' => $value['width'] ?? NULL,
        'height' => $value['height'] ?? NULL,
      ];
    }

    \Drupal::logger('dcloud_import')->warning('Failed to copy file to destination for field @field_id', ['@field_id' => $field_id]);
    return NULL;
  }


  /**
   * Clear GraphQL-specific caches instead of all caches.
   */
  private function clearGraphQLCaches(): void {
    // Use GraphQL Compose's cache clearing function if available.
    if (function_exists('_graphql_compose_cache_flush')) {
      _graphql_compose_cache_flush();
      return;
    }

    // Fallback: Clear individual GraphQL cache bins.
    $cache_bins = [
      'cache.graphql.apq',
      'cache.graphql.ast',
      'cache.graphql.definitions',
      'cache.graphql.results',
      'cache.graphql_compose.definitions',
    ];

    foreach ($cache_bins as $cache_bin) {
      try {
        \Drupal::service($cache_bin)->deleteAll();
      } catch (\Exception $e) {
        // Cache service might not exist, continue with others.
        \Drupal::logger('dcloud_import')->warning('Could not clear cache bin @bin: @message', [
          '@bin' => $cache_bin,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }
}


