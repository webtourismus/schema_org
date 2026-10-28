<?php

declare(strict_types=1);

namespace Drupal\schema_org\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Locale\CountryManagerInterface;

/**
 * Configures the hotel profile of the schema.org graph.
 *
 * Dependencies are autowired.
 *
 * @see \Drupal\Core\DependencyInjection\AutowireTrait
 */
class SchemaOrgSettingsForm extends ConfigFormBase {

  protected const CONFIG_NAME = 'schema_org.settings';

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityRepositoryInterface $entityRepository,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected CountryManagerInterface $countryManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'schema_org_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return string[]
   *   The config names.
   */
  protected function getEditableConfigNames(): array {
    return [static::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(static::CONFIG_NAME);
    $target = static::CONFIG_NAME . ':';

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Output the schema.org graph on frontend pages'),
      '#config_target' => $target . 'enabled',
    ];

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General'),
      '#open' => TRUE,
    ];
    $form['general']['business_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type of business'),
      '#options' => [
        'Hotel' => $this->t('Hotel'),
        'Resort' => $this->t('Resort'),
        'BedAndBreakfast' => $this->t('Bed and breakfast / guesthouse'),
        'Hostel' => $this->t('Hostel'),
        'Motel' => $this->t('Motel'),
        'Campground' => $this->t('Campground'),
        'VacationRental' => $this->t('Vacation rental / apartments'),
        'LodgingBusiness' => $this->t('Other lodging business'),
      ],
      '#config_target' => $target . 'business_type',
    ];
    $form['general']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#description' => $this->t('Leave empty to use the site name.'),
      '#config_target' => $target . 'name',
    ];
    $form['general']['alternate_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alternate name'),
      '#description' => $this->t('For example a short name or a former name of the hotel.'),
      '#config_target' => $target . 'alternate_name',
    ];
    $form['general']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#description' => $this->t('Two or three factual sentences about the hotel: location, style, target group and highlights. Translatable.'),
      '#rows' => 3,
      '#config_target' => $target . 'description',
    ];
    $form['general']['logo'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Logo'),
      '#description' => $this->t('A path like /themes/custom/frontend/logo.svg or an absolute URL. Leave empty to use the logo of the default theme. Prefer a PNG or JPG logo of at least 112×112 pixels.'),
      '#config_target' => $target . 'logo',
    ];
    $form['general']['images'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Images'),
      '#description' => $this->t('Representative photos of the hotel, separated by commas. The first image is the most important one.'),
      '#target_type' => 'media',
      '#selection_settings' => $this->getMediaSelectionSettings(),
      '#tags' => TRUE,
      '#maxlength' => 2048,
      '#default_value' => $this->loadByUuids('media', $config->get('image_uuids') ?? []),
    ];
    $form['general']['image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Image style'),
      '#description' => $this->t('Used for all images in the graph. Google recommends at least 1200 pixels width in 16:9, 4:3 or 1:1.'),
      '#options' => $this->getImageStyleOptions(),
      '#empty_option' => $this->t('- Original image -'),
      '#empty_value' => '',
      '#config_target' => $target . 'image_style',
    ];

    $form['contact'] = [
      '#type' => 'details',
      '#title' => $this->t('Contact and location'),
      '#open' => TRUE,
    ];
    $form['contact']['telephone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Telephone'),
      '#description' => $this->t('In international format, for example +43 512 123456.'),
      '#config_target' => $target . 'telephone',
    ];
    $form['contact']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#config_target' => $target . 'email',
    ];
    $form['contact']['street_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Street address'),
      '#config_target' => $target . 'address.street_address',
    ];
    $form['contact']['postal_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Postal code'),
      '#size' => 10,
      '#config_target' => $target . 'address.postal_code',
    ];
    $form['contact']['locality'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Locality'),
      '#config_target' => $target . 'address.locality',
    ];
    $form['contact']['region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Region'),
      '#description' => $this->t('For example the federal state, like Tirol.'),
      '#config_target' => $target . 'address.region',
    ];
    $form['contact']['country_code'] = [
      '#type' => 'select',
      '#title' => $this->t('Country'),
      '#options' => $this->countryManager->getList(),
      '#empty_value' => '',
      '#config_target' => $target . 'address.country_code',
    ];
    $form['contact']['latitude'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Latitude'),
      '#size' => 16,
      '#placeholder' => '47.2692',
      '#config_target' => $target . 'latitude',
    ];
    $form['contact']['longitude'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Longitude'),
      '#size' => 16,
      '#placeholder' => '11.4041',
      '#config_target' => $target . 'longitude',
    ];

    $form['stay'] = [
      '#type' => 'details',
      '#title' => $this->t('Classification and stay'),
      '#open' => TRUE,
    ];
    $form['stay']['star_rating'] = [
      '#type' => 'select',
      '#title' => $this->t('Official star rating'),
      '#options' => array_combine(['1', '2', '3', '4', '5'], ['1', '2', '3', '4', '5']),
      '#empty_option' => $this->t('- None -'),
      '#empty_value' => '',
      '#config_target' => $target . 'star_rating',
    ];
    $form['stay']['star_rating_superior'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Superior'),
      '#config_target' => $target . 'star_rating_superior',
    ];
    $form['stay']['checkin_time'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Check-in from'),
      '#size' => 6,
      '#placeholder' => '15:00',
      '#config_target' => $target . 'checkin_time',
    ];
    $form['stay']['checkout_time'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Check-out until'),
      '#size' => 6,
      '#placeholder' => '10:00',
      '#config_target' => $target . 'checkout_time',
    ];
    $form['stay']['number_of_rooms'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of rooms'),
      '#description' => $this->t('Use 0 to omit.'),
      '#min' => 0,
      '#config_target' => $target . 'number_of_rooms',
    ];
    $form['stay']['pets_allowed'] = [
      '#type' => 'select',
      '#title' => $this->t('Pets allowed'),
      '#options' => [
        'yes' => $this->t('Yes'),
        'no' => $this->t('No'),
        'on_request' => $this->t('On request'),
      ],
      '#empty_option' => $this->t('- Not specified -'),
      '#empty_value' => '',
      '#description' => $this->t('Default for all rooms. Can be overridden per room.'),
      '#config_target' => $target . 'pets_allowed',
    ];
    $form['stay']['price_range'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Price range'),
      '#description' => $this->t('For example "€€€" or "€120–350 per night". Translatable.'),
      '#config_target' => $target . 'price_range',
    ];
    $form['stay']['currency'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currency'),
      '#description' => $this->t('ISO 4217 code of the prices of rooms and packages.'),
      '#size' => 4,
      '#maxlength' => 3,
      '#required' => TRUE,
      '#config_target' => $target . 'currency',
    ];
    $form['stay']['payment_accepted'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Payment accepted'),
      '#description' => $this->t('For example "Cash, Credit Card, Debit Card, Bank transfer". Translatable.'),
      '#config_target' => $target . 'payment_accepted',
    ];
    $form['stay']['available_languages'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Languages spoken'),
      '#description' => $this->t('Language codes spoken by the staff, one per line, for example "de", "en", "it".'),
      '#rows' => 3,
      '#config_target' => new ConfigTarget(
        static::CONFIG_NAME,
        'available_languages',
        [static::class, 'linesFromConfig'],
        [static::class, 'linesToConfig'],
      ),
    ];
    $form['stay']['amenities'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Amenities'),
      '#description' => $this->t('Amenities of the whole hotel (e.g. spa, restaurant, parking), separated by commas. Room amenities are taken from each room.'),
      '#target_type' => 'taxonomy_term',
      '#selection_settings' => $this->getAmenitySelectionSettings(),
      '#tags' => TRUE,
      '#maxlength' => 4096,
      '#default_value' => $this->loadByUuids('taxonomy_term', $config->get('amenity_uuids') ?? []),
    ];

    $form['links'] = [
      '#type' => 'details',
      '#title' => $this->t('Links'),
      '#open' => TRUE,
    ];
    $form['links']['same_as'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Profiles on other websites'),
      '#description' => $this->t('One URL per line: Google Business Profile, Facebook, Instagram, TripAdvisor, Holidaycheck, Wikipedia, tourism board, … These links help search engines and AI assistants to identify the hotel.'),
      '#rows' => 5,
      '#config_target' => new ConfigTarget(
        static::CONFIG_NAME,
        'same_as',
        [static::class, 'linesFromConfig'],
        [static::class, 'linesToConfig'],
      ),
    ];
    $form['links']['booking_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Booking URL'),
      '#description' => $this->t('Entry page of the booking engine, published as reservation action of the hotel. Rooms and packages use their own booking deeplinks.'),
      '#config_target' => $target . 'booking_url',
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
    ];
    $nodeTypes = array_map(
      static fn (array $info): string => (string) $info['label'],
      $this->bundleInfo->getBundleInfo('node'),
    );
    $form['advanced']['room_bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types describing rooms'),
      '#description' => $this->t('Output as HotelRoom with an Offer.'),
      '#options' => $nodeTypes,
      '#config_target' => new ConfigTarget(
        static::CONFIG_NAME,
        'room_bundles',
        toConfig: [static::class, 'checkboxesToConfig'],
      ),
    ];
    $form['advanced']['offer_bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types describing offers'),
      '#description' => $this->t('Output as Offer, for example packages.'),
      '#options' => $nodeTypes,
      '#config_target' => new ConfigTarget(
        static::CONFIG_NAME,
        'offer_bundles',
        toConfig: [static::class, 'checkboxesToConfig'],
      ),
    ];
    $form['advanced']['extra_properties'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional properties of the hotel (JSON)'),
      '#description' => $this->t('A JSON object with schema.org properties that are added to the hotel and override generated values, for example <code>{"award": "Falstaff 2027", "openingHoursSpecification": {…}}</code>. Translatable.'),
      '#rows' => 6,
      '#config_target' => $target . 'extra_properties',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    // Entity references are stored as UUIDs, so the configuration can be
    // deployed between environments.
    $this->config(static::CONFIG_NAME)
      ->set('image_uuids', $this->idsToUuids('media', $form_state->getValue('images')))
      ->set('amenity_uuids', $this->idsToUuids('taxonomy_term', $form_state->getValue('amenities')))
      ->save();
  }

  /**
   * Converts a config sequence to textarea lines.
   *
   * @param string[]|null $value
   *   The config value.
   */
  public static function linesFromConfig(?array $value): string {
    return implode("\n", $value ?? []);
  }

  /**
   * Converts textarea lines to a config sequence.
   *
   * @return string[]
   *   The non-empty, trimmed lines.
   */
  public static function linesToConfig(string $value): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])));
  }

  /**
   * Converts checkboxes values to a config sequence.
   *
   * @param array<string, string|int> $value
   *   The checkboxes value.
   *
   * @return string[]
   *   The checked keys.
   */
  public static function checkboxesToConfig(array $value): array {
    return array_values(array_map('strval', array_keys(array_filter($value))));
  }

  /**
   * Converts entity autocomplete values to UUIDs.
   *
   * @return string[]
   *   The UUIDs.
   */
  protected function idsToUuids(string $entityTypeId, mixed $value): array {
    $ids = array_filter(array_column(is_array($value) ? $value : [], 'target_id'));
    if ($ids === []) {
      return [];
    }
    $entities = $this->entityTypeManager->getStorage($entityTypeId)->loadMultiple($ids);
    $uuids = [];
    // Keep the order entered by the editor.
    foreach ($ids as $id) {
      if (isset($entities[$id])) {
        $uuids[] = (string) $entities[$id]->uuid();
      }
    }
    return array_values(array_unique($uuids));
  }

  /**
   * Loads entities by UUID for default values.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string[] $uuids
   *   The UUIDs.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The entities.
   */
  protected function loadByUuids(string $entityTypeId, array $uuids): array {
    return array_values(array_filter(array_map(
      fn (string $uuid) => $this->entityRepository->loadEntityByUuid($entityTypeId, $uuid),
      $uuids,
    )));
  }

  /**
   * Gets the image style options.
   *
   * @return array<string, string>
   *   Image style labels, keyed by ID.
   */
  protected function getImageStyleOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('image_style')->loadMultiple() as $id => $style) {
      $options[$id] = (string) $style->label();
    }
    asort($options);
    return $options;
  }

  /**
   * Restricts the media autocomplete to image media types.
   *
   * @return array<string, mixed>
   *   Selection settings.
   */
  protected function getMediaSelectionSettings(): array {
    $bundles = [];
    foreach ($this->entityTypeManager->getStorage('media_type')->loadMultiple() as $id => $type) {
      /** @var \Drupal\media\MediaTypeInterface $type */
      if ($type->getSource()->getPluginId() === 'image') {
        $bundles[$id] = $id;
      }
    }
    return $bundles ? ['target_bundles' => $bundles] : [];
  }

  /**
   * Restricts the term autocomplete to the "amenity" vocabulary, if present.
   *
   * @return array<string, mixed>
   *   Selection settings.
   */
  protected function getAmenitySelectionSettings(): array {
    $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load('amenity');
    return $vocabulary ? ['target_bundles' => ['amenity' => 'amenity']] : [];
  }

}
