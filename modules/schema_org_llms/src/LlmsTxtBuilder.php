<?php

declare(strict_types=1);

namespace Drupal\schema_org_llms;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\schema_org\SchemaGraphBuilder;

/**
 * Builds /llms.txt from the data of the schema.org graph.
 *
 * The file follows https://llmstxt.org: a H1 with the name, a blockquote
 * summary, free text and H2 sections with link lists. All facts come from
 * the same data as the JSON-LD, so both always say the same.
 */
class LlmsTxtBuilder {

  use StringTranslationTrait;

  /**
   * Field used to sort rooms and packages, if present.
   */
  protected const FIELD_WEIGHT = 'field_weight';

  public function __construct(
    protected SchemaGraphBuilder $graphBuilder,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Builds the llms.txt content in the current language.
   *
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of everything the content depends on.
   *
   * @return string
   *   The Markdown content.
   */
  public function build(CacheableMetadata $cacheability): string {
    $settings = $this->configFactory->get('schema_org.settings');
    $llmsSettings = $this->configFactory->get('schema_org_llms.settings');
    $cacheability
      ->addCacheableDependency($settings)
      ->addCacheableDependency($llmsSettings)
      ->addCacheContexts(['url.site', 'languages:language_interface', 'languages:language_content']);

    $hotel = $this->graphBuilder->buildHotelNode($cacheability);
    $lines = ['# ' . $this->oneLine((string) ($hotel['name'] ?? ''))];
    if (!empty($hotel['description'])) {
      $lines[] = '';
      $lines[] = '> ' . $this->oneLine((string) $hotel['description']);
    }
    $intro = trim((string) $llmsSettings->get('intro'));
    if ($intro !== '') {
      $lines[] = '';
      $lines[] = $intro;
    }

    $this->addSection($lines, (string) $this->t('Hotel'), $this->buildHotelFacts($hotel));

    $roomItems = [];
    foreach ($this->loadNodes($settings->get('room_bundles') ?? [], NULL, $cacheability) as $node) {
      $roomItems[] = $this->buildRoomItem($node, $cacheability);
    }
    $this->addSection($lines, (string) $this->t('Rooms'), $roomItems);

    $packageItems = [];
    foreach ($this->loadNodes($settings->get('offer_bundles') ?? [], NULL, $cacheability) as $node) {
      $packageItems[] = $this->buildPackageItem($node, $cacheability);
    }
    $this->addSection($lines, (string) $this->t('Packages'), $packageItems);

    $pageBundles = $this->getPageTypeBundles();
    $areaItems = [];
    foreach ($this->loadNodes($pageBundles, array_keys(SchemaGraphBuilder::PLACE_TYPES), $cacheability) as $node) {
      $areaItems[] = $this->buildAreaItem($node, $cacheability);
    }
    $this->addSection($lines, (string) $this->t('Hotel areas'), $areaItems);

    $pageItems = [];
    foreach ($this->loadNodes($pageBundles, array_keys(SchemaGraphBuilder::PAGE_TYPES), $cacheability) as $node) {
      $pageItems[] = $this->link($node->label(), $this->nodeUrl($node, $cacheability));
    }
    $this->addSection($lines, (string) $this->t('Further information'), $pageItems);

    $this->addSection($lines, (string) $this->t('Other languages'), $this->buildLanguageItems($cacheability));

    return implode("\n", $lines) . "\n";
  }

  /**
   * Builds the list of hotel facts.
   *
   * @param array<string, mixed> $hotel
   *   The schema.org hotel.
   *
   * @return string[]
   *   List items.
   */
  protected function buildHotelFacts(array $hotel): array {
    $facts = [];
    if (isset($hotel['starRating']['ratingValue'])) {
      $superior = isset($hotel['starRating']['alternateName']) ? ' ' . $this->t('superior') : '';
      $facts[] = $this->t('Classification: @stars stars@superior', [
        '@stars' => $hotel['starRating']['ratingValue'],
        '@superior' => $superior,
      ]);
    }
    // A country alone is no useful address.
    if (!empty($hotel['address']['streetAddress']) || !empty($hotel['address']['addressLocality'])) {
      $address = $hotel['address'];
      $locality = trim(($address['postalCode'] ?? '') . ' ' . ($address['addressLocality'] ?? ''));
      $parts = array_filter([
        $address['streetAddress'] ?? '',
        $locality,
        $address['addressRegion'] ?? '',
        $address['addressCountry'] ?? '',
      ]);
      $facts[] = $this->t('Address: @address', ['@address' => implode(', ', $parts)]);
    }
    if (!empty($hotel['telephone'])) {
      $facts[] = $this->t('Phone: @phone', ['@phone' => $hotel['telephone']]);
    }
    if (!empty($hotel['email'])) {
      $facts[] = $this->t('Email: @email', ['@email' => $hotel['email']]);
    }
    if (!empty($hotel['checkinTime']) || !empty($hotel['checkoutTime'])) {
      $facts[] = $this->t('Check-in from @in, check-out until @out', [
        '@in' => $hotel['checkinTime'] ?? '-',
        '@out' => $hotel['checkoutTime'] ?? '-',
      ]);
    }
    if (!empty($hotel['numberOfRooms'])) {
      $facts[] = $this->t('Number of rooms: @count', ['@count' => $hotel['numberOfRooms']]);
    }
    if (isset($hotel['petsAllowed'])) {
      $facts[] = $this->t('Pets allowed: @pets', ['@pets' => $this->yesNo($hotel['petsAllowed'])]);
    }
    if (!empty($hotel['priceRange'])) {
      $facts[] = $this->t('Price range: @range', ['@range' => $hotel['priceRange']]);
    }
    if (!empty($hotel['paymentAccepted'])) {
      $facts[] = $this->t('Payment accepted: @payment', ['@payment' => $hotel['paymentAccepted']]);
    }
    if (!empty($hotel['availableLanguage'])) {
      $facts[] = $this->t('Languages spoken: @languages', ['@languages' => implode(', ', $this->languageNames((array) $hotel['availableLanguage']))]);
    }
    if (!empty($hotel['amenityFeature'])) {
      $facts[] = $this->t('Amenities: @amenities', ['@amenities' => implode(', ', array_column((array) $hotel['amenityFeature'], 'name'))]);
    }
    if (!empty($hotel['url'])) {
      $facts[] = $this->link((string) $this->t('Website'), (string) $hotel['url']);
    }
    if (!empty($hotel['potentialAction']['target']['urlTemplate'])) {
      $facts[] = $this->link((string) $this->t('Booking'), (string) $hotel['potentialAction']['target']['urlTemplate']);
    }
    if (!empty($hotel['hasMap'])) {
      $facts[] = $this->link((string) $this->t('Map'), (string) $hotel['hasMap']);
    }
    foreach ((array) ($hotel['sameAs'] ?? []) as $profile) {
      $facts[] = $this->link((string) parse_url((string) $profile, PHP_URL_HOST), (string) $profile);
    }
    return array_map('strval', $facts);
  }

  /**
   * Builds the list item of a room.
   */
  protected function buildRoomItem(NodeInterface $node, CacheableMetadata $cacheability): string {
    $entities = $this->graphBuilder->buildNodeEntities($node, $cacheability);
    $room = $entities['room'] ?? [];
    $details = [];
    if (!empty($room['description'])) {
      $details[] = $this->oneLine((string) $room['description']);
    }
    if (isset($room['occupancy'])) {
      $occupancy = $room['occupancy'];
      $details[] = isset($occupancy['maxValue']) && ($occupancy['maxValue'] ?? NULL) !== ($occupancy['value'] ?? NULL)
        ? $this->t('@count persons, max. @max', [
          '@count' => $occupancy['value'] ?? $occupancy['maxValue'],
          '@max' => $occupancy['maxValue'],
        ])
        : $this->t('@count persons', ['@count' => $occupancy['value'] ?? $occupancy['maxValue']]);
    }
    if (isset($room['floorSize']['value'])) {
      $details[] = $this->t('@size m²', ['@size' => $room['floorSize']['value']]);
    }
    if (isset($room['bed']['typeOfBed'])) {
      $details[] = isset($room['bed']['numberOfBeds'])
        ? $this->t('@count × @bed', ['@count' => $room['bed']['numberOfBeds'], '@bed' => $room['bed']['typeOfBed']])
        : (string) $room['bed']['typeOfBed'];
    }
    if (!empty($room['amenityFeature'])) {
      $details[] = implode(', ', array_column((array) $room['amenityFeature'], 'name'));
    }
    array_push($details, ...$this->offerDetails($entities['offer'] ?? [], $node, $cacheability));
    return $this->item($node->label(), $this->nodeUrl($node, $cacheability), $details);
  }

  /**
   * Builds the list item of a package.
   */
  protected function buildPackageItem(NodeInterface $node, CacheableMetadata $cacheability): string {
    $offer = $this->graphBuilder->buildNodeEntities($node, $cacheability)['offer'] ?? [];
    $details = [];
    if (!empty($offer['description'])) {
      $details[] = $this->oneLine((string) $offer['description']);
    }
    array_push($details, ...$this->offerDetails($offer, $node, $cacheability));
    return $this->item($node->label(), $this->nodeUrl($node, $cacheability), $details);
  }

  /**
   * Builds the list item of a hotel area, e.g. the spa.
   */
  protected function buildAreaItem(NodeInterface $node, CacheableMetadata $cacheability): string {
    $place = $this->graphBuilder->buildNodeEntities($node, $cacheability)['place'] ?? [];
    $details = [];
    if (!empty($place['description'])) {
      $details[] = $this->oneLine((string) $place['description']);
    }
    if (!empty($place['openingHours'])) {
      $details[] = $this->t('Opening hours: @hours', ['@hours' => implode(', ', (array) $place['openingHours'])]);
    }
    return $this->item($node->label(), $this->nodeUrl($node, $cacheability), $details);
  }

  /**
   * Builds the details of an offer: price, validity, inclusions, ...
   *
   * @param array<string, mixed> $offer
   *   The schema.org offer.
   * @param \Drupal\node\NodeInterface $node
   *   The room or package.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The cacheability metadata.
   *
   * @return string[]
   *   The details.
   */
  protected function offerDetails(array $offer, NodeInterface $node, CacheableMetadata $cacheability): array {
    $details = [];
    if (isset($offer['price'])) {
      $unit = $offer['priceSpecification']['unitText'] ?? '';
      $details[] = trim((string) $this->t('from @price @currency @unit', [
        '@price' => $offer['price'],
        '@currency' => $offer['priceCurrency'] ?? '',
        '@unit' => $unit,
      ]));
    }
    if (isset($offer['validFrom']) || isset($offer['validThrough'])) {
      $details[] = $this->t('valid @from – @through', [
        '@from' => $offer['validFrom'] ?? '',
        '@through' => $offer['validThrough'] ?? '',
      ]);
    }
    if (isset($offer['eligibleDuration']['minValue'])) {
      $details[] = $this->t('minimum stay @count nights', ['@count' => $offer['eligibleDuration']['minValue']]);
    }
    $inclusions = array_filter(array_map(
      static fn (array $item): string => (string) ($item['typeOfGood']['name'] ?? ''),
      array_filter((array) ($offer['includesObject'] ?? []), 'is_array'),
    ));
    if ($inclusions) {
      $details[] = $this->t('includes: @items', ['@items' => implode(', ', $inclusions)]);
    }
    $audiences = array_column((array) ($offer['itemOffered']['audience'] ?? []), 'audienceType');
    if ($audiences) {
      $details[] = $this->t('for: @audiences', ['@audiences' => implode(', ', $audiences)]);
    }
    if (!empty($offer['category'])) {
      $details[] = $this->t('category: @categories', ['@categories' => implode(', ', (array) $offer['category'])]);
    }
    if (!empty($offer['url']) && $offer['url'] !== $this->nodeUrl($node, $cacheability)) {
      $details[] = $this->link((string) $this->t('Book'), (string) $offer['url']);
    }
    return array_map('strval', $details);
  }

  /**
   * Builds links to llms.txt in the other languages.
   *
   * @return string[]
   *   List items.
   */
  protected function buildLanguageItems(CacheableMetadata $cacheability): array {
    $current = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $items = [];
    foreach ($this->languageManager->getLanguages() as $language) {
      if ($language->getId() === $current) {
        continue;
      }
      $url = Url::fromRoute('schema_org_llms.llms_txt', [], ['absolute' => TRUE, 'language' => $language])->toString(TRUE);
      $cacheability->addCacheableDependency($url);
      $items[] = $this->link($language->getName(), $url->getGeneratedUrl());
    }
    return $items;
  }

  /**
   * Loads published nodes in the current content language.
   *
   * @param string[] $bundles
   *   The node bundles.
   * @param string[]|null $pageTypes
   *   Only nodes with one of these page types, or NULL for all.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The cacheability metadata.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The nodes, sorted by weight and title. Nodes without a translation in
   *   the current language are skipped.
   */
  protected function loadNodes(array $bundles, ?array $pageTypes, CacheableMetadata $cacheability): array {
    if ($bundles === []) {
      return [];
    }
    $nodeType = $this->entityTypeManager->getDefinition('node');
    foreach ($bundles as $bundle) {
      $cacheability->addCacheTags($nodeType->getBundleListCacheTags($bundle));
    }

    $storage = $this->entityTypeManager->getStorage('node');
    // Only published nodes are listed, which are public anyway.
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('type', $bundles, 'IN');
    if ($pageTypes !== NULL) {
      $query->condition(SchemaGraphBuilder::FIELD_PAGE_TYPE, $pageTypes, 'IN');
    }

    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $nodes = [];
    foreach ($storage->loadMultiple($query->execute()) as $node) {
      assert($node instanceof NodeInterface);
      if ($node->hasTranslation($langcode)) {
        $node = $node->getTranslation($langcode);
      }
      elseif ($node->isTranslatable()) {
        continue;
      }
      if ($node->isPublished()) {
        $nodes[] = $node;
      }
    }

    usort($nodes, fn (NodeInterface $a, NodeInterface $b): int => [
      $this->weight($a),
      $a->label(),
    ] <=> [
      $this->weight($b),
      $b->label(),
    ]);
    return $nodes;
  }

  /**
   * Gets the node bundles having the page type field.
   *
   * @return string[]
   *   The bundles.
   */
  protected function getPageTypeBundles(): array {
    return array_values($this->entityFieldManager->getFieldMap()['node'][SchemaGraphBuilder::FIELD_PAGE_TYPE]['bundles'] ?? []);
  }

  /**
   * Gets the weight of a node for sorting.
   */
  protected function weight(NodeInterface $node): int {
    if (!$node->hasField(static::FIELD_WEIGHT) || $node->get(static::FIELD_WEIGHT)->isEmpty()) {
      return 0;
    }
    return (int) ($node->get(static::FIELD_WEIGHT)->first()?->getValue()['value'] ?? 0);
  }

  /**
   * Gets the absolute URL of a node.
   */
  protected function nodeUrl(NodeInterface $node, CacheableMetadata $cacheability): string {
    $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE);
    $cacheability->addCacheableDependency($url);
    return $url->getGeneratedUrl();
  }

