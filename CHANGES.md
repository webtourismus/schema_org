# Changes

## 1.1.0

- New package fields in the "Schema.org" tab:
  - Category (schema.org `Offer.category`): a fixed list of themes shared by
    all websites, e.g. wellness, skiing, hiking.
  - Target group (schema.org `audience` of the offered service), e.g.
    families, couples, adults only (`PeopleAudience` with minimum age 18).
  - Inclusions (schema.org `Offer.includesObject`), together with the meal
    plan.
- Post update creates the fields on existing sites: run `drush updb` and
  `drush cex`.

## 1.0.1

- README: import only the module's German translation file. `drush
  locale:update` imports translations of all projects and can change
  translated configuration of other modules.

## 1.0.0

- Initial release, extracted from the factory.webtourismus.at starterkit.
- Server-rendered JSON-LD `@graph`: LodgingBusiness (Hotel, Resort, ...),
  WebSite, WebPage, BreadcrumbList, HotelRoom and Offer.
- Settings form for the hotel profile, translatable via config translation.
- "Schema.org" tab for rooms and packages: bed, smoking, pets, price unit,
  validity and additional JSON properties.
- `hook_schema_org_graph_alter()` for per-project changes.
- German interface translation.
