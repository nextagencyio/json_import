<?php

namespace Drupal\json_import\Commands;

use Drupal\json_import\Service\DrupalContentImporter;
use Drupal\json_import\Service\JsonSchemaValidator;
use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;
use Consolidation\AnnotatedCommand\CommandData;

/**
 * Drush commands for JSON import functionality.
 */
final class JsonImportCommands extends DrushCommands {

  /**
   * The Drupal content importer service.
   *
   * @var \Drupal\json_import\Service\DrupalContentImporter
   */
  protected $importer;

  /**
   * The JSON schema validator service.
   *
   * @var \Drupal\json_import\Service\JsonSchemaValidator
   */
  protected $schemaValidator;

  /**
   * Constructs a new JsonImportCommands object.
   *
   * @param \Drupal\json_import\Service\DrupalContentImporter $importer
   *   The Drupal content importer service.
   * @param \Drupal\json_import\Service\JsonSchemaValidator $schema_validator
   *   The JSON schema validator service.
   */
  public function __construct(DrupalContentImporter $importer, JsonSchemaValidator $schema_validator) {
    parent::__construct();
    $this->importer = $importer;
    $this->schemaValidator = $schema_validator;
  }

  /**
   * Import JSON configuration and content from a file.
   *
   * @param string $file
   *   Path to the JSON file to import.
   * @param array $options
   *   An associative array of options.
   *
   * @command json_import:import
   * @aliases ji:import
   * @option preview Preview mode - show what would be imported without making changes
   * @usage json_import:import /path/to/config.json Import configuration and content from the specified JSON file
   * @usage json_import:import /path/to/config.json --preview Preview what would be imported without making changes
   */
  public function import(string $file, array $options = ['preview' => FALSE]): void {
    
    // Validate file exists and is readable
    if (!file_exists($file)) {
      $this->logger()->error('File does not exist: ' . $file);
      return;
    }

    if (!is_readable($file)) {
      $this->logger()->error('File is not readable: ' . $file);
      return;
    }

    // Read and parse JSON file
    $json_content = file_get_contents($file);
    if ($json_content === FALSE) {
      $this->logger()->error('Could not read file: ' . $file);
      return;
    }

    $data = json_decode($json_content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $this->logger()->error('Invalid JSON format: ' . json_last_error_msg());
      return;
    }

    // Validate against JSON schema first
    $schema_validation = $this->schemaValidator->validate($data);
    
    if (!$schema_validation['valid']) {
      $this->logger()->error('Schema validation failed:');
      foreach ($schema_validation['errors'] as $error) {
        $this->logger()->error('  - ' . $error);
      }
      return;
    }
    
    // Show schema validation warnings if any
    if (!empty($schema_validation['warnings'])) {
      foreach ($schema_validation['warnings'] as $warning) {
        $this->logger()->warning($warning);
      }
    }

    // Validate the structure (fallback validation)
    if (!$this->validateStructure($data)) {
      $this->logger()->error('Invalid JSON structure. Expected "model" and/or "content" arrays.');
      return;
    }

    $preview_mode = $options['preview'];
    
    try {
      $this->logger()->info('Starting import from: ' . $file . ($preview_mode ? ' (PREVIEW MODE)' : ''));
      
      $result = $this->importer->import($data, $preview_mode);
      
      if ($preview_mode) {
        $this->logger()->success('Preview completed successfully. ' . count($result) . ' operations would be performed.');
      } else {
        $this->logger()->success('Import completed successfully. ' . count($result) . ' operations performed.');
      }

      // Display results
      if (isset($result['summary']) && is_array($result['summary'])) {
        foreach ($result['summary'] as $message) {
          $this->logger()->info($message);
        }
      }
      
      if (isset($result['warnings']) && is_array($result['warnings'])) {
        foreach ($result['warnings'] as $warning) {
          $this->logger()->warning($warning);
        }
      }
      
    } catch (\Exception $e) {
      $this->logger()->error('Import failed: ' . $e->getMessage());
    }
  }

