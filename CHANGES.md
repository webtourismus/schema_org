# Changes

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
