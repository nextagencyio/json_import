<?php

namespace Drupal\json_import\Service;

use JsonSchema\Validator;
use JsonSchema\Constraints\Constraint;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for validating JSON against the import schema.
 */
class JsonSchemaValidator {

  use StringTranslationTrait;

  /**
   * The schema URL.
   */
  const SCHEMA_URL = 'https://raw.githubusercontent.com/nextagencyio/json_import/refs/heads/1.x/resources/schema.json';

  /**
   * Validates JSON data against the import schema.
   *
   * @param array $data
   *   The decoded JSON data to validate.
   *
   * @return array
   *   Array with 'valid' boolean and 'errors' array.
   */
  public function validate(array $data): array {
    // Check if the JSON Schema library is available
    if (!class_exists('JsonSchema\Validator')) {
      return [
        'valid' => TRUE,
        'errors' => [],
        'warnings' => [$this->t('JSON Schema validation library not available. Skipping schema validation.')],
      ];
    }

    try {
      // Load the schema
      $schema = $this->loadSchema();
      if (!$schema) {
        return [
          'valid' => TRUE,
          'errors' => [],
          'warnings' => [$this->t('Could not load schema for validation. Skipping schema validation.')],
        ];
      }

      // Convert data to object for validation
      $data_object = json_decode(json_encode($data));

      // Validate against schema
      $validator = new Validator();
      $validator->validate($data_object, $schema, Constraint::CHECK_MODE_COERCE_TYPES);

      if ($validator->isValid()) {
        return [
          'valid' => TRUE,
          'errors' => [],
          'warnings' => [],
        ];
      }

      // Collect validation errors
      $errors = [];
      foreach ($validator->getErrors() as $error) {
        $property = $error['property'] ? $error['property'] . ': ' : '';
        $errors[] = $property . $error['message'];
      }

      return [
        'valid' => FALSE,
        'errors' => $errors,
        'warnings' => [],
      ];

    } catch (\Exception $e) {
      return [
        'valid' => TRUE,
        'errors' => [],
        'warnings' => [$this->t('Schema validation failed: @error', ['@error' => $e->getMessage()])],
      ];
    }
  }

  /**
   * Loads the JSON schema.
   *
   * @return object|null
   *   The schema object or NULL if unable to load.
   */
  private function loadSchema(): ?object {
    // First try to load from local file
    $module_path = \Drupal::service('extension.list.module')->getPath('json_import');
    $local_path = \Drupal::root() . '/' . $module_path . '/resources/schema.json';
    
    if (is_readable($local_path)) {
      $schema_content = file_get_contents($local_path);
      if ($schema_content !== FALSE) {
        $schema = json_decode($schema_content);
        if (json_last_error() === JSON_ERROR_NONE) {
          return $schema;
        }
      }
    }

    // Fallback to remote schema (if curl is available)
    if (function_exists('curl_init')) {
      try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::SCHEMA_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $schema_content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200 && $schema_content !== FALSE) {
          $schema = json_decode($schema_content);
          if (json_last_error() === JSON_ERROR_NONE) {
            return $schema;
          }
        }
      } catch (\Exception $e) {
        // Fallback to no validation
      }
    }

    return NULL;
  }

}