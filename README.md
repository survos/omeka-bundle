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

## Dokku Deployment (Omeka S Image)

Use the prebuilt Omeka S image for production and keep it separate from the Symfony app.

1) Create a shared MySQL service and app:

```bash
dokku mysql:create omeka-db
dokku apps:create kpa-omeka
dokku mysql:link omeka-db kpa-omeka
```

2) Create a dedicated database and user for this app:

```bash
dokku mysql:enter omeka-db env | grep MYSQL_ROOT_PASSWORD
dokku mysql:enter omeka-db mysql -uroot -p
```

```sql
CREATE DATABASE kpa_omeka;
CREATE USER 'kpa_omeka'@'%' IDENTIFIED BY 'kpa_omeka_secret';
GRANT ALL PRIVILEGES ON kpa_omeka.* TO 'kpa_omeka'@'%';
FLUSH PRIVILEGES;
```

3) Set Omeka env vars and deploy the image:

```bash
dokku config:set kpa-omeka \
  OMEKA_ADMIN_EMAIL=admin@admin.com \
  OMEKA_ADMIN_PASSWORD=admin \
  OMEKA_ADMIN_NAME="KPA Admin" \
  OMEKA_SITE_TITLE="KPA Omeka" \
  OMEKA_TIMEZONE=UTC \
  OMEKA_LOCALE=en_US \
  DB_HOST=dokku-mysql-omeka-db \
  DB_NAME=kpa_omeka \
  DB_USER=kpa_omeka \
  DB_PASSWORD=kpa_omeka_secret

dokku git:from-image kpa-omeka erseco/alpine-omeka-s:latest
dokku ps:rebuild kpa-omeka
```

4) Persist uploads and logs (optional but recommended):

```bash
mkdir -p /var/lib/dokku/data/storage/kpa-omeka/{omeka_files,omeka_logs}
dokku storage:mount kpa-omeka /var/lib/dokku/data/storage/kpa-omeka/omeka_files:/var/www/html/volume
dokku storage:mount kpa-omeka /var/lib/dokku/data/storage/kpa-omeka/omeka_logs:/var/www/html/logs
```

If you have a larger disk mounted, prefer mounting that location instead of `/var/lib/dokku/data/storage`:

```bash
mkdir -p /mnt/volume-1/omeka/kpa/files/config \
  /mnt/volume-1/omeka/kpa/files/files \
  /mnt/volume-1/omeka/kpa/files/modules \
  /mnt/volume-1/omeka/kpa/files/themes \
  /mnt/volume-1/omeka/kpa/logs
chown -R 65534:65534 /mnt/volume-1/omeka/kpa/files /mnt/volume-1/omeka/kpa/logs
chmod -R u+rwX,g+rwX /mnt/volume-1/omeka/kpa/files /mnt/volume-1/omeka/kpa/logs
dokku storage:mount kpa-omeka /mnt/volume-1/omeka/kpa/files:/var/www/html/volume
dokku storage:mount kpa-omeka /mnt/volume-1/omeka/kpa/logs:/var/www/html/logs
```

If permissions fail, confirm the container UID/GID and adjust:

```bash
dokku enter kpa-omeka web id
```

5) Set the domain and ports:

```bash
dokku domains:set kpa-omeka kpa-omeka.survos.com
dokku ports:set kpa-omeka http:80:8080
```

6) Enable HTTPS (Let’s Encrypt):

```bash
dokku letsencrypt:set kpa-omeka email you@example.com
dokku letsencrypt:enable kpa-omeka
```

7) Verify and troubleshoot:

```bash
dokku ps:report kpa-omeka
dokku ports:report kpa-omeka
dokku domains:report kpa-omeka
dokku logs kpa-omeka --tail
```

If you see the default nginx page, the app is only exposed on port 8080. Fix with:

```bash
dokku ports:set kpa-omeka http:80:8080
dokku ps:restart kpa-omeka
```

Repeat steps 2-6 for each additional Omeka instance (new DB/user, new app name, new domain).

## Troubleshooting: Theme Not Found

If you get `The current theme is not active. Its current state is "not_found"`, the themes directory is empty in the mounted volume. Seed themes/modules from the image:

```bash
dokku enter kpa-omeka web sh -lc "\
cp -a /var/www/html/themes/. /var/www/html/volume/themes/ 2>/dev/null || true
cp -a /var/www/html/modules/. /var/www/html/volume/modules/ 2>/dev/null || true
"
chown -R 65534:65534 /mnt/volume-1/omeka/kpa/files
chmod -R u+rwX,g+rwX /mnt/volume-1/omeka/kpa/files
dokku ps:restart kpa-omeka
```

## Troubleshooting: disk_free_space Warning

If you see `disk_free_space(): No such file or directory`, Omeka is checking a missing files directory. Ensure `files/files` exists and set the file store base path to the mounted volume.

Create the directory:

```bash
mkdir -p /mnt/volume-1/omeka/kpa/files/files
chown -R 65534:65534 /mnt/volume-1/omeka/kpa/files
```

Set file store paths in `local.config.php` (persisted in the mounted config dir):

`/mnt/volume-1/omeka/kpa/files/config/local.config.php`

```php
<?php
return [
    'file_store' => [
        'local' => [
            'base_path' => '/var/www/html/volume/files',
            'base_uri' => '/files',
        ],
    ],
];
```

Then restart:

```bash
dokku ps:restart kpa-omeka
```

## Multi-tenant Plan (Future)

Omeka S is not multi-tenant. The safest approach is one Omeka app per tenant, all linked to a shared MySQL service with separate databases/users. If we later need true multi-tenant behavior, that would be a custom Omeka fork or middleware layer and a non-trivial project. For now, automate the per-tenant app flow (create app, DB/user, env vars, domain, storage mounts) and keep it repeatable.

## Planned Sites

- `kpa-omeka` is live and public.
- `rhs-omeka` (Rapp Historical Society) should be deployed as a separate app and kept password-protected until policy is finalized.
- Optional sample/stress-test sites: `larco-omeka`, `fortepan-omeka`, `smithsonian-omeka`.

## Password Protection (RHS)

For a temporary lock-down, use basic auth at the Dokku nginx layer.

```bash
sudo apt-get install -y apache2-utils
sudo htpasswd -c /home/dokku/.htpasswd-rhs rhs
dokku nginx:set rhs-omeka nginx-http-authentication "basic:/home/dokku/.htpasswd-rhs"
dokku ps:restart rhs-omeka
```

To disable later:

```bash
dokku nginx:set rhs-omeka nginx-http-authentication
dokku ps:restart rhs-omeka
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
