<?php

/**
 * @file
 * Definition of Drupal\views\ManyToOneHelper.
 */

namespace Drupal\views;

use Drupal\views\Plugin\views\HandlerBase;

/**
 * This many to one helper object is used on both arguments and filters.
 *
 * @todo This requires extensive documentation on how this class is to
 * be used. For now, look at the arguments and filters that use it. Lots
 * of stuff is just pass-through but there are definitely some interesting
 * areas where they interact.
 *
 * Any handler that uses this can have the following possibly additional
 * definition terms:
 * - numeric: If true, treat this field as numeric, using %d instead of %s in
 *            queries.
 *
 */
class ManyToOneHelper {

  function __construct($handler) {
    $this->handler = $handler;
  }

  public static function defineOptions(&$options) {
    $options['reduce_duplicates'] = array('default' => FALSE, 'bool' => TRUE);
  }

  public function buildOptionsForm(&$form, &$form_state) {
    $form['reduce_duplicates'] = array(
      '#type' => 'checkbox',
      '#title' => t('Reduce duplicates'),
      '#description' => t("This filter can cause items that have more than one of the selected options to appear as duplicate results. If this filter causes duplicate results to occur, this checkbox can reduce those duplicates; however, the more terms it has to search for, the less performant the query will be, so use this with caution. Shouldn't be set on single-value fields, as it may cause values to disappear from display, if used on an incompatible field."),
      '#default_value' => !empty($this->handler->options['reduce_duplicates']),
      '#weight' => 4,
    );
  }

  /**
   * Sometimes the handler might want us to use some kind of formula, so give
   * it that option. If it wants us to do this, it must set $helper->formula = TRUE
   * and implement handler->getFormula();
   */
  public function getField() {
    if (!empty($this->formula)) {
      return $this->handler->getFormula();
    }
    else {
      return $this->handler->tableAlias . '.' . $this->handler->realField;
    }
  }

  /**
   * Add a table to the query.
   *
   * This is an advanced concept; not only does it add a new instance of the table,
   * but it follows the relationship path all the way down to the relationship
   * link point and adds *that* as a new relationship and then adds the table to
   * the relationship, if necessary.
   */
  public function addTable($join = NULL, $alias = NULL) {
    // This is used for lookups in the many_to_one table.
    $field = $this->handler->relationship . '_' . $this->handler->table . '.' . $this->handler->field;

    if (empty($join)) {
      $join = $this->getJoin();
    }

    // See if there's a chain between us and the base relationship. If so, we need
    // to create a new relationship to use.
    $relationship = $this->handler->relationship;

    // Determine the primary table to seek
    if (empty($this->handler->query->relationships[$relationship])) {
      $base_table = $this->handler->view->storage->get('base_table');
    }
    else {
      $base_table = $this->handler->query->relationships[$relationship]['base'];
    }

    // Cycle through the joins. This isn't as error-safe as the normal
    // ensurePath logic. Perhaps it should be.
    $r_join = clone $join;
    while ($r_join->leftTable != $base_table) {
      $r_join = HandlerBase::getTableJoin($r_join->leftTable, $base_table);
    }
    // If we found that there are tables in between, add the relationship.
    if ($r_join->table != $join->table) {
      $relationship = $this->handler->query->addRelationship($this->handler->table . '_' . $r_join->table, $r_join, $r_join->table, $this->handler->relationship);
    }

    // And now add our table, using the new relationship if one was used.
    $alias = $this->handler->query->addTable($this->handler->table, $relationship, $join, $alias);

    // Store what values are used by this table chain so that other chains can
    // automatically discard those values.
    if (empty($this->handler->view->many_to_one_tables[$field])) {
      $this->handler->view->many_to_one_tables[$field] = $this->handler->value;
    }
    else {
      $this->handler->view->many_to_one_tables[$field] = array_merge($this->handler->view->many_to_one_tables[$field], $this->handler->value);
    }

    return $alias;
  }

  public function getJoin() {
    return $this->handler->getJoin();
  }

  /**
   * Provide the proper join for summary queries. This is important in part because
   * it will cooperate with other arguments if possible.
   */
  public function summaryJoin() {
    $field = $this->handler->relationship . '_' . $this->handler->table . '.' . $this->handler->field;
    $join = $this->getJoin();

    // shortcuts
    $options = $this->handler->options;
    $view = &$this->handler->view;
    $query = &$this->handler->query;

    if (!empty($options['require_value'])) {
      $join->type = 'INNER';
    }

    if (empty($options['add_table']) || empty($view->many_to_one_tables[$field])) {
      return $query->ensureTable($this->handler->table, $this->handler->relationship, $join);
    }
    else {
      if (!empty($view->many_to_one_tables[$field])) {
        foreach ($view->many_to_one_tables[$field] as $value) {
          $join->extra = array(
            array(
              'field' => $this->handler->realField,
              'operator' => '!=',
              'value' => $value,
              'numeric' => !empty($this->definition['numeric']),
            ),
          );
        }
      }
      return $this->addTable($join);
    }
  }

