<?php

/**
 * @file
 * Contains \Drupal\views\Plugin\Derivative\DefaultWizardDeriver.
 */

namespace Drupal\views\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DerivativeBase;
use Drupal\views\Views;

/**
 * A derivative class which provides automatic wizards for all base tables.
 *
 * The derivatives store all base table plugin information.
 */
class DefaultWizardDeriver extends DerivativeBase {
  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions(array $base_plugin_definition) {
    $views_data = Views::viewsData();
    $base_tables = array_keys($views_data->fetchBaseTables());
    $this->derivatives = array();
    foreach ($base_tables as $table) {
      $views_info = $views_data->get($table);
      if (empty($views_info['table']['wizard_id'])) {
        $this->derivatives[$table] = array(
          'id' => 'standard',
          'base_table' => $table,
          'title' => $views_info['table']['base']['title'],
          'class' => 'Drupal\views\Plugin\views\wizard\Standard'
        );
      }
    }
    return parent::getDerivativeDefinitions($base_plugin_definition);
  }
}