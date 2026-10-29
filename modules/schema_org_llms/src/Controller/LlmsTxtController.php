<?php

declare(strict_types=1);

namespace Drupal\schema_org_llms\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\schema_org_llms\LlmsTxtBuilder;

/**
 * Delivers /llms.txt.
 */
class LlmsTxtController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    protected LlmsTxtBuilder $builder,
  ) {}

  /**
   * Returns llms.txt as UTF-8 plain text, cached like a page.
   */
  public function __invoke(): CacheableResponse {
    $cacheability = new CacheableMetadata();
    $response = new CacheableResponse($this->builder->build($cacheability), 200, [
      'Content-Type' => 'text/plain; charset=utf-8',
      // Readable by AI crawlers, but not shown as a search result itself.
      'X-Robots-Tag' => 'noindex',
    ]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

}