  /**
   * Override ensureMyTable so we can control how this joins in.
   * The operator actually has influence over joining.
   */
  public function ensureMyTable() {
    if (!isset($this->handler->tableAlias)) {
      // Case 1: Operator is an 'or' and we're not reducing duplicates.
      // We hence get the absolute simplest:
      $field = $this->handler->relationship . '_' . $this->handler->table . '.' . $this->handler->field;
      if ($this->handler->operator == 'or' && empty($this->handler->options['reduce_duplicates'])) {
        if (empty($this->handler->options['add_table']) && empty($this->handler->view->many_to_one_tables[$field])) {
          // query optimization, INNER joins are slightly faster, so use them
          // when we know we can.
          $join = $this->getJoin();
          if (isset($join)) {
            $join->type = 'INNER';
          }
          $this->handler->tableAlias = $this->handler->query->ensureTable($this->handler->table, $this->handler->relationship, $join);
          $this->handler->view->many_to_one_tables[$field] = $this->handler->value;
        }
        else {
          $join = $this->getJoin();
          $join->type = 'LEFT';
          if (!empty($this->handler->view->many_to_one_tables[$field])) {
            foreach ($this->handler->view->many_to_one_tables[$field] as $value) {
              $join->extra = array(
                array(
                  'field' => $this->handler->realField,
                  'operator' => '!=',
                  'value' => $value,
                  'numeric' => !empty($this->handler->definition['numeric']),
                ),
              );
            }
          }

          $this->handler->tableAlias = $this->addTable($join);
        }

        return $this->handler->tableAlias;
      }

      // Case 2: it's an 'and' or an 'or'.
      // We do one join per selected value.
      if ($this->handler->operator != 'not') {
        // Clone the join for each table:
        $this->handler->tableAliases = array();
        foreach ($this->handler->value as $value) {
          $join = $this->getJoin();
          if ($this->handler->operator == 'and') {
            $join->type = 'INNER';
          }
          $join->extra = array(
            array(
              'field' => $this->handler->realField,
              'value' => $value,
              'numeric' => !empty($this->handler->definition['numeric']),
            ),
          );

          // The table alias needs to be unique to this value across the
          // multiple times the filter or argument is called by the view.
          if (!isset($this->handler->view->many_to_one_aliases[$field][$value])) {
            if (!isset($this->handler->view->many_to_one_count[$this->handler->table])) {
              $this->handler->view->many_to_one_count[$this->handler->table] = 0;
            }
            $this->handler->view->many_to_one_aliases[$field][$value] = $this->handler->table . '_value_' . ($this->handler->view->many_to_one_count[$this->handler->table]++);
          }
          $alias = $this->handler->tableAliases[$value] = $this->addTable($join, $this->handler->view->many_to_one_aliases[$field][$value]);

          // and set table_alias to the first of these.
          if (empty($this->handler->tableAlias)) {
            $this->handler->tableAlias = $alias;
          }
        }
      }
      // Case 3: it's a 'not'.
      // We just do one join. We'll add a where clause during
      // the query phase to ensure that $table.$field IS NULL.
      else {
        $join = $this->getJoin();
        $join->type = 'LEFT';
        $join->extra = array();
        $join->extra_type = 'OR';
        foreach ($this->handler->value as $value) {
          $join->extra[] = array(
            'field' => $this->handler->realField,
            'value' => $value,
            'numeric' => !empty($this->handler->definition['numeric']),
          );
        }

        $this->handler->tableAlias = $this->addTable($join);
      }
    }
    return $this->handler->tableAlias;
  }

  /**
   * Provides a unique placeholders for handlers.
   */
  protected function placeholder() {
    return $this->handler->query->placeholder($this->handler->options['table'] . '_' . $this->handler->options['field']);
  }

  public function addFilter() {
    if (empty($this->handler->value)) {
      return;
    }
    $this->handler->ensureMyTable();

    // Shorten some variables:
    $field = $this->getField();
    $options = $this->handler->options;
    $operator = $this->handler->operator;
    $formula = !empty($this->formula);
    $value = $this->handler->value;
    if (empty($options['group'])) {
      $options['group'] = 0;
    }

    // add_condition determines whether a single expression is enough(FALSE) or the
    // conditions should be added via an db_or()/db_and() (TRUE).
    $add_condition = TRUE;
    if ($operator == 'not') {
      $value = NULL;
      $operator = 'IS NULL';
      $add_condition = FALSE;
    }
    elseif ($operator == 'or' && empty($options['reduce_duplicates'])) {
      if (count($value) > 1) {
        $operator = 'IN';
      }
      else {
        $value = is_array($value) ? array_pop($value) : $value;
        $operator = '=';
      }
      $add_condition = FALSE;
    }

    if (!$add_condition) {
      if ($formula) {
        $placeholder = $this->placeholder();
        if ($operator == 'IN') {
          $operator = "$operator IN($placeholder)";
        }
        else {
          $operator = "$operator $placeholder";
        }
        $placeholders = array(
          $placeholder => $value,
        ) + $this->placeholders;
        $this->handler->query->addWhereExpression($options['group'], "$field $operator", $placeholders);
      }
      else {
        $placeholder = $this->placeholder();
        if (count($this->handler->value) > 1) {
          $this->handler->query->addWhereExpression(0, "$field $operator($placeholder)", array($placeholder => $value));
        }
        else {
          $this->handler->query->addWhereExpression(0, "$field $operator $placeholder", array($placeholder => $value));
        }
      }
    }

    if ($add_condition) {
      $field = $this->handler->realField;
      $clause = $operator == 'or' ? db_or() : db_and();
      foreach ($this->handler->tableAliases as $value => $alias) {
        $clause->condition("$alias.$field", $value);
      }

      // implode on either AND or OR.
      $this->handler->query->addWhere($options['group'], $clause);
    }
  }

}