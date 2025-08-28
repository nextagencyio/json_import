<?php

namespace Drupal\json_import\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\json_import\Service\DrupalContentImporter;
use Drupal\json_import\Service\JsonSchemaValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for importing Drupal content configuration.
 */
class JsonImportForm extends FormBase {

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
   * Constructs a new JsonImportForm.
   *
   * @param \Drupal\json_import\Service\DrupalContentImporter $importer
   *   The Drupal content importer service.
   * @param \Drupal\json_import\Service\JsonSchemaValidator $schema_validator
   *   The JSON schema validator service.
   */
  public function __construct(DrupalContentImporter $importer, JsonSchemaValidator $schema_validator) {
    $this->importer = $importer;
    $this->schemaValidator = $schema_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('json_import.importer'),
      $container->get('json_import.schema_validator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'json_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Import Drupal content types, paragraph types, and content from JSON configuration.') . '</p>' .
                   '<p><strong>' . $this->t('Note:') . '</strong> ' . $this->t('GraphQL Compose will be automatically configured for all imported content types with all GraphQL options and fields enabled.') . '</p>' .
                   '<p>' . $this->t('Your JSON will be validated against our <a href="@schema_url" target="_blank">JSON schema</a> for better error reporting.', ['@schema_url' => 'https://raw.githubusercontent.com/nextagencyio/json_import/refs/heads/1.x/resources/schema.json']) . '</p>',
    ];

    // Add collapsed fieldset with example JSON
    $form['example'] = [
      '#type' => 'details',
      '#title' => $this->t('Example JSON'),
      '#open' => FALSE,
    ];

    $form['example']['example_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Example Configuration'),
      '#description' => $this->t('This is an example JSON configuration showing the structure. You can copy from here.'),
      '#rows' => 20,
      '#default_value' => $this->getExampleJson(),
    ];

    $form['json_data'] = [
      '#type' => 'textarea',
      '#title' => $this->t('JSON Configuration'),
      '#rows' => 20,
      '#required' => TRUE,
    ];

    $form['preview'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Preview mode'),
      '#default_value' => FALSE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Import'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $json_data = $form_state->getValue('json_data');
    $data = json_decode($json_data, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      $form_state->setErrorByName('json_data', $this->t('Invalid JSON format: @error', [
        '@error' => json_last_error_msg(),
      ]));
      return;
    }

    // Validate against JSON schema first
    $schema_validation = $this->schemaValidator->validate($data);
    
    if (!$schema_validation['valid']) {
      foreach ($schema_validation['errors'] as $error) {
        $form_state->setErrorByName('json_data', $this->t('Schema validation error: @error', ['@error' => $error]));
      }
      return;
    }
    
    // Show schema validation warnings if any
    if (!empty($schema_validation['warnings'])) {
      foreach ($schema_validation['warnings'] as $warning) {
        $this->messenger()->addWarning($warning);
      }
    }

    // Validate the concise model structure (fallback validation).
    if (!$this->validateConciseModel($data)) {
      $form_state->setErrorByName('json_data', $this->t('Invalid JSON structure. Expected "model" and/or "content" arrays.'));
    }
  }

  /**
   * Validates the concise model structure.
   *
   * @param array $data
   *   The decoded JSON data.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  private function validateConciseModel(array $data): bool {
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
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $json_data = $form_state->getValue('json_data');
    $preview_mode = $form_state->getValue('preview');
    $data = json_decode($json_data, TRUE);

    try {
      $result = $this->importer->import($data, $preview_mode);

      if ($preview_mode) {
        $this->messenger()->addStatus($this->t('Preview completed successfully. @count operations would be performed.', [
          '@count' => count($result),
        ]));
      }
      else {
        $this->messenger()->addStatus($this->t('Import completed successfully. @count operations performed.', [
          '@count' => count($result),
        ]));
      }

      // Display the results.
      if (isset($result['summary']) && is_array($result['summary'])) {
        foreach ($result['summary'] as $message) {
          $this->messenger()->addStatus($message);
        }
      }
      if (isset($result['warnings']) && is_array($result['warnings'])) {
        foreach ($result['warnings'] as $warning) {
          $this->messenger()->addWarning($warning);
        }
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Import failed: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Returns example JSON for the form.
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

  /**
   * Returns static example JSON for the form.
   *
   * @return string
   *   Example JSON configuration.
   */
  private function getStaticExampleJson(): string {
    return '{
  "model": [
    {
      "bundle": "event",
      "description": "Content type for managing events, conferences, and gatherings",
      "label": "Event",
      "body": true,
      "fields": [
        {
          "id": "event_date",
          "label": "Event Date",
          "type": "datetime"
        },
        {
          "id": "location",
          "label": "Location",
          "type": "string"
        },
        {
          "id": "event_details",
          "label": "Event Details",
          "type": "paragraph(event_detail)[]"
        },
        {
          "id": "tags",
          "label": "Tags",
          "type": "term(tags)[]"
        },
        {
          "id": "featured",
          "label": "Featured",
          "type": "bool"
        }
      ]
    },
    {
      "entity": "paragraph",
      "bundle": "event_detail",
      "description": "Reusable content blocks for event information and details",
      "label": "Event Detail",
      "fields": [
        {
          "id": "detail_title",
          "label": "Detail Title",
          "type": "string!"
        },
        {
          "id": "detail_content",
          "label": "Detail Content",
          "type": "text"
        },
        {
          "id": "detail_image",
          "label": "Detail Image",
          "type": "image"
        }
      ]
    }
  ],
  "content": [
    {
      "id": "detail1",
      "type": "paragraph.event_detail",
      "values": {
        "detail_title": "Schedule",
        "detail_content": "The event will run from 9:00 AM to 5:00 PM with lunch break at noon."
      }
    },
    {
      "id": "detail2",
      "type": "paragraph.event_detail",
      "values": {
        "detail_title": "Speakers",
        "detail_content": "Join us for presentations by industry experts and thought leaders."
      }
    },
    {
      "id": "event1",
      "type": "node.event",
      "path": "/events/web-dev-conference-2024",
      "values": {
        "title": "Web Development Conference 2024",
        "body": "<p>Join us for a full day of learning about modern web development...</p>",
        "event_date": "2024-03-15T09:00:00",
        "location": "Convention Center Downtown",
        "tags": [
          "web-development",
          "conference",
          "technology"
        ],
        "featured": true,
        "event_details": ["@detail1", "@detail2"]
      }
    }
  ]
}';
  }

}
