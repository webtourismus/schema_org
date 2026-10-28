<?php

declare(strict_types=1);

namespace Drupal\schema_org;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Breadcrumb\ChainBreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Drupal\ebr\Entity\ActionableInterface;
use Drupal\file\FileInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the schema.org JSON-LD graph for the current page.
 *
 * The graph uses stable "@id" references so search engines and AI crawlers
 * can merge the nodes across pages:
 * - <base>#hotel: the lodging business (from schema_org.settings).
 * - <base>#website: the website, published by the hotel.
 * - <page url>: the WebPage, part of the website, about the hotel.
 * - <page url>#breadcrumb: the BreadcrumbList of the page.
 * - <node url>#room: a HotelRoom, contained in the hotel.
 * - <node url>#offer: an Offer, offered by the hotel.
 *
 * Other modules and themes can change the graph with
 * hook_schema_org_graph_alter().
 */
class SchemaGraphBuilder {

  use StringTranslationTrait;

  /**
   * Field names provided by this module.
   */
  public const FIELD_BED_TYPE = 'field_schema_bed_type';
  public const FIELD_BED_COUNT = 'field_schema_bed_count';
  public const FIELD_SMOKING = 'field_schema_smoking';
  public const FIELD_PETS = 'field_schema_pets';
  public const FIELD_PRICE_UNIT = 'field_schema_price_unit';
  public const FIELD_VALIDITY = 'field_schema_validity';
  public const FIELD_JSON = 'field_schema_json';
  public const FIELD_CATEGORY = 'field_schema_category';
  public const FIELD_AUDIENCE = 'field_schema_audience';
  public const FIELD_INCLUSIONS = 'field_schema_inclusions';

  /**
   * Field names of the webtourismus starterkit used as data sources.
   */
  protected const FIELD_BODY = 'body';
  protected const FIELD_SUBTITLE = 'field_subtitle';
  protected const FIELD_IMAGES = 'field_images';
  protected const FIELD_AMENITY = 'field_amenity';
  protected const FIELD_PRICE = 'field_price';
  protected const FIELD_ROOMSIZE = 'field_roomsize';
  protected const FIELD_OCCUPANCY_STD = 'field_occupancy_std';
  protected const FIELD_OCCUPANCY_MAX = 'field_occupancy_max';
  protected const FIELD_MINLOS = 'field_minlos';
  protected const FIELD_MEALPLAN = 'field_mealplan';

