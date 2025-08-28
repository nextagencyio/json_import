# JSON Import Module

## Overview

The JSON Import module allows you to import Drupal content types, paragraph types, and content from JSON configuration via an admin form or drush command. This module is forked from `dcloud_import` but focuses specifically on JSON-based imports without API functionality.

## Features

- Admin form interface for importing JSON configurations
- Drush command for command-line imports
- Support for content types, paragraph types, fields, and content
- Preview mode to see what would be imported
- Comprehensive validation of JSON structure

## Installation

1. Place the module in `modules/custom/json_import`
2. Enable the module: `drush en json_import`

## Usage

### Admin Interface

Navigate to **Configuration** → **Content authoring** → **JSON Content Import** (`/admin/config/content/json-import`)

The form provides:
- JSON input field with validation
- Example JSON configuration
- Preview mode option

### Drush Command

Import from command line:

```bash
# Import from a JSON file
drush json_import:import /path/to/config.json

# Preview what would be imported
drush json_import:import /path/to/config.json --preview

# Using alias
drush ji:import /path/to/config.json
```

## JSON Structure

The module expects JSON with `model` and/or `content` arrays:

```json
{
  "model": [
    {
      "bundle": "event",
      "description": "Content type for events",
      "label": "Event",
      "body": true,
      "fields": [
        {
          "id": "event_date",
          "label": "Event Date", 
          "type": "datetime"
        }
      ]
    }
  ],
  "content": [
    {
      "id": "event1",
      "type": "node.event",
      "values": {
        "title": "Sample Event",
        "event_date": "2024-03-15T09:00:00"
      }
    }
  ]
}
```

## Permissions

- **Import JSON Configuration**: Required to use both the admin form and drush command

## Requirements

- Drupal 10 or 11
- Paragraphs module (for paragraph type imports)

## Technical Details

### Services

- `json_import.importer`: Main import service
- `json_import.field_type_mapper`: Maps field types  
- `json_import.commands`: Drush command service

### Key Classes

- `JsonImportForm`: Admin form
- `JsonImportCommands`: Drush commands
- `DrupalContentImporter`: Core import logic

### File Structure

```
json_import/
├── json_import.info.yml
├── json_import.permissions.yml  
├── json_import.routing.yml
├── json_import.services.yml
├── json_import.links.menu.yml
├── src/
│   ├── Commands/
│   │   └── JsonImportCommands.php
│   ├── Form/
│   │   └── JsonImportForm.php
│   └── Service/
│       ├── DrupalContentImporter.php
│       ├── FieldTypeMapper.php
│       └── FileValidationService.php
└── resources/
    └── sample.json
```

## Differences from dcloud_import

- Removed all API endpoints and controllers
- Simplified permissions to single import permission
- Added drush command functionality  
- Focused on JSON import only
- Updated module metadata and menu placement