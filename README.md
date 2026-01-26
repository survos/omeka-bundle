# survos/omeka-bundle

Symfony HttpClient wrapper for the Omeka-S REST API. PHP 8.4+, Symfony 7/8.

## Installation

```bash
composer require survos/omeka-bundle
```

```bash
# .env
OMEKA_LOCAL_API_URL=http://localhost:8088/api
OMEKA_LOCAL_KEY_IDENTITY=
OMEKA_LOCAL_KEY_CREDENTIAL=
OMEKA_REMOTE_KEY_IDENTITY=
OMEKA_REMOTE_KEY_CREDENTIAL=
```

```yaml
# config/packages/survos_omeka.yaml
survos_omeka:
  clients:
    local:
      api_url: '%env(OMEKA_LOCAL_API_URL)%'
      key_identity: '%env(default::OMEKA_LOCAL_KEY_IDENTITY)%'
      key_credential: '%env(default::OMEKA_LOCAL_KEY_CREDENTIAL)%'
    remote:
      api_url: 'https://dev.omeka.org/omeka-s-sandbox/api'
      key_identity: '%env(default::OMEKA_REMOTE_KEY_IDENTITY)%'
      key_credential: '%env(default::OMEKA_REMOTE_KEY_CREDENTIAL)%'
```

## Omeka S Sandbox
- Sandbox UI: https://dev.omeka.org/omeka-s-sandbox/
- Admin UI (redirects to login): https://dev.omeka.org/omeka-s-sandbox/admin
- API endpoint: https://dev.omeka.org/omeka-s-sandbox/api/
- Demo accounts (UI login only; not API keys):
  - demo1@example.com / Password1!
  - demo2@example.com / Password2@
  - demo3@example.com / Password3#

To write, you must create an API key for the logged-in user and set it in `.env`:

- Omeka S user manual (API keys): https://omeka.org/s/docs/user-manual/admin/users/#api-keys
- In the sandbox UI: Users → edit your user → API keys → add a key → save
- Copy the **Key identity** and **Key credential** into `OMEKA_KEY_IDENTITY` and `OMEKA_KEY_CREDENTIAL`

## Usage

```php
use Survos\OmekaBundle\Client\OmekaClient;
use Symfony\Component\DependencyInjection\Attribute\Target;

class ArchiveService
{
    public function __construct(
        #[Target('omeka.local')] private OmekaClient $localOmeka,
        #[Target('omeka.remote')] private OmekaClient $remoteOmeka,
    ) {}
    
    public function import(): void
    {
        // Read
        $items = $this->remoteOmeka->getItems(resourceTemplateId: 5, perPage: 50);
        $item = $this->remoteOmeka->getItem(123);
        $templates = $this->remoteOmeka->getResourceTemplates();
        
        // Search
        $results = $this->remoteOmeka->searchItems('civil war', fulltextSearch: true);
        $filtered = $this->remoteOmeka->filterItemsByProperty('dcterms:creator', 'Smith', 'in');
        
        // Create
        $newItem = $this->localOmeka->createItem([
            'dcterms:title' => 'Letter from John Smith',
            'dcterms:date' => '1862-04-15',
            'dcterms:type' => 'Correspondence',
        ], templateId: 5);
        
        // With media
        $itemWithMedia = $this->localOmeka->createItem($metadata, templateId: 5, mediaFiles: [
            '/path/to/scan.tiff',
            ['path' => '/path/to/ocr.pdf', 'title' => 'OCR Text'],
        ]);
        
        // Update / Delete
        $this->localOmeka->updateItem(123, ['dcterms:title' => 'Updated Title']);
        $this->localOmeka->deleteItem(123);
    }
}
```

## Fluent Payload Builder

```php
$item = $this->omeka->payloadBuilder(templateId: 5)
    ->set('dcterms:title', 'My Document')
    ->set('dcterms:date', '1862-04-15')
    ->set('dcterms:creator', 'John Smith')
    ->setUri('dcterms:source', 'https://archive.org/item/123')
    ->addMedia('/path/to/scan.tiff', 'Page 1')
    ->inItemSet(10)
    ->create();
```

## Template-Validated Payloads

Validate metadata against Omeka resource templates before submission:

```php
// Fetch template constraints
$template = $this->omeka->getResourceTemplate(5);
$properties = $this->omeka->getTemplateProperties(5);
// Returns: ['dcterms:title' => ['property_id' => 1, 'types' => ['literal']], ...]

// Build validated payload - warns on invalid terms, auto-assigns property IDs
$payload = $this->omeka->buildPayload($metadata, templateId: 5);
```

## For AI Cataloging (ScanStation Use Case)

```php
// Pull vocab constraints for AI prompt
$vocabs = $this->omeka->getCustomVocabTerms('Document Types');
$subjects = $this->omeka->getCustomVocabTerms('Local Subjects');

$prompt = "Catalog this document using ONLY these values:
  dcterms:type: " . implode(', ', $vocabs) . "
  dcterms:subject: " . implode(', ', $subjects);

// AI returns structured data → validate → push
$aiMetadata = $llm->catalog($image, $prompt);
$item = $this->omeka->createItem($aiMetadata, templateId: 5, mediaFiles: [$scanPath]);
```

## API Reference

| Method | Description |
|--------|-------------|
| `getItems(?int $templateId, ?int $itemSetId, int $page, int $perPage)` | List/filter items |
| `getItem(int $id)` | Get single item |
| `createItem(array $properties, ?int $templateId, ?array $mediaFiles)` | Create item |
| `updateItem(int $id, array $properties)` | Update item |
| `deleteItem(int $id)` | Delete item |
| `searchItems(string $query, bool $fulltextSearch)` | Search items |
| `filterItemsByProperty(string $property, string $value, string $type)` | Filter by property |
| `getResourceTemplates()` | List templates |
| `getResourceTemplate(int $id)` | Get single template |
| `getResourceTemplateByLabel(string $label)` | Get template by name |
| `getTemplateProperties(int $templateId)` | Get template field definitions |
| `getProperties()` | Get all properties |
| `getProperty(string $term)` | Get property by term |
| `getPropertyId(string $term)` | Get property ID by term |
| `getCustomVocabTerms(string $label)` | Get controlled vocabulary terms |
| `addMediaToItem(int $itemId, string $path, ?array $metadata)` | Attach media |
| `getItemSets(int $page, int $perPage)` | List item sets |
| `payloadBuilder(?int $templateId)` | Get fluent payload builder |
| `getSites()` | List sites |
| `createSite(string $slug, string $title, ?string $theme, bool $isPublic)` | Create site |

## Item Model

```php
$item = $this->omeka->getItem(123);

$item->id;                    // int
$item->title;                 // string
$item->isPublic;              // bool
$item->created;               // ?DateTimeImmutable
$item->resourceTemplateId;    // ?int
$item->mediaIds;              // int[]
$item->properties;            // array - raw property data

// Convenience accessors
$item->getPropertyValue('dcterms:creator');    // ?string - first value
$item->getPropertyValues('dcterms:subject');   // string[] - all values
```

## Testing Against Sandbox

```php
// Uses public sandbox - resets Mon/Wed/Fri/Sun
// Remote client points to https://dev.omeka.org/omeka-s-sandbox/api
$items = $this->remoteOmeka->getItems(); // Anonymous read access
```

For write access, log into the sandbox UI, create an API key (not your password), and set credentials.

## Sync Command

```bash
bin/console omeka:sync remote local --with-vocabularies --with-properties --with-templates --with-sites --with-item-sets --with-items --with-media --with-site-items --with-site-pages -v
```

## License

MIT