  /**
   * Gets the native names of language codes.
   *
   * @param string[] $langcodes
   *   Language codes, e.g. "de".
   *
   * @return string[]
   *   Native names, e.g. "Deutsch", or the code if unknown.
   */
  protected function languageNames(array $langcodes): array {
    $standard = LanguageManager::getStandardLanguageList();
    return array_map(static fn (string $code): string => $standard[$code][1] ?? $code, $langcodes);
  }

  /**
   * Translates a schema.org boolean or text value.
   */
  protected function yesNo(mixed $value): string {
    return match (TRUE) {
      $value === TRUE => (string) $this->t('yes'),
      $value === FALSE => (string) $this->t('no'),
      default => (string) $value,
    };
  }

  /**
   * Adds a H2 section with list items, if there are any.
   *
   * @param string[] $lines
   *   The lines of the file.
   * @param string $title
   *   The section title.
   * @param string[] $items
   *   The list items, without the leading dash.
   */
  protected function addSection(array &$lines, string $title, array $items): void {
    $items = array_filter($items, static fn (string $item): bool => $item !== '');
    if ($items === []) {
      return;
    }
    $lines[] = '';
    $lines[] = '## ' . $title;
    $lines[] = '';
    foreach ($items as $item) {
      $lines[] = '- ' . $item;
    }
  }

  /**
   * Builds a list item "[title](url): detail; detail".
   *
   * @param string|null $title
   *   The link text.
   * @param string $url
   *   The URL.
   * @param string[] $details
   *   The details.
   */
  protected function item(?string $title, string $url, array $details): string {
    // Details are separated by semicolons, so drop their final period.
    $details = array_filter(array_map(fn ($detail): string => rtrim($this->oneLine((string) $detail), '.;'), $details));
    return $this->link((string) $title, $url) . ($details ? ': ' . implode('; ', $details) : '');
  }

  /**
   * Builds a Markdown link.
   */
  protected function link(string $title, string $url): string {
    $text = str_replace(['[', ']'], ['\\[', '\\]'], $this->oneLine($title));
    return '[' . $text . '](' . str_replace([' ', ')'], ['%20', '%29'], $url) . ')';
  }

  /**
   * Converts a text to a single line.
   */
  protected function oneLine(string $text): string {
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

}
