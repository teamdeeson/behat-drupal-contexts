<?php

namespace TeamDeeson\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;
use Drupal\file\Entity\File;
use Drupal\node\Entity\NodeType;
use Behat\Gherkin\Node\TableNode;

/**
 * Defines application features from the specific context.
 */
class AdminContext extends AnonymousContext implements Context, SnippetAcceptingContext {

  /** @var array */
  private $entities = [];

  /**
   * @Given /^I am on the add "([^"]*)" page$/
   *
   * @param string $contentTypeName
   *   The content type's machine name or label.
   * @throws \Exception
   */
  public function iAmOnTheAddPage($contentTypeName) {
    $nodeTypes = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();

    foreach ($nodeTypes as $nodeType) {
      /** @var \Drupal\node\Entity\NodeType $nodeType */
      if ($this->matchesMachineName($nodeType, $contentTypeName) || $this->matchesLabel($nodeType, $contentTypeName)) {
        $this->assertAtPath("/node/add/{$nodeType->id()}");
        return;
      }
    }

    throw new Exception("Unknown content type: {$contentTypeName}");
  }

  /**
   * @param \Drupal\node\Entity\NodeType $nodeType
   *   The node type to check against.
   * @param string $machineName
   *   The machine name to match.
   * @return bool
   *   Whether or not the NodeType matches the machine name.
   */
  private function matchesMachineName(NodeType $nodeType, $machineName) {
    return $nodeType->id() == $machineName;
  }

  /**
   * @param \Drupal\node\Entity\NodeType $nodeType
   *   The node type to check against.
   * @param string $label
   *   The label to match.
   * @return bool
   *   Whether or not the NodeType matches the label.
   */
  private function matchesLabel(NodeType $nodeType, $label) {
    return $nodeType->label() == $label;
  }

  /**
   * @Given /^I am on the edit "([^"]*)" block page$/
   * @param string $blockName
   *   The name of the block we want to edit.
   * @throws \Exception
   */
  public function iAmOnTheEditBlockPage($blockName) {
    $blockQuery = \Drupal::entityTypeManager()->getStorage('block_content')->getQuery();
    $blockQuery->condition('info', $blockName);
    $ids = $blockQuery->execute();

    if (count($ids) == 0) {
      throw new Exception("No block with {$blockName} could be found.");
    }
    elseif (count($ids) > 1) {
      throw new Exception("{$blockName} could not be resolved to a single block.");
    }

    $id = reset($ids);
    $this->assertAtPath("block/$id");
  }

  /**
   * @Then /^I should not see the "([^"]*)" tab$/
   * @param string $label
   *   The label to look for.
   * @throws \Exception
   */
  public function iShouldNotSeeTheTab($label) {
    $currentPage = $this->getMink()->getSession()->getPage();
    $hasTabs = $currentPage->has('css', '.tabs');
    if ($hasTabs) {
      $tabs = $currentPage->find('css', '.tabs');
      if (strpos($label, $tabs->getText()) !== FALSE) {
        throw new Exception("The ${label} tab has been found in the tabs.");
      }
    };
  }

  /**
   * Remove any created entities.
   *
   * @AfterScenario
   */
  public function cleanEntities() {
    foreach ($this->entities as $entity_type => $entities) {
      \Drupal::entityTypeManager()->getStorage($entity_type)->delete($entities);
    }
  }

  /**
   * @Given :entity_type entities:
   */
  public function createEntities($entity_type, TableNode $entityTable) {
    if (empty($this->entities[$entity_type])) {
      $this->entities[$entity_type] = [];
    }

    $entityStorage = \Drupal::entityTypeManager()->getStorage($entity_type);
    $fieldDefinitions = \Drupal::entityManager()->getFieldStorageDefinitions($entity_type);
    foreach ($entityTable->getHash() as $entityHash) {
      $entityHash = (array) $this->prepareEntity($entity_type, (object) $entityHash, $fieldDefinitions);

      $entity = $entityStorage->create($entityHash);
      $entity->save();
      $this->entities[$entity_type][] = $entity;
    }
  }

