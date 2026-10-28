# Schema.org

Renders one server-side schema.org JSON-LD `@graph` into the `<head>` of every
frontend page, so search engines and AI assistants get structured, linked data
about the hotel, its rooms and its offers.

## Installation

The package is not on Packagist. Add the GitHub repository to the project's
`composer.json` (or to `webtourismus/drupal-metapackage`):

```json
"repositories": {
  "webtourismus/schema_org": {
    "type": "vcs",
    "url": "https://github.com/webtourismus/schema_org.git"
  }
}
```

Then require and enable it:

```bash
ddev composer require webtourismus/schema_org
ddev drush en schema_org -y
ddev drush locale:import de modules/contrib/schema_org/translations/schema_org.de.po
ddev drush cex -y
```

It is installed to `web/modules/contrib/schema_org`.

## Graph

| Node             | `@id`                       | Source                                   |
|------------------|-----------------------------|------------------------------------------|
| `Hotel` (*)      | `<base>/#hotel`             | Settings form                            |
| `WebSite`        | `<base>/#website`           | Site name                                |
| `WebPage`        | `<page url>`                | Current page / node                      |
| `BreadcrumbList` | `<page url>#breadcrumb`     | Drupal breadcrumb                        |
| `HotelRoom`      | `<node url>#room`           | Room nodes + "Schema.org" tab            |
| `Offer`          | `<node url>#offer`          | Room/package price, booking deeplink     |

(*) Or another `LodgingBusiness` subtype, e.g. `Resort` or `BedAndBreakfast`.

Prices, occupancy, meal plan and minimum stay come from the existing fields
filled by the booking engine migrations (seekda, ...). Booking deeplinks come
from `ebr_accommodation` (`ActionableInterface::getActionUrl('book')`).

## Configuration

- Hotel profile: *Configuration › Search and metadata › Schema.org*
  (`/admin/config/search/schema-org`), translatable via config translation.
- Rooms and packages: tab "Schema.org" in the node edit form (bed, smoking,
  pets, price unit, validity, additional JSON properties).
- Both have an "additional properties (JSON)" field for anything not covered,
  e.g. `{"award": "…"}`.

After enabling the module, export the configuration with `drush cex`.

## Extending per project

Implement `hook_schema_org_graph_alter()` in a module or the theme, see
`schema_org.api.php`.

## Testing

Check the output with https://validator.schema.org/ and
https://search.google.com/test/rich-results.

## Development

Releases are git tags (`1.0.0`, `1.1.0`, ...), keep `version` in
`schema_org.info.yml` and `CHANGES.md` in sync.

Check coding standards (with drupal/coder installed):

```bash
phpcs
```
