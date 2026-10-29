# Changes

## 1.2.0

- "Schema.org" tab for pages with a page type and additional JSON:
  - About us, contact, FAQ, overview and gallery pages are output as the
    matching WebPage type (`AboutPage`, `ContactPage`, ...).
  - Hotel areas (restaurant, bar, café, spa, fitness, seminar) are output as
    their own place (`Restaurant`, `DaySpa`, ...), contained in the hotel and
    the page's main entity. The hotel lists them in `containsPlace`.
  - The additional JSON is added to the hotel area, otherwise to the page.
- Post update creates the fields on existing sites: run `drush updb` and
  `drush cex`.

## 1.1.1

- Settings page has a "Settings" tab, so config translation adds its
  "Translate schema.org" tab. Before, the translation page was not linked.
- Descriptions of translatable settings name that tab. The additional
  properties (JSON) are not translatable and now say so.

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