  /**
   * Maximum length of generated descriptions.
   */
  protected const DESCRIPTION_LENGTH = 300;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityRepositoryInterface $entityRepository,
    protected LanguageManagerInterface $languageManager,
    protected RouteMatchInterface $routeMatch,
    protected AdminContext $adminContext,
    protected PathMatcherInterface $pathMatcher,
    protected TitleResolverInterface $titleResolver,
    protected ChainBreadcrumbBuilderInterface $breadcrumb,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected RequestStack $requestStack,
    protected ModuleHandlerInterface $moduleHandler,
    protected ThemeManagerInterface $themeManager,
    protected ThemeSettingsProvider $themeSettingsProvider,
  ) {}

  /**
   * Builds the JSON-LD document for the current route.
   *
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of everything the graph depends on.
   *
   * @return array<string, mixed>
   *   The JSON-LD document with "@context" and "@graph", or an empty array if
   *   no graph should be output on this route.
   */
  public function build(CacheableMetadata $cacheability): array {
    $config = $this->configFactory->get('schema_org.settings');
    $cacheability
      ->addCacheableDependency($config)
      ->addCacheContexts(['route', 'url.site', 'languages:language_interface', 'languages:language_content']);

    if (!$config->get('enabled') || $this->adminContext->isAdminRoute()) {
      return [];
    }
    if (in_array($this->routeMatch->getRouteName(), ['system.403', 'system.404', 'system.401'], TRUE)) {
      return [];
    }

    $baseUrl = $this->getBaseUrl();
    $hotelId = $baseUrl . '#hotel';
    $websiteId = $baseUrl . '#website';
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();

    $graph = [
      'hotel' => $this->buildHotel($config, $hotelId, $cacheability),
      'website' => $this->buildWebsite($websiteId, $hotelId, $langcode, $cacheability),
    ];

    $node = $this->getCurrentNode($cacheability);
    $page = $this->buildWebPage($node, $websiteId, $hotelId, $langcode, $cacheability);

    if ($node instanceof NodeInterface) {
      $nodeUrl = $page['url'];
      $roomBundles = $config->get('room_bundles') ?? [];
      $offerBundles = $config->get('offer_bundles') ?? [];

      if (in_array($node->bundle(), $roomBundles, TRUE)) {
        $graph['room'] = $this->buildRoom($node, $nodeUrl, $hotelId, $config, $cacheability);
        $page['mainEntity'] = ['@id' => $graph['room']['@id']];
        $offer = $this->buildOffer($node, $nodeUrl, $hotelId, $config, $cacheability, ['@id' => $graph['room']['@id']]);
        // A room without price and booking link is no meaningful offer.
        if (isset($offer['price']) || $offer['url'] !== $nodeUrl) {
          $graph['offer'] = $offer;
        }
        $graph['room'] = $this->applyJsonOverrides($graph['room'], $node);
      }
      elseif (in_array($node->bundle(), $offerBundles, TRUE)) {
        $graph['offer'] = $this->buildOffer($node, $nodeUrl, $hotelId, $config, $cacheability, [
          '@type' => 'Service',
          'name' => $node->label(),
          'serviceType' => (string) $this->t('Hotel package', [], ['context' => 'schema.org']),
          'provider' => ['@id' => $hotelId],
          // Offer has no "audience" property, the offered service has.
          'audience' => $this->buildAudiences($node),
        ]);
        $graph['offer'] = $this->applyJsonOverrides($graph['offer'], $node);
        $page['mainEntity'] = ['@id' => $graph['offer']['@id']];
      }
    }
    $cacheability->addCacheContexts(['url.path.is_front']);
    if ($this->pathMatcher->isFrontPage()) {
      // The front page is identified by the site URL, not by its node path.
      $page['@id'] = $page['url'] = $this->getFrontUrl($cacheability);
      $page['mainEntity'] ??= ['@id' => $hotelId];
    }

    $breadcrumb = $this->buildBreadcrumb($page, $cacheability);
    if ($breadcrumb !== []) {
      $graph['breadcrumb'] = $breadcrumb;
      $page['breadcrumb'] = ['@id' => $breadcrumb['@id']];
    }
    $graph['webpage'] = $page;

    $context = [
      'route_match' => $this->routeMatch,
      'node' => $node,
      'langcode' => $langcode,
      'base_url' => $baseUrl,
    ];
    $this->moduleHandler->alter('schema_org_graph', $graph, $context, $cacheability);
    $this->themeManager->alter('schema_org_graph', $graph, $context, $cacheability);

    $graph = array_values(array_filter(array_map([$this, 'removeEmptyValues'], $graph)));
    if ($graph === []) {
      return [];
    }
    return [
      '@context' => 'https://schema.org',
      '@graph' => $graph,
    ];
  }

  /**
   * Builds the lodging business node.
   *
   * @return array<string, mixed>
   *   The schema.org LodgingBusiness (or subtype) node.
   */
  protected function buildHotel(ImmutableConfig $config, string $hotelId, CacheableMetadata $cacheability): array {
    $siteConfig = $this->configFactory->get('system.site');
    $cacheability->addCacheableDependency($siteConfig);
    $currency = $config->get('currency') ?: 'EUR';

    $hotel = [
      '@type' => $config->get('business_type') ?: 'Hotel',
      '@id' => $hotelId,
      'name' => $config->get('name') ?: $siteConfig->get('name'),
      'alternateName' => $config->get('alternate_name'),
      'description' => $config->get('description'),
      'url' => $this->getFrontUrl($cacheability),
      'logo' => $this->getLogoUrl($config, $cacheability),
      'image' => $this->getMediaImageUrls(
        $this->loadByUuids('media', $config->get('image_uuids') ?? []),
        (string) $config->get('image_style'),
        $cacheability,
      ),
      'telephone' => $config->get('telephone'),
      'email' => $config->get('email'),
      'address' => [
        '@type' => 'PostalAddress',
        'streetAddress' => $config->get('address.street_address'),
        'postalCode' => $config->get('address.postal_code'),
        'addressLocality' => $config->get('address.locality'),
        'addressRegion' => $config->get('address.region'),
        'addressCountry' => $config->get('address.country_code'),
      ],
      'checkinTime' => $config->get('checkin_time'),
      'checkoutTime' => $config->get('checkout_time'),
      'numberOfRooms' => (int) $config->get('number_of_rooms') ?: NULL,
      'petsAllowed' => $this->mapPetsAllowed((string) $config->get('pets_allowed')),
      'priceRange' => $config->get('price_range'),
      'currenciesAccepted' => $currency,
      'paymentAccepted' => $config->get('payment_accepted'),
      'availableLanguage' => $config->get('available_languages'),
      'amenityFeature' => $this->buildAmenityFeatures(
        $this->loadByUuids('taxonomy_term', $config->get('amenity_uuids') ?? []),
        $cacheability,
      ),
      'sameAs' => $config->get('same_as'),
    ];

    $latitude = (string) $config->get('latitude');
    $longitude = (string) $config->get('longitude');
    if ($latitude !== '' && $longitude !== '') {
      $hotel['geo'] = [
        '@type' => 'GeoCoordinates',
        'latitude' => (float) $latitude,
        'longitude' => (float) $longitude,
      ];
      $hotel['hasMap'] = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($latitude . ',' . $longitude);
    }

    if ($starRating = (string) $config->get('star_rating')) {
      $hotel['starRating'] = [
        '@type' => 'Rating',
        'ratingValue' => $starRating,
        'alternateName' => $config->get('star_rating_superior') ? $starRating . 'S' : NULL,
      ];
    }

    if ($bookingUrl = (string) $config->get('booking_url')) {
      $hotel['potentialAction'] = [
        '@type' => 'ReserveAction',
        'target' => [
          '@type' => 'EntryPoint',
          'urlTemplate' => $bookingUrl,
          'actionPlatform' => [
            'https://schema.org/DesktopWebPlatform',
            'https://schema.org/MobileWebPlatform',
          ],
        ],
      ];
    }

    $extra = $this->decodeJsonObject((string) $config->get('extra_properties'));
    unset($extra['@id']);
    return array_replace($hotel, $extra);
  }

  /**
   * Builds the WebSite node.
   *
   * @return array<string, mixed>
   *   The schema.org WebSite node.
   */
  protected function buildWebsite(string $websiteId, string $hotelId, string $langcode, CacheableMetadata $cacheability): array {
    $siteConfig = $this->configFactory->get('system.site');
    return [
      '@type' => 'WebSite',
      '@id' => $websiteId,
      'url' => $this->getFrontUrl($cacheability),
      'name' => $siteConfig->get('name'),
      'inLanguage' => $langcode,
      'publisher' => ['@id' => $hotelId],
    ];
  }

  /**
   * Builds the WebPage node for the current page.
   *
   * @return array<string, mixed>
   *   The schema.org WebPage node.
   */
  protected function buildWebPage(?NodeInterface $node, string $websiteId, string $hotelId, string $langcode, CacheableMetadata $cacheability): array {
    if ($node instanceof NodeInterface) {
      $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE);
      $cacheability->addCacheableDependency($url);
      return [
        '@type' => 'WebPage',
        '@id' => $url->getGeneratedUrl(),
        'url' => $url->getGeneratedUrl(),
        'name' => $node->label(),
        'description' => $this->getNodeDescription($node),
        'inLanguage' => $langcode,
        'isPartOf' => ['@id' => $websiteId],
        'about' => ['@id' => $hotelId],
        'primaryImageOfPage' => $this->getNodeImageUrls($node, $cacheability)[0] ?? NULL,
        'datePublished' => date('c', (int) $node->getCreatedTime()),
        'dateModified' => date('c', (int) $node->getChangedTime()),
      ];
    }

    $url = Url::fromRoute('<current>', [], ['absolute' => TRUE])->toString(TRUE);
    $cacheability->addCacheableDependency($url);
    $page = [
      '@type' => 'WebPage',
      '@id' => $url->getGeneratedUrl(),
      'url' => $url->getGeneratedUrl(),
      'inLanguage' => $langcode,
      'isPartOf' => ['@id' => $websiteId],
      'about' => ['@id' => $hotelId],
    ];
    $request = $this->requestStack->getCurrentRequest();
    $route = $this->routeMatch->getRouteObject();
    if ($request && $route) {
      $title = $this->titleResolver->getTitle($request, $route);
      if (is_string($title) || $title instanceof \Stringable) {
        $page['name'] = $this->toPlainText((string) $title);
      }
    }
    return $page;
  }

  /**
   * Builds a HotelRoom node from a room node.
   *
   * @return array<string, mixed>
   *   The schema.org HotelRoom node.
   */
  protected function buildRoom(NodeInterface $node, string $nodeUrl, string $hotelId, ImmutableConfig $config, CacheableMetadata $cacheability): array {
    $room = [
      '@type' => 'HotelRoom',
      '@id' => $nodeUrl . '#room',
      'name' => $node->label(),
      'description' => $this->getNodeDescription($node),
      'url' => $nodeUrl,
      'image' => $this->getNodeImageUrls($node, $cacheability),
      'containedInPlace' => ['@id' => $hotelId],
    ];

    $occupancyStd = $this->getScalar($node, static::FIELD_OCCUPANCY_STD);
    $occupancyMax = $this->getScalar($node, static::FIELD_OCCUPANCY_MAX);
    if ($occupancyStd !== NULL || $occupancyMax !== NULL) {
      $room['occupancy'] = [
        '@type' => 'QuantitativeValue',
        'value' => $occupancyStd !== NULL ? (int) $occupancyStd : NULL,
        'maxValue' => $occupancyMax !== NULL ? (int) $occupancyMax : NULL,
        'unitCode' => 'C62',
      ];
    }

    if (($size = $this->getScalar($node, static::FIELD_ROOMSIZE)) !== NULL) {
      $room['floorSize'] = [
        '@type' => 'QuantitativeValue',
        'value' => (float) $size,
        'unitCode' => 'MTK',
      ];
    }

    if ($node->hasField(static::FIELD_AMENITY)) {
      $room['amenityFeature'] = $this->buildAmenityFeatures($this->getReferencedEntities($node->get(static::FIELD_AMENITY)), $cacheability);
    }

    $bedType = $this->getOptionLabel($node, static::FIELD_BED_TYPE);
    $bedCount = $this->getScalar($node, static::FIELD_BED_COUNT);
    if ($bedType !== NULL || $bedCount !== NULL) {
      $room['bed'] = [
        '@type' => 'BedDetails',
        'typeOfBed' => $bedType,
        'numberOfBeds' => $bedCount !== NULL ? (int) $bedCount : NULL,
      ];
    }

    $smoking = $this->getScalar($node, static::FIELD_SMOKING);
    if ($smoking !== NULL) {
      $room['smokingAllowed'] = $smoking === 'yes';
    }

    $pets = (string) $this->getScalar($node, static::FIELD_PETS);
    $room['petsAllowed'] = $this->mapPetsAllowed($pets !== '' ? $pets : (string) $config->get('pets_allowed'));

    return $room;
  }

  /**
   * Builds an Offer node for a room or a package.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The room or package node.
   * @param string $nodeUrl
   *   The absolute URL of the node.
   * @param string $hotelId
   *   The "@id" of the hotel.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The schema_org.settings config.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The cacheability metadata.
   * @param array<string, mixed> $itemOffered
   *   The item offered, either an "@id" reference or an inline node.
   *
   * @return array<string, mixed>
   *   The schema.org Offer node.
   */
  protected function buildOffer(NodeInterface $node, string $nodeUrl, string $hotelId, ImmutableConfig $config, CacheableMetadata $cacheability, array $itemOffered): array {
    $currency = $config->get('currency') ?: 'EUR';
    $offer = [
      '@type' => 'Offer',
      '@id' => $nodeUrl . '#offer',
      'name' => $node->label(),
      'description' => $this->getNodeDescription($node),
      'url' => $this->getBookingUrl($node, $cacheability) ?? $nodeUrl,
      'image' => $this->getNodeImageUrls($node, $cacheability),
      'itemOffered' => $itemOffered,
      'offeredBy' => ['@id' => $hotelId],
    ];

    $price = $this->getScalar($node, static::FIELD_PRICE);
    if ($price !== NULL && is_numeric($price)) {
      $price = number_format((float) $price, 2, '.', '');
      $offer['price'] = $price;
      $offer['priceCurrency'] = $currency;
      $unit = (string) $this->getScalar($node, static::FIELD_PRICE_UNIT);
      if ($unit !== '') {
        $offer['priceSpecification'] = [
          '@type' => 'UnitPriceSpecification',
          'price' => $price,
          'priceCurrency' => $currency,
          'unitText' => $this->getOptionLabel($node, static::FIELD_PRICE_UNIT),
          'referenceQuantity' => in_array($unit, ['per_night', 'per_person_night'], TRUE) ? [
            '@type' => 'QuantitativeValue',
            'value' => 1,
            'unitCode' => 'DAY',
          ] : NULL,
        ];
      }
    }

    if ($node->hasField(static::FIELD_VALIDITY) && !$node->get(static::FIELD_VALIDITY)->isEmpty()) {
      $validity = $node->get(static::FIELD_VALIDITY)->first()?->getValue() ?? [];
      $offer['validFrom'] = $validity['value'] ?? NULL;
      $offer['validThrough'] = $validity['end_value'] ?? NULL;
    }

    if (($minlos = $this->getScalar($node, static::FIELD_MINLOS)) !== NULL && (int) $minlos > 0) {
      $offer['eligibleDuration'] = [
        '@type' => 'QuantitativeValue',
        'minValue' => (int) $minlos,
        'unitCode' => 'DAY',
      ];
    }

    $offer['category'] = array_values($this->getOptionLabels($node, static::FIELD_CATEGORY));

    // The meal plan and all inclusions are services included in the offer.
    $inclusions = [$this->getOptionLabel($node, static::FIELD_MEALPLAN)];
    if ($node->hasField(static::FIELD_INCLUSIONS)) {
      foreach ($node->get(static::FIELD_INCLUSIONS) as $item) {
        $inclusions[] = trim((string) ($item->getValue()['value'] ?? ''));
      }
    }
    $offer['includesObject'] = array_map(
      static fn (string $name): array => [
        '@type' => 'TypeAndQuantityNode',
        'amountOfThisGood' => 1,
        'typeOfGood' => [
          '@type' => 'Service',
          'name' => $name,
        ],
      ],
      array_values(array_unique(array_filter($inclusions, static fn (?string $name): bool => (string) $name !== ''))),
    );

    return $offer;
  }

  /**
   * Builds the BreadcrumbList node from Drupal's breadcrumb.
   *
   * @param array<string, mixed> $page
   *   The WebPage node of the current page.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The cacheability metadata.
   *
   * @return array<string, mixed>
   *   The schema.org BreadcrumbList node, or an empty array.
   */
  protected function buildBreadcrumb(array $page, CacheableMetadata $cacheability): array {
    $breadcrumb = $this->breadcrumb->build($this->routeMatch);
    $cacheability->addCacheableDependency($breadcrumb);

    $items = [];
    foreach ($breadcrumb->getLinks() as $link) {
      assert($link instanceof Link);
      $text = $link->getText();
      if (!is_string($text) && !$text instanceof \Stringable) {
        continue;
      }
      $url = (clone $link->getUrl())->setAbsolute()->toString(TRUE);
      $cacheability->addCacheableDependency($url);
      $items[] = [
        'name' => $this->toPlainText((string) $text),
        'item' => $url->getGeneratedUrl(),
      ];
    }
    if (!empty($page['name'])) {
      $items[] = ['name' => $page['name'], 'item' => $page['url']];
    }
    if (count($items) < 2) {
      return [];
    }

    $list = [];
    foreach ($items as $index => $item) {
      $list[] = ['@type' => 'ListItem', 'position' => $index + 1] + $item;
    }
    return [
      '@type' => 'BreadcrumbList',
      '@id' => $page['url'] . '#breadcrumb',
      'itemListElement' => $list,
    ];
  }

  /**
   * Builds amenity features from taxonomy terms.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $terms
   *   The amenity terms.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The cacheability metadata.
   *
   * @return list<array<string, mixed>>
   *   A list of LocationFeatureSpecification nodes.
   */
  protected function buildAmenityFeatures(array $terms, CacheableMetadata $cacheability): array {
    $features = [];
    foreach ($terms as $term) {
      $access = $term->access('view', NULL, TRUE);
      $cacheability->addCacheableDependency($term)->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }
      $term = $this->entityRepository->getTranslationFromContext($term);
      $features[] = [
        '@type' => 'LocationFeatureSpecification',
        'name' => $term->label(),
        'value' => TRUE,
      ];
    }
    return $features;
  }

  /**
   * Gets the node of the current route if it is a node's canonical page.
   */
  protected function getCurrentNode(CacheableMetadata $cacheability): ?NodeInterface {
    if ($this->routeMatch->getRouteName() !== 'entity.node.canonical') {
      return NULL;
    }
    $node = $this->routeMatch->getParameter('node');
    if (!$node instanceof NodeInterface) {
      return NULL;
    }
    $node = $this->entityRepository->getTranslationFromContext($node);
    assert($node instanceof NodeInterface);
    $cacheability->addCacheableDependency($node);
    return $node;
  }

  /**
   * Gets the booking deeplink of a room or package, if any.
   *
   * Rooms and packages of the "ebr_accommodation" modules (seekda, DIRS21,
   * ...) provide a "book" action URL into the external booking engine.
   */
  protected function getBookingUrl(NodeInterface $node, CacheableMetadata $cacheability): ?string {
    if (!$node instanceof ActionableInterface) {
      return NULL;
    }
    // The action URL depends on other nodes (the booking page).
    $cacheability->addCacheTags(['node_list']);
    $url = $node->getActionUrl('book');
    if (!$url instanceof Url) {
      return NULL;
    }
    $generated = (clone $url)->setAbsolute()->toString(TRUE);
    $cacheability->addCacheableDependency($generated);
    return $generated->getGeneratedUrl();
  }

  /**
   * Gets a plain text description for a node.
   */
  protected function getNodeDescription(NodeInterface $node): ?string {
    foreach ([static::FIELD_SUBTITLE, static::FIELD_BODY] as $fieldName) {
      if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
        continue;
      }
      $values = $node->get($fieldName)->first()?->getValue() ?? [];
      $text = $this->toPlainText((string) (($values['summary'] ?? '') ?: ($values['value'] ?? '')));
      if ($text !== '') {
        return Unicode::truncate($text, static::DESCRIPTION_LENGTH, TRUE, TRUE);
      }
    }
    return NULL;
  }

  /**
   * Gets the absolute image URLs of a node's media images.
   *
   * @return list<string>
   *   The image URLs.
   */
  protected function getNodeImageUrls(NodeInterface $node, CacheableMetadata $cacheability): array {
    if (!$node->hasField(static::FIELD_IMAGES)) {
      return [];
    }
    $imageStyle = (string) $this->configFactory->get('schema_org.settings')->get('image_style');
    return $this->getMediaImageUrls($this->getReferencedEntities($node->get(static::FIELD_IMAGES)), $imageStyle, $cacheability);
  }

  /**
   * Gets the absolute URLs of image media entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $mediaItems
   *   The media entities. Non-image media is skipped.
   * @param string $imageStyleId
   *   An image style ID, or an empty string for the original image.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The cacheability metadata.
   *
   * @return list<string>
   *   The image URLs.
   */
  protected function getMediaImageUrls(array $mediaItems, string $imageStyleId, CacheableMetadata $cacheability): array {
    $imageStyle = NULL;
    if ($imageStyleId !== '') {
      $imageStyle = $this->entityTypeManager->getStorage('image_style')->load($imageStyleId);
      if ($imageStyle instanceof ImageStyleInterface) {
        $cacheability->addCacheableDependency($imageStyle);
      }
    }

    $urls = [];
    foreach ($mediaItems as $media) {
      if (!$media instanceof MediaInterface || $media->getSource()->getPluginId() !== 'image') {
        continue;
      }
      $access = $media->access('view', NULL, TRUE);
      $cacheability->addCacheableDependency($media)->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }
      $sourceField = $media->getSource()->getConfiguration()['source_field'] ?? NULL;
      $file = $sourceField && $media->hasField($sourceField) ? $media->get($sourceField)->entity : NULL;
      if (!$file instanceof FileInterface) {
        continue;
      }
      $cacheability->addCacheableDependency($file);
      $uri = (string) $file->getFileUri();
      $urls[] = $imageStyle instanceof ImageStyleInterface
        ? $imageStyle->buildUrl($uri)
        : $this->fileUrlGenerator->generateAbsoluteString($uri);
    }
    return $urls;
  }

  /**
   * Gets the absolute logo URL from the settings or the default theme.
   */
  protected function getLogoUrl(ImmutableConfig $config, CacheableMetadata $cacheability): ?string {
    $logo = (string) $config->get('logo');
    if ($logo === '') {
      $theme = (string) $this->configFactory->get('system.theme')->get('default');
      $cacheability->addCacheTags(['config:system.theme', 'config:system.theme.global', "config:$theme.settings"]);
      $logo = (string) $this->themeSettingsProvider->getSetting('logo.url', $theme);
    }
    if ($logo === '') {
      return NULL;
    }
    if (str_starts_with($logo, '/') && !str_starts_with($logo, '//')) {
      $request = $this->requestStack->getCurrentRequest();
      $logo = ($request ? $request->getSchemeAndHttpHost() : '') . $logo;
    }
    return $logo;
  }

  /**
   * Gets the absolute URL of the front page in the current language.
   */
  protected function getFrontUrl(CacheableMetadata $cacheability): string {
    $url = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(TRUE);
    $cacheability->addCacheableDependency($url);
    return $url->getGeneratedUrl();
  }

  /**
   * Gets the language-independent base URL used for "@id" values.
   */
  protected function getBaseUrl(): string {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return '/';
    }
    return $request->getSchemeAndHttpHost() . $request->getBasePath() . '/';
  }

  /**
   * Loads entities by UUID, skipping missing ones.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string[] $uuids
   *   The UUIDs.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The loaded entities.
   */
  protected function loadByUuids(string $entityTypeId, array $uuids): array {
    $entities = [];
    foreach ($uuids as $uuid) {
      $entity = $this->entityRepository->loadEntityByUuid($entityTypeId, $uuid);
      if ($entity) {
        $entities[] = $entity;
      }
    }
    return $entities;
  }

  /**
   * Gets the referenced entities of an entity reference field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *   The field items.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The referenced entities, or an empty array for other field types.
   */
  protected function getReferencedEntities(FieldItemListInterface $items): array {
    return $items instanceof EntityReferenceFieldItemListInterface ? $items->referencedEntities() : [];
  }

  /**
   * Gets the main property value of a single-value field, if not empty.
   */
  protected function getScalar(FieldableEntityInterface $entity, string $fieldName): mixed {
    if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
      return NULL;
    }
    $item = $entity->get($fieldName)->first();
    $property = $item?->mainPropertyName();
    return $property ? $item->get($property)->getValue() : NULL;
  }

  /**
   * Builds the target groups of an entity as schema.org audiences.
   *
   * @return list<array<string, mixed>>
   *   A list of Audience nodes.
   */
  protected function buildAudiences(FieldableEntityInterface $entity): array {
    $audiences = [];
    foreach ($this->getOptionLabels($entity, static::FIELD_AUDIENCE) as $value => $label) {
      $audiences[] = match ($value) {
        'adults_only' => [
          '@type' => 'PeopleAudience',
          'audienceType' => $label,
          'suggestedMinAge' => 18,
        ],
        default => [
          '@type' => 'Audience',
          'audienceType' => $label,
        ],
      };
    }
    return $audiences;
  }

  /**
   * Gets the human readable label of a list field's value.
   */
  protected function getOptionLabel(FieldableEntityInterface $entity, string $fieldName): ?string {
    $value = $this->getScalar($entity, $fieldName);
    if ($value === NULL) {
      return NULL;
    }
    $options = $this->getOptions($entity, $fieldName);
    return isset($options[$value]) ? $this->toPlainText((string) $options[$value]) : (string) $value;
  }

  /**
   * Gets the human readable labels of a multi-value list field.
   *
   * @return array<string, string>
   *   The labels, keyed by value, in the order entered by the editor.
   */
  protected function getOptionLabels(FieldableEntityInterface $entity, string $fieldName): array {
    if (!$entity->hasField($fieldName)) {
      return [];
    }
    $options = $this->getOptions($entity, $fieldName);
    $labels = [];
    foreach ($entity->get($fieldName) as $item) {
      $value = (string) ($item->getValue()['value'] ?? '');
      if ($value !== '') {
        $labels[$value] = isset($options[$value]) ? $this->toPlainText((string) $options[$value]) : $value;
      }
    }
    return $labels;
  }

  /**
   * Gets the allowed values of a list field, translated to the UI language.
   *
   * @return array<int|string, mixed>
   *   The option labels, keyed by value.
   */
  protected function getOptions(FieldableEntityInterface $entity, string $fieldName): array {
    return $entity->get($fieldName)->getFieldDefinition()
      ->getFieldStorageDefinition()
      ->getOptionsProvider('value', $entity)
      ?->getPossibleOptions() ?? [];
  }

  /**
   * Maps a "pets allowed" option to a schema.org value.
   */
  protected function mapPetsAllowed(string $value): bool|string|null {
    return match ($value) {
      'yes' => TRUE,
      'no' => FALSE,
      'on_request' => (string) $this->t('On request', [], ['context' => 'schema.org pets']),
      default => NULL,
    };
  }

  /**
   * Merges the per-node JSON overrides into a graph node.
   *
   * @param array<string, mixed> $item
   *   The graph node.
   * @param \Drupal\node\NodeInterface $node
   *   The node holding the overrides.
   *
   * @return array<string, mixed>
   *   The graph node with overrides applied. The "@id" cannot be overridden.
   */
  protected function applyJsonOverrides(array $item, NodeInterface $node): array {
    $overrides = $this->decodeJsonObject((string) $this->getScalar($node, static::FIELD_JSON));
    unset($overrides['@id']);
    return array_replace($item, $overrides);
  }

  /**
   * Decodes a JSON object string, returning an empty array on any error.
   *
   * @return array<string, mixed>
   *   The decoded object.
   */
  protected function decodeJsonObject(string $json): array {
    if (trim($json) === '') {
      return [];
    }
    try {
      $decoded = json_decode($json, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }
    return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
  }

  /**
   * Converts HTML to a single line of plain text.
   */
  protected function toPlainText(string $html): string {
    // Separate the text of adjacent elements before removing the tags.
    $text = Html::decodeEntities(strip_tags(str_replace('<', ' <', $html)));
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

  /**
   * Recursively removes NULL, empty strings and empty arrays.
   *
   * Boolean FALSE and 0 are kept, they are meaningful values.
   */
  protected function removeEmptyValues(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    foreach ($value as $key => $item) {
      $item = $this->removeEmptyValues($item);
      if ($item === NULL || $item === '' || $item === []) {
        unset($value[$key]);
      }
      else {
        $value[$key] = $item;
      }
    }
    // Keep lists as JSON arrays after removing items.
    return $this->reindexIfList($value);
  }

  /**
   * Re-indexes arrays with only integer keys so they encode as JSON arrays.
   *
   * @param array<mixed> $value
   *   The array.
   *
   * @return array<mixed>
   *   The array, re-indexed if all keys are integers.
   */
  protected function reindexIfList(array $value): array {
    foreach (array_keys($value) as $key) {
      if (!is_int($key)) {
        return $value;
      }
    }
    return array_values($value);
  }

}
