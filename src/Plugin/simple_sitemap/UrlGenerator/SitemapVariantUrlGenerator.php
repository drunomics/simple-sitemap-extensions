<?php

namespace Drupal\simple_sitemap_extensions\Plugin\simple_sitemap\UrlGenerator;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Url;
use Drupal\simple_sitemap\Logger;
use Drupal\simple_sitemap\Plugin\simple_sitemap\UrlGenerator\UrlGeneratorBase;
use Drupal\simple_sitemap\Simplesitemap;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\simple_sitemap\SimplesitemapManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Generates urls for sitemap variants.
 *
 * @UrlGenerator(
 *   id = "sitemap_variant",
 *   label = @Translation("Sitemap variant URL generator"),
 *   description = @Translation("Generates URLs for sitemap variants."),
 * )
 */
class SitemapVariantUrlGenerator extends UrlGeneratorBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Sitemap manager.
   *
   * @var \Drupal\simple_sitemap\SimplesitemapManager
   */
  protected $sitemapManager;

  /**
   * Language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * SitemapVariantUrlGenerator constructor.
   *
   * @param array $configuration
   *   Configuration.
   * @param string $plugin_id
   *   Plugin id.
   * @param string $plugin_definition
   *   Plugin definition.
   * @param \Drupal\simple_sitemap\Simplesitemap $generator
   *   Sitemap generator.
   * @param \Drupal\simple_sitemap\Logger $logger
   *   Logger.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   Language manager.
   * @param \Drupal\simple_sitemap\SimplesitemapManager $sitemap_manager
   *   Sitemap manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory.
   * @param \Drupal\Core\Database\Connection $database
   *   The database.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    Simplesitemap $generator,
    Logger $logger,
    LanguageManagerInterface $language_manager,
    SimplesitemapManager $sitemap_manager,
    TimeInterface $time,
    ConfigFactoryInterface $config_factory,
    Connection $database
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $generator,
      $logger
    );

    $this->languageManager = $language_manager;
    $this->sitemapManager = $sitemap_manager;
    $this->time = $time;
    $this->configFactory = $config_factory;
    $this->database = $database;
  }

  /**
   * The static create function.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param array $configuration
   *   The configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   *
   * @return \Drupal\simple_sitemap\Plugin\simple_sitemap\UrlGenerator\UrlGeneratorBase|\Drupal\simple_sitemap_extensions\Plugin\simple_sitemap\UrlGenerator\SitemapVariantUrlGenerator|static
   *   Instance of this class.
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('simple_sitemap.generator'),
      $container->get('simple_sitemap.logger'),
      $container->get('language_manager'),
      $container->get('simple_sitemap.manager'),
      $container->get('datetime.time'),
      $container->get('config.factory'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDataSets() {
    $data_sets = [];
    $sitemap_variants = $this->sitemapManager->getSitemapVariants();
    unset($sitemap_variants['sitemap_index']);

    $config = $this->configFactory->get('simple_sitemap_extensions.sitemap_index.settings');
    $enabled_variants = $config->get('variants');

    foreach ($sitemap_variants as $variant_key => $variant_definition) {
      if (!in_array($variant_key, $enabled_variants)) {
        continue;
      }
      $data_sets[] = ['variant' => $variant_key];
    }
    return $data_sets;
  }

  /**
   * Gets the custom base url.
   *
   * @return string
   *   The URL.
   */
  protected function getCustomBaseUrl() {
    $customBaseUrl = $this->settings['base_url'];
    return !empty($customBaseUrl) ? $customBaseUrl : $GLOBALS['base_url'];
  }

  /**
   * Get the number of pages for a given variant.
   *
   * @param string $sitemapVariant
   *   The sitemap variant.
   *
   * @return int
   *   The number of pages.
   */
  private function getNumberOfVariantPages($sitemapVariant) {
    $sitemaps = $this->database->select('simple_sitemap', 's')
      ->fields('s', ['delta', 'sitemap_created', 'type'])
      ->condition('s.type', $sitemapVariant)
      ->condition('s.status', 1)
      ->execute()
      ->fetchAll();

    return is_array($sitemaps) && NULL !== $sitemaps ? count($sitemaps) : 1;
  }

  /**
   * {@inheritdoc}
   */
  public function generate($data_set) {
    $path_data = $this->processDataSet($data_set);

    return FALSE !== $path_data ? $path_data : [];
  }

  /**
   * {@inheritdoc}
   */
  protected function processDataSet($data_set) {
    $settings = [
      'absolute' => TRUE,
      'base_url' => $this->getCustomBaseUrl(),
      'language' => $this->languageManager->getLanguage(LanguageInterface::LANGCODE_NOT_APPLICABLE),
    ];

    $pages = $this->getNumberOfVariantPages($data_set['variant']);
    if ($pages > 1) {
      $urls = [];

      // The last page is a listing with pagination only.
      if ($pages > 1) {
        $pages -= 1;
      }
      for ($i = 1; $i <= $pages; $i++) {
        $url = Url::fromRoute('simple_sitemap_extensions.sitemap_variant_page', [
          'variant' => $data_set['variant'],
          'page' => $i,
        ], $settings);

        $url = [
          'url' => $url,
          'lastmod' => date('c', $this->time->getRequestTime()),
        ];
        $urls[] = $url;
      }

      return $urls;
    }
    else {
      $url = Url::fromRoute('simple_sitemap.sitemap_variant', ['variant' => $data_set['variant']], $settings);
      return [
        [
          'url' => $url,
          'lastmod' => date('c', $this->time->getRequestTime()),
        ],
      ];
    }

  }

}
