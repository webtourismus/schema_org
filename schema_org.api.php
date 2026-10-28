<?php

/**
 * @file
 * Hooks provided by the schema_org module.
 */

declare(strict_types=1);

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alters the schema.org graph before it is rendered as JSON-LD.
 *
 * Can be implemented by modules and themes, so each project can add or
 * change data without touching the base module.
 *
 * @param array<string, array<string, mixed>> $graph
 *   The graph nodes, keyed by a machine name: "hotel", "website", "webpage",
 *   and depending on the page "breadcrumb", "room" and "offer". Keys are
 *   dropped when rendering, so new nodes can be added with any key.
 * @param array<string, mixed> $context
 *   - route_match: The current route match.
 *   - node: The node of the current page, or NULL.
 *   - langcode: The content language code.
 *   - base_url: The base URL used for "@id" values, e.g. https://example.com/.
 * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
 *   Add cache tags and contexts of any data used to alter the graph.
 */
function hook_schema_org_graph_alter(array &$graph, array $context, \Drupal\Core\Cache\CacheableMetadata $cacheability): void {
  // Add an award to the hotel.
  $graph['hotel']['award'] = 'Falstaff Hotel Guide 2027';

  // Add an event node on a specific page.
  if ($context['node'] && $context['node']->bundle() === 'event') {
    $graph['event'] = [
      '@type' => 'Event',
      'name' => $context['node']->label(),
      'location' => ['@id' => $context['base_url'] . '#hotel'],
    ];
  }
}

/**
 * @} End of "addtogroup hooks".
 */