  /**
   * Generate and output example JSON configuration.
   *
   * @command json_import:example
   * @aliases ji:example
   * @usage json_import:example Output example JSON configuration to console
   * @usage json_import:example > example.json Save example JSON to a file
   */
  public function example(): void {
    $example_json = $this->getExampleJson();
    $this->output()->writeln($example_json);
  }

  /**
   * Validates the JSON structure.
   *
   * @param array $data
   *   The decoded JSON data.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  private function validateStructure(array $data): bool {
    // Allow either "model" or "content" or both
    if (!isset($data['model']) && !isset($data['content'])) {
      return FALSE;
    }

    // If model exists, validate its structure
    if (isset($data['model']) && is_array($data['model'])) {
      foreach ($data['model'] as $item) {
        if (!is_array($item) || !isset($item['bundle']) || !isset($item['label'])) {
          return FALSE;
        }
      }
    }

    return TRUE;
  }

  /**
   * Returns example JSON for the command.
   *
   * @return string
   *   Example JSON configuration.
   */
  private function getExampleJson(): string {
    // Load example JSON from the module's resources file if available.
    $module_path = \Drupal::service('extension.list.module')->getPath('json_import');
    $path = \Drupal::root() . '/' . $module_path . '/resources/sample.json';
    if (is_readable($path)) {
      $contents = file_get_contents($path);
      if ($contents !== FALSE && $contents !== '') {
        return $contents;
      }
    }

    // Fallback to embedded example JSON if the file is not available.
    return json_encode([
      'model' => [
        [
          'bundle' => 'event',
          'description' => 'Content type for managing events, conferences, and gatherings',
          'label' => 'Event',
          'body' => TRUE,
          'fields' => [
            ['id' => 'path', 'label' => 'Path', 'type' => 'string'],
            ['id' => 'event_date', 'label' => 'Event Date', 'type' => 'datetime'],
            ['id' => 'location', 'label' => 'Location', 'type' => 'string'],
            ['id' => 'event_details', 'label' => 'Event Details', 'type' => 'paragraph(event_detail)[]'],
            ['id' => 'tags', 'label' => 'Tags', 'type' => 'term(tags)[]'],
            ['id' => 'featured', 'label' => 'Featured', 'type' => 'bool'],
          ],
        ],
        [
          'entity' => 'paragraph',
          'bundle' => 'event_detail',
          'description' => 'Reusable content blocks for event information and details',
          'label' => 'Event Detail',
          'fields' => [
            ['id' => 'detail_title', 'label' => 'Detail Title', 'type' => 'string!'],
            ['id' => 'detail_content', 'label' => 'Detail Content', 'type' => 'text'],
            ['id' => 'detail_image', 'label' => 'Detail Image', 'type' => 'image'],
          ],
        ],
      ],
      'content' => [
        [
          'id' => 'detail1',
          'type' => 'paragraph.event_detail',
          'values' => [
            'detail_title' => 'Schedule',
            'detail_content' => 'The event will run from 9:00 AM to 5:00 PM with lunch break at noon.',
          ],
        ],
        [
          'id' => 'detail2',
          'type' => 'paragraph.event_detail',
          'values' => [
            'detail_title' => 'Speakers',
            'detail_content' => 'Join us for presentations by industry experts and thought leaders.',
          ],
        ],
        [
          'id' => 'event1',
          'type' => 'node.event',
          'values' => [
            'title' => 'Web Development Conference 2024',
            'path' => '/events/web-dev-conference-2024',
            'body' => '<p>Join us for a full day of learning about modern web development...</p>',
            'event_date' => '2024-03-15T09:00:00',
            'location' => 'Convention Center Downtown',
            'tags' => ['web-development', 'conference', 'technology'],
            'featured' => TRUE,
            'event_details' => ['@detail1', '@detail2'],
          ],
        ],
      ],
    ], JSON_PRETTY_PRINT);
  }

}