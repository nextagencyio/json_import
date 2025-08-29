<?php

namespace Drupal\json_import\Commands;

use Drupal\json_import\Service\DrupalContentImporter;
use Drupal\json_import\Service\JsonSchemaValidator;
use Drush\Commands\DrushCommands;

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

    // Validate file exists and is readable.
    if (!file_exists($file)) {
      $this->logger()->error('File does not exist: ' . $file);
      return;
    }

    if (!is_readable($file)) {
      $this->logger()->error('File is not readable: ' . $file);
      return;
    }

    // Read and parse JSON file.
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

    // Validate against JSON schema first.
    $schema_validation = $this->schemaValidator->validate($data);

    if (!$schema_validation['valid']) {
      $this->logger()->error('Schema validation failed:');
      foreach ($schema_validation['errors'] as $error) {
        $this->logger()->error('  - ' . $error);
      }
      return;
    }

    // Show schema validation warnings if any.
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
      }
      else {
        $this->logger()->success('Import completed successfully. ' . count($result) . ' operations performed.');
      }

      // Display results.
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

    }
    catch (\Exception $e) {
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
   * Output comprehensive instructions for AI agents to use JSON import.
   *
   * @command json_import:ai-instructions
   * @aliases ji:ai-instructions
   * @usage json_import:ai-instructions Output AI-friendly instructions for using JSON import
   */
  public function aiInstructions(): void {
    $instructions = $this->getAiInstructions();
    $this->output()->writeln($instructions);
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
    // Allow either "model" or "content" or both.
    if (!isset($data['model']) && !isset($data['content'])) {
      return FALSE;
    }

    // If model exists, validate its structure.
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
            [
              'id' => 'path',
              'label' => 'Path',
              'type' => 'string',
            ],
            [
              'id' => 'event_date',
              'label' => 'Event Date',
              'type' => 'datetime',
            ],
            [
              'id' => 'location',
              'label' => 'Location',
              'type' => 'string',
            ],
            [
              'id' => 'event_details',
              'label' => 'Event Details',
              'type' => 'paragraph(event_detail)[]',
            ],
            [
              'id' => 'tags',
              'label' => 'Tags',
              'type' => 'term(tags)[]',
            ],
            [
              'id' => 'featured',
              'label' => 'Featured',
              'type' => 'bool',
            ],
          ],
        ],
        [
          'entity' => 'paragraph',
          'bundle' => 'event_detail',
          'description' => 'Reusable content blocks for event information and details',
          'label' => 'Event Detail',
          'fields' => [
            [
              'id' => 'detail_title',
              'label' => 'Detail Title',
              'type' => 'string!',
            ],
            [
              'id' => 'detail_content',
              'label' => 'Detail Content',
              'type' => 'text',
            ],
            [
              'id' => 'detail_image',
              'label' => 'Detail Image',
              'type' => 'image',
            ],
          ],
        ],
      ],
      'content' => [
        [
          'id' => 'detail1',
          'type' => 'paragraph.event_detail',
          'values' => [
            'detail_title' => 'Schedule',
            'detail_content' => 'The event will run from 9:00 AM to 5:00 PM with lunch' .
            ' break at noon.',
          ],
        ],
        [
          'id' => 'detail2',
          'type' => 'paragraph.event_detail',
          'values' => [
            'detail_title' => 'Speakers',
            'detail_content' => 'Join us for presentations by industry experts and' .
            ' thought leaders.',
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

  /**
   * Returns comprehensive AI instructions for using JSON import.
   *
   * @return string
   *   AI-friendly instructions for using the JSON import system.
   */
  private function getAiInstructions(): string {
    return <<<EOT
# JSON Import System Instructions for AI Agents

## Overview
The JSON Import system allows you to create and import Drupal content structures (content types, fields, paragraphs) and content entities (nodes, paragraphs, media) via JSON files and drush commands.

## Available Drush Commands

### 1. Import JSON Content and Configuration
```bash
drush json_import:import /path/to/file.json
# Aliases: drush ji:import /path/to/file.json
```

### 2. Preview Import (No Changes Made)
```bash
drush json_import:import /path/to/file.json --preview
# Shows what would be imported without making actual changes
```

### 3. Generate Example JSON
```bash
drush json_import:example
# Output example JSON structure to console

drush json_import:example > example.json
# Save example JSON to a file
```

### 4. Get AI Instructions (This Command)
```bash
drush json_import:ai-instructions
```

## JSON File Structure

JSON files must contain one or both of these top-level keys:

### 1. "model" - Define Content Types and Paragraph Types
Creates content types, paragraph bundles, and their fields.

### 2. "content" - Create Content Entities
Creates actual content (nodes, paragraphs, media) with data.

## JSON Schema Overview

### Model Section Structure:
```json
{
  "model": [
    {
      "bundle": "content_type_machine_name",
      "label": "Human Readable Name",
      "description": "Description of the content type",
      "body": true,
      "fields": [
        {
          "id": "field_machine_name",
          "label": "Field Label",
          "type": "field_type"
        }
      ]
    }
  ]
}
```

### Content Section Structure:
```json
{
  "content": [
    {
      "id": "unique_identifier",
      "type": "entity_type.bundle_name",
      "values": {
        "field_name": "field_value",
        "reference_field": "@other_entity_id"
      }
    }
  ]
}
```

## Field Types Reference

### Basic Field Types:
- `string` - Short text field
- `text` - Long text field
- `bool` - Boolean (TRUE/FALSE)
- `int` - Integer number
- `float` - Decimal number
- `email` - Email address
- `url` - URL field
- `date` - Date field
- `datetime` - Date and time field
- `image` - Image field
- `file` - File field

### Reference Field Types:
- `term(vocabulary_name)` - Taxonomy term reference
- `term(vocabulary_name)[]` - Multiple taxonomy terms
- `paragraph(bundle_name)` - Single paragraph reference
- `paragraph(bundle_name)[]` - Multiple paragraph references
- `node(content_type)` - Node reference
- `node(content_type)[]` - Multiple node references

### Required Fields:
- Add `!` to make a field required: `"type": "string!"`

## Entity Types

### Supported Entity Types:
1. **node.bundle_name** - Content nodes (pages, articles, etc.)
2. **paragraph.bundle_name** - Paragraph entities (reusable content blocks)
3. **media.bundle_name** - Media entities (images, files, videos)
4. **term.vocabulary_name** - Taxonomy terms

## Content References

Use `@entity_id` to reference other entities within the same JSON file:

```json
{
  "content": [
    {
      "id": "my_paragraph",
      "type": "paragraph.text_block",
      "values": {
        "field_text": "Some content"
      }
    },
    {
      "id": "my_node",
      "type": "node.page",
      "values": {
        "title": "My Page",
        "field_paragraphs": ["@my_paragraph"]
      }
    }
  ]
}
```

## Image and File Handling

### For Image Fields:
```json
{
  "field_image": {
    "uri": "/path/to/image.jpg",
    "alt": "Alt text for accessibility"
  }
}
```

### For File Fields:
```json
{
  "field_file": {
    "uri": "/path/to/document.pdf",
    "description": "File description"
  }
}
```

## Best Practices for AI Agents

### 1. Structure Your JSON Logically
- Define paragraph bundles before nodes that reference them
- Create referenced entities before entities that reference them

### 2. Use Meaningful IDs
- Use descriptive IDs for content entities: `hero_section`, `main_content`, etc.
- IDs should be unique within the JSON file

### 3. Validate Before Import
- Always test with `--preview` flag first
- Check the drush output for validation errors

### 4. Handle Errors Gracefully
- Schema validation errors will be reported before import
- Fix validation issues before attempting import

### 5. Consider Field Cardinality
- Use arrays `[]` for multi-value fields
- Single values for single-value fields

## Example Complete JSON File

```json
{
  "model": [
    {
      "bundle": "landing_page",
      "label": "Landing Page",
      "description": "Marketing landing pages with flexible content",
      "body": false,
      "fields": [
        {
          "id": "field_hero_section",
          "label": "Hero Section",
          "type": "paragraph(hero)[]"
        },
        {
          "id": "field_content_sections",
          "label": "Content Sections",
          "type": "paragraph(content_section)[]"
        }
      ]
    },
    {
      "entity": "paragraph",
      "bundle": "hero",
      "label": "Hero Section",
      "fields": [
        {
          "id": "field_headline",
          "label": "Headline",
          "type": "string!"
        },
        {
          "id": "field_subheading",
          "label": "Subheading",
          "type": "text"
        },
        {
          "id": "field_hero_image",
          "label": "Hero Image",
          "type": "image"
        }
      ]
    }
  ],
  "content": [
    {
      "id": "hero1",
      "type": "paragraph.hero",
      "values": {
        "field_headline": "Welcome to Our Amazing Product",
        "field_subheading": "Discover the future of technology with our innovative solutions.",
        "field_hero_image": {
          "uri": "/modules/contrib/json_import/resources/placeholder.png",
          "alt": "Product hero image"
        }
      }
    },
    {
      "id": "landing1",
      "type": "node.landing_page",
      "values": {
        "title": "Product Landing Page",
        "field_hero_section": ["@hero1"],
        "status": 1
      }
    }
  ]
}
```

## Common Issues and Solutions

### Schema Validation Errors:
- Ensure all required fields are present
- Check field type syntax (use `!` for required fields)
- Verify entity type names match exactly

### Reference Errors:
- Ensure referenced entities exist in the same JSON file
- Use correct `@entity_id` syntax
- Check that field types support the entity being referenced

### Import Failures:
- Check that content types/bundles exist or are defined in model section
- Verify field machine names match exactly
- Ensure all dependencies are met

## Advanced Features

### Conditional Field Creation:
The system will create missing fields automatically based on the JSON schema.

### Multi-file Imports:
You can import multiple JSON files by running the command multiple times.

### Preview Mode:
Always test complex imports with `--preview` to see what changes would be made.

This system is designed to be flexible and powerful for creating complex Drupal content structures programmatically.

EOT;
  }

}