  /**
   * @param \stdClass $entity
   * @param \Drupal\Core\Field\FieldDefinitionInterface[] $fieldDefinitions
   *
   * @return array
   * @throws \Exception
   */
  private function prepareEntity($entityType, \stdClass $entity, array $fieldDefinitions) {
    $entityTypeManager = \Drupal::entityTypeManager();
    $entityKeys = $entityTypeManager->getStorage($entityType)->getEntityType()->getKeys();

    foreach ($entity as $field => $value) {
      if (!in_array($field, $entityKeys) && key_exists($field, $fieldDefinitions)) {
        $definition = $fieldDefinitions[$field];

        // Load any referenced files.
        if ($definition->getType() === 'image') {
          $fileStorage = $entityTypeManager->getStorage('file');
          $files = $fileStorage->loadByProperties(['uri' => $value]);
          if (empty($files)) {
            throw new \Exception("No file with uri {$value} exists.");
          }
          else {
            $entity->{$field} = end($files);
          }
        }

        // Load any referenced entities.
        if ($definition->getType() === 'entity_reference') {
          $targetType = $definition->getSetting('target_type');
          $entityStorage = \Drupal::entityTypeManager()
            ->getStorage($targetType);
          $labelKey = $entityStorage->getEntityType()->getKey('label');

          $entities = $entityStorage->loadByProperties([$labelKey => $value]);
          if (empty($entities)) {
            throw new \Exception("No {$targetType} with {$labelKey} {$value} exists");
          }
          else {
            $entity->{$field} = end($entities);
          }
        }
      }
    }

    return $entity;
  }

  /**
   * Attempts to click a link in a details block containing giving text. This is
   * for add/edit content forms containing media fields for instance.
   *
   * @Given I click :clickable in the fieldset containing :text
   */
  public function clickLinkInFieldset($clickable, $fieldsetText) {
    $clickableElement = $this->assertClickableInFieldset($clickable, $fieldsetText);
    $clickableElement->click();
  }

  /**
   * @param string $text
   *
   * @return \Behat\Mink\Element\NodeElement[]
   * @throws \Exception
   */
  private function findFieldsetsWithText($text) {
    /** @var \Behat\Mink\Element\NodeElement[] $fieldsets */
    $fieldsets = $this->getSession()->getPage()->findAll('css', 'fieldset, details');

    $matches = [];
    foreach ($fieldsets as $fieldset) {
      if (strpos($fieldset->getText(), $text) !== FALSE) {
        $matches[] = $fieldset;
      }
    }

    if (empty($matches)) {
      throw new \Exception(sprintf('Could not find a fieldset containing %s', $text));
    }

    return $matches;
  }

  /**
   * Attempts to find a link in a details block containing giving text. This is
   * for add/edit content forms containing media fields for instance.

   * @then I (should )see the :clickable link/button in the fieldset containing :text
   * @return ElementNode;
   */
  public function assertClickableInFieldset($clickable, $fieldsetText) {
    $fieldsets = $this->findFieldsetsWithText($fieldsetText);

    foreach ($fieldsets as $fieldset) {
      if ($clickableElement = $fieldset->findLink($clickable)) {
        return $clickableElement;
      }
      elseif ($clickableElement = $fieldset->findButton($clickable)) {
        return $clickableElement;
      }
    }

    throw new \Exception(sprintf('Found a row fieldset "%s", but no "%s" link on the page %s', $fieldsetText, $clickable, $this->getSession()->getCurrentUrl()));
  }

  /**
   * @When I click summary :summaryText
   */
  public function clickDetailsSummary($summaryText) {
    $summaryElements = $this->getSession()->getPage()->findAll('css', 'summary');

    foreach ($summaryElements as $element) {
      if ($element->getText() === $summaryText) {
        $element->click();
        return;
      }
    }

    throw new \Exception("No \"{$summaryText}\" summary found");
  }

  /**
   * @When I look in the :name iframe
   */
  public function switchToIframe($name) {
    $this->getSession()->switchToIFrame($name);
  }

  /**
   * @When I look in the main window
   */
  public function switchToWindow() {
    $this->getSession()->switchToWindow();
  }

}
